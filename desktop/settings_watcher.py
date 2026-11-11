"""Live settings watcher.

The admin panel can change the global office settings or a staff member's
custom office time at any moment. This worker polls a lightweight endpoint on
the server and applies the new schedule to the running app as soon as the
version changes, so a change made in the browser shows up in the tracker in a
couple of seconds.
"""
from __future__ import annotations

import logging
import threading
from datetime import datetime

from api_client import ApiClient, ApiError
from config import SETTINGS_POLL_SECONDS, apply_schedule_payload, utc_now
from database import Database

log = logging.getLogger(__name__)

VERSION_KEY = "settings_version"


class SettingsWatcher:
    """Polls the server for setting changes and applies them immediately."""

    def __init__(self, db: Database, on_change=None):
        self.db = db
        self.on_change = on_change
        self.version: str = db.get_setting(VERSION_KEY) or ""
        self.last_error: str | None = None
        self.last_check_at: datetime | None = None
        self.last_applied_at: datetime | None = None
        self._stop = threading.Event()
        self._wake = threading.Event()
        self._lock = threading.Lock()
        self._thread: threading.Thread | None = None

    # ------------------------------------------------------------------ lifecycle
    def start(self) -> None:
        if self._thread and self._thread.is_alive():
            return
        self._stop.clear()
        self._thread = threading.Thread(target=self._loop, name="settings-watch", daemon=True)
        self._thread.start()

    def stop(self) -> None:
        self._stop.set()
        self._wake.set()
        if self._thread and self._thread.is_alive():
            self._thread.join(timeout=1)
        self._thread = None

    def trigger(self) -> None:
        """Check immediately instead of waiting for the next poll."""
        self._wake.set()

    @property
    def is_running(self) -> bool:
        return bool(self._thread and self._thread.is_alive())

    # ------------------------------------------------------------------ polling
    def _loop(self) -> None:
        while not self._stop.is_set():
            self._wake.wait(SETTINGS_POLL_SECONDS)
            self._wake.clear()
            if self._stop.is_set():
                break
            try:
                self.check_now()
            except Exception:  # pragma: no cover - defensive
                log.exception("Settings watcher error")

    def check_now(self) -> bool:
        """Fetch the current settings version and apply it when it changed."""
        token = self.db.get_setting("api_token")
        api_url = self.db.get_setting("api_url")
        if not token or not api_url:
            return False

        with self._lock:
            api = ApiClient(api_url, token, timeout=10)
            try:
                data = api.settings(since=self.version or None)
            except ApiError as exc:
                self.last_error = str(exc)
                return False

            self.last_error = None
            self.last_check_at = utc_now()

            version = str(data.get("version") or "")
            schedule = data.get("schedule")

            if not data.get("changed") or not isinstance(schedule, dict):
                return False

            # The version changed on the server, so the interface has to be
            # refreshed even when the applied values happen to be identical.
            version_changed = version != self.version

            apply_schedule_payload(schedule, self.db)
            self.last_applied_at = utc_now()
            if version:
                self.version = version
                self.db.set_setting(VERSION_KEY, version)

        if version_changed and self.on_change:
            try:
                self.on_change(schedule)
            except Exception:  # pragma: no cover - defensive
                log.exception("Settings change callback failed")

        return version_changed

    def reset(self) -> None:
        """Forget the known version so the next check pulls a fresh copy."""
        self.version = ""
        self.db.set_setting(VERSION_KEY, None)
