"""Background API synchroniser. The desktop keeps working when the server is offline."""
from __future__ import annotations

import logging
import threading
from datetime import datetime
from pathlib import Path

from api_client import ApiClient, ApiError
from config import SYNC_INTERVAL_SECONDS, apply_schedule_payload, minute_iso, utc_now
from database import Database

log = logging.getLogger(__name__)


class SyncService:
    def __init__(self, db: Database, on_schedule_change=None):
        self.db = db
        self.on_schedule_change = on_schedule_change
        self._stop = threading.Event()
        self._wake = threading.Event()
        self._lock = threading.Lock()
        self._thread: threading.Thread | None = None
        self._status_lock = threading.Lock()
        self.online = False
        self.last_sync_at: datetime | None = None
        self.last_error: str | None = None
        self.last_success_message = "Waiting for first sync"

    def start(self) -> None:
        if self._thread and self._thread.is_alive():
            return
        self._thread = threading.Thread(target=self._loop, name="api-sync", daemon=True)
        self._thread.start()

    def stop(self) -> None:
        self._stop.set()
        self._wake.set()
        if self._thread and self._thread.is_alive():
            self._thread.join(timeout=3)

    def trigger(self) -> None:
        self._wake.set()

    def status(self) -> dict:
        with self._status_lock:
            return {
                "online": self.online,
                "last_sync_at": self.last_sync_at,
                "last_error": self.last_error,
                "message": self.last_success_message,
            }

    def _loop(self) -> None:
        while not self._stop.is_set():
            self._wake.wait(SYNC_INTERVAL_SECONDS)
            self._wake.clear()
            if self._stop.is_set():
                break
            self.sync_once()

    def sync_once(self) -> None:
        if not self._lock.acquire(blocking=False):
            return
        try:
            token = self.db.get_setting("api_token")
            api_url = self.db.get_setting("api_url")
            if not token or not api_url:
                self._set_status(False, "Sign in to connect the office server.")
                return

            api = ApiClient(api_url, token)
            sessions = self.db.unsynced_sessions()
            if not sessions:
                # Heartbeat: keeps the admin dashboard's online indicator fresh
                # during idle time and namaz breaks when there is nothing to upload.
                heartbeat = api.me()
                if apply_schedule_payload(heartbeat.get("schedule"), self.db):
                    self._notify_schedule_change(heartbeat.get("schedule"))
                self._set_status(True, "Connected · everything is synced.")
                return

            synced_something = False
            for row in sessions:
                synced_something = self._sync_row(api, row) or synced_something

            if synced_something:
                self._set_status(True, "Data synced with the office server.")
            else:
                self._set_status(True, "Connected.")
        except ApiError as exc:
            self._set_status(False, str(exc), error=True)
        except Exception as exc:  # pragma: no cover
            log.exception("Unexpected sync error")
            self._set_status(False, f"Sync error: {exc}", error=True)
        finally:
            self._lock.release()

    def _sync_row(self, api: ApiClient, row) -> bool:
        session_uuid = row["uuid"]
        remote_created = bool(row["remote_created"])

        if not remote_created:
            start_response = api.start_session(session_uuid, row["started_at"], row["device_name"] or "Windows PC")
            if apply_schedule_payload(start_response.get("schedule"), self.db):
                self._notify_schedule_change(start_response.get("schedule"))
            self.db.update_session(session_uuid, remote_created=1)
            row = self.db.get_session(session_uuid)
            if not row:
                return False

        activity_rows = self.db.pending_activity(session_uuid)
        if row["status"] == "running":
            current = minute_iso()
            activity_rows = [item for item in activity_rows if item["minute_start"] < current]

        break_rows = self.db.pending_breaks(session_uuid)
        activity_payload = [
            {
                "minute_start": item["minute_start"],
                "keystrokes": item["keystrokes"],
                "clicks": item["clicks"],
                "active_seconds": item["active_seconds"],
                "idle_seconds": item["idle_seconds"],
                "is_idle": bool(item["is_idle"]),
            }
            for item in activity_rows
        ]
        break_payload = [
            {
                "uuid": item["uuid"],
                "type": item["type"],
                "started_at": item["started_at"],
                "ended_at": item["ended_at"],
                "duration_seconds": item["duration_seconds"],
                "completed": bool(item["completed"]),
            }
            for item in break_rows
        ]

        if activity_payload or break_payload or row["status"] == "stopped":
            api.sync_session(session_uuid, row["status"], activity_payload, break_payload)
            self.db.mark_activity_synced([item["id"] for item in activity_rows])
            self.db.mark_breaks_synced([item["uuid"] for item in break_rows])

        for shot in self.db.pending_screenshots(session_uuid):
            if not Path(shot["path"]).exists():
                self.db.mark_screenshot_synced(int(shot["id"]))
                continue
            api.upload_screenshot(session_uuid, shot["taken_at"], shot["path"])
            self.db.mark_screenshot_synced(int(shot["id"]))

        if row["status"] == "stopped" and not row["stop_synced"]:
            api.stop_session(session_uuid, row["ended_at"] or row["started_at"])
            self.db.update_session(session_uuid, stop_synced=1)

        return True

    def _notify_schedule_change(self, schedule: dict | None) -> None:
        """Tell the interface that the office settings changed."""
        if not self.on_schedule_change:
            return
        try:
            self.on_schedule_change(schedule)
        except Exception:  # pragma: no cover - defensive
            log.exception("Schedule change notification failed")

    def _set_status(self, online: bool, message: str, error: bool = False) -> None:
        with self._status_lock:
            self.online = online
            self.last_success_message = message
            self.last_error = message if error else None
            if online:
                self.last_sync_at = utc_now()
