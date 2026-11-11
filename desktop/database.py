"""Local SQLite store. Everything is queued locally and synced by the API worker."""
from __future__ import annotations

import sqlite3
import threading
from datetime import date
from typing import Any, Iterable

from config import DB_PATH, day_bounds_utc, iso_utc, utc_now


class Database:
    def __init__(self, path=DB_PATH):
        self._lock = threading.RLock()
        self.connection = sqlite3.connect(path, check_same_thread=False, timeout=20)
        self.connection.row_factory = sqlite3.Row
        self.connection.execute("PRAGMA journal_mode=WAL")
        self.connection.execute("PRAGMA synchronous=NORMAL")
        self.connection.execute("PRAGMA busy_timeout=20000")
        self._create_schema()

    def _create_schema(self) -> None:
        with self._lock, self.connection:
            self.connection.executescript(
                """
                CREATE TABLE IF NOT EXISTS settings (
                    key TEXT PRIMARY KEY,
                    value TEXT
                );

                CREATE TABLE IF NOT EXISTS sessions (
                    uuid TEXT PRIMARY KEY,
                    started_at TEXT NOT NULL,
                    ended_at TEXT,
                    status TEXT NOT NULL DEFAULT 'running',
                    active_seconds INTEGER NOT NULL DEFAULT 0,
                    idle_seconds INTEGER NOT NULL DEFAULT 0,
                    break_seconds INTEGER NOT NULL DEFAULT 0,
                    device_name TEXT,
                    remote_created INTEGER NOT NULL DEFAULT 0,
                    stop_synced INTEGER NOT NULL DEFAULT 0,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                );

                CREATE TABLE IF NOT EXISTS activity_minutes (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    session_uuid TEXT NOT NULL,
                    minute_start TEXT NOT NULL,
                    keystrokes INTEGER NOT NULL DEFAULT 0,
                    clicks INTEGER NOT NULL DEFAULT 0,
                    active_seconds INTEGER NOT NULL DEFAULT 0,
                    idle_seconds INTEGER NOT NULL DEFAULT 0,
                    is_idle INTEGER NOT NULL DEFAULT 0,
                    synced INTEGER NOT NULL DEFAULT 0,
                    updated_at TEXT NOT NULL,
                    UNIQUE(session_uuid, minute_start)
                );

                CREATE TABLE IF NOT EXISTS breaks (
                    uuid TEXT PRIMARY KEY,
                    session_uuid TEXT NOT NULL,
                    type TEXT NOT NULL,
                    started_at TEXT NOT NULL,
                    ended_at TEXT,
                    duration_seconds INTEGER NOT NULL DEFAULT 0,
                    completed INTEGER NOT NULL DEFAULT 0,
                    synced INTEGER NOT NULL DEFAULT 0,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                );

                CREATE TABLE IF NOT EXISTS screenshots (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    session_uuid TEXT NOT NULL,
                    path TEXT NOT NULL,
                    taken_at TEXT NOT NULL,
                    synced INTEGER NOT NULL DEFAULT 0,
                    created_at TEXT NOT NULL
                );

                CREATE INDEX IF NOT EXISTS idx_activity_session ON activity_minutes(session_uuid, minute_start);
                CREATE INDEX IF NOT EXISTS idx_break_session ON breaks(session_uuid, started_at);
                CREATE INDEX IF NOT EXISTS idx_screenshots_session ON screenshots(session_uuid, taken_at);
                """
            )

    # ------------------------------------------------------------------ settings
    def get_setting(self, key: str, default: str | None = None) -> str | None:
        with self._lock:
            row = self.connection.execute("SELECT value FROM settings WHERE key = ?", (key,)).fetchone()
        return row["value"] if row else default

    def set_setting(self, key: str, value: str | None) -> None:
        with self._lock, self.connection:
            if value is None:
                self.connection.execute("DELETE FROM settings WHERE key = ?", (key,))
            else:
                self.connection.execute(
                    "INSERT INTO settings(key, value) VALUES(?, ?) "
                    "ON CONFLICT(key) DO UPDATE SET value = excluded.value",
                    (key, value),
                )

    # ------------------------------------------------------------------ sessions
    def create_session(self, uuid: str, started_at: str, device_name: str) -> None:
        now = iso_utc(utc_now())
        with self._lock, self.connection:
            self.connection.execute(
                """
                INSERT INTO sessions(uuid, started_at, status, device_name, created_at, updated_at)
                VALUES(?, ?, 'running', ?, ?, ?)
                """,
                (uuid, started_at, device_name, now, now),
            )

    def get_session(self, uuid: str) -> sqlite3.Row | None:
        with self._lock:
            return self.connection.execute("SELECT * FROM sessions WHERE uuid = ?", (uuid,)).fetchone()

    def get_open_session(self) -> sqlite3.Row | None:
        with self._lock:
            return self.connection.execute(
                "SELECT * FROM sessions WHERE status IN ('running', 'paused') ORDER BY started_at DESC LIMIT 1"
            ).fetchone()

    def update_session(self, uuid: str, **fields: Any) -> None:
        allowed = {
            "ended_at",
            "status",
            "active_seconds",
            "idle_seconds",
            "break_seconds",
            "remote_created",
            "stop_synced",
        }
        updates = {key: value for key, value in fields.items() if key in allowed}
        if not updates:
            return
        updates["updated_at"] = iso_utc(utc_now())
        assignment = ", ".join(f"{key} = ?" for key in updates)
        values = list(updates.values()) + [uuid]
        with self._lock, self.connection:
            self.connection.execute(f"UPDATE sessions SET {assignment} WHERE uuid = ?", values)

    def unsynced_sessions(self, limit: int = 12) -> list[sqlite3.Row]:
        with self._lock:
            return self.connection.execute(
                """
                SELECT DISTINCT s.*
                FROM sessions s
                WHERE s.remote_created = 0
                   OR (s.status = 'stopped' AND s.stop_synced = 0)
                   OR EXISTS (
                        SELECT 1 FROM activity_minutes a
                        WHERE a.session_uuid = s.uuid AND a.synced = 0
                   )
                   OR EXISTS (
                        SELECT 1 FROM breaks b
                        WHERE b.session_uuid = s.uuid AND b.synced = 0
                   )
                   OR EXISTS (
                        SELECT 1 FROM screenshots sc
                        WHERE sc.session_uuid = s.uuid AND sc.synced = 0
                   )
                ORDER BY s.started_at ASC
                LIMIT ?
                """,
                (limit,),
            ).fetchall()

    def today_summary(self, local_day: date) -> dict[str, int]:
        start, end = day_bounds_utc(local_day)
        with self._lock:
            row = self.connection.execute(
                """
                SELECT
                    COALESCE(SUM(active_seconds), 0) AS active_seconds,
                    COALESCE(SUM(idle_seconds), 0) AS idle_seconds,
                    COALESCE(SUM(break_seconds), 0) AS break_seconds
                FROM sessions
                WHERE started_at >= ? AND started_at < ?
                """,
                (iso_utc(start), iso_utc(end)),
            ).fetchone()
        return dict(row) if row else {"active_seconds": 0, "idle_seconds": 0, "break_seconds": 0}

    def latest_session(self) -> sqlite3.Row | None:
        with self._lock:
            return self.connection.execute(
                "SELECT * FROM sessions ORDER BY started_at DESC LIMIT 1"
            ).fetchone()

    # ------------------------------------------------------------------ activity
    def upsert_activity(
        self,
        session_uuid: str,
        minute_start: str,
        keystrokes: int,
        clicks: int,
        active_seconds: int,
        idle_seconds: int,
        is_idle: bool,
    ) -> None:
        now = iso_utc(utc_now())
        with self._lock, self.connection:
            self.connection.execute(
                """
                INSERT INTO activity_minutes(
                    session_uuid, minute_start, keystrokes, clicks, active_seconds,
                    idle_seconds, is_idle, synced, updated_at
                ) VALUES(?, ?, ?, ?, ?, ?, ?, 0, ?)
                ON CONFLICT(session_uuid, minute_start) DO UPDATE SET
                    keystrokes = excluded.keystrokes,
                    clicks = excluded.clicks,
                    active_seconds = excluded.active_seconds,
                    idle_seconds = excluded.idle_seconds,
                    is_idle = excluded.is_idle,
                    synced = 0,
                    updated_at = excluded.updated_at
                """,
                (
                    session_uuid,
                    minute_start,
                    max(0, int(keystrokes)),
                    max(0, int(clicks)),
                    min(60, max(0, int(active_seconds))),
                    min(60, max(0, int(idle_seconds))),
                    1 if is_idle else 0,
                    now,
                ),
            )

    def pending_activity(self, session_uuid: str, limit: int = 3000) -> list[sqlite3.Row]:
        with self._lock:
            return self.connection.execute(
                "SELECT * FROM activity_minutes WHERE session_uuid = ? AND synced = 0 "
                "ORDER BY minute_start ASC LIMIT ?",
                (session_uuid, limit),
            ).fetchall()

    def mark_activity_synced(self, ids: Iterable[int]) -> None:
        ids = list(ids)
        if not ids:
            return
        placeholders = ",".join("?" for _ in ids)
        with self._lock, self.connection:
            self.connection.execute(
                f"UPDATE activity_minutes SET synced = 1 WHERE id IN ({placeholders})",
                ids,
            )

    def recent_activity(self, session_uuid: str, limit: int = 18) -> list[sqlite3.Row]:
        with self._lock:
            return self.connection.execute(
                "SELECT * FROM activity_minutes WHERE session_uuid = ? "
                "ORDER BY minute_start DESC LIMIT ?",
                (session_uuid, limit),
            ).fetchall()

    # ------------------------------------------------------------------ breaks
    def add_break(self, uuid: str, session_uuid: str, break_type: str, started_at: str) -> None:
        now = iso_utc(utc_now())
        with self._lock, self.connection:
            self.connection.execute(
                """
                INSERT INTO breaks(uuid, session_uuid, type, started_at, completed, synced, created_at, updated_at)
                VALUES(?, ?, ?, ?, 0, 0, ?, ?)
                """,
                (uuid, session_uuid, break_type, started_at, now, now),
            )

    def finish_break(self, uuid: str, ended_at: str, duration_seconds: int, completed: bool = True) -> None:
        now = iso_utc(utc_now())
        with self._lock, self.connection:
            self.connection.execute(
                "UPDATE breaks SET ended_at = ?, duration_seconds = ?, completed = ?, synced = 0, updated_at = ? "
                "WHERE uuid = ?",
                (ended_at, max(0, int(duration_seconds)), 1 if completed else 0, now, uuid),
            )

    def pending_breaks(self, session_uuid: str) -> list[sqlite3.Row]:
        with self._lock:
            return self.connection.execute(
                "SELECT * FROM breaks WHERE session_uuid = ? AND synced = 0 ORDER BY started_at ASC",
                (session_uuid,),
            ).fetchall()

    def mark_breaks_synced(self, uuids: Iterable[str]) -> None:
        uuids = list(uuids)
        if not uuids:
            return
        placeholders = ",".join("?" for _ in uuids)
        with self._lock, self.connection:
            self.connection.execute(
                f"UPDATE breaks SET synced = 1 WHERE uuid IN ({placeholders})",
                uuids,
            )

    def breaks_for_session(self, session_uuid: str) -> list[sqlite3.Row]:
        with self._lock:
            return self.connection.execute(
                "SELECT * FROM breaks WHERE session_uuid = ? ORDER BY started_at ASC",
                (session_uuid,),
            ).fetchall()

    # ------------------------------------------------------------------ screenshots
    def add_screenshot(self, session_uuid: str, path: str, taken_at: str) -> int:
        with self._lock, self.connection:
            cursor = self.connection.execute(
                "INSERT INTO screenshots(session_uuid, path, taken_at, synced, created_at) VALUES(?, ?, ?, 0, ?)",
                (session_uuid, path, taken_at, iso_utc(utc_now())),
            )
            return int(cursor.lastrowid)

    def pending_screenshots(self, session_uuid: str | None = None, limit: int = 10) -> list[sqlite3.Row]:
        with self._lock:
            if session_uuid:
                return self.connection.execute(
                    "SELECT * FROM screenshots WHERE session_uuid = ? AND synced = 0 "
                    "ORDER BY taken_at ASC LIMIT ?",
                    (session_uuid, limit),
                ).fetchall()
            return self.connection.execute(
                "SELECT * FROM screenshots WHERE synced = 0 ORDER BY taken_at ASC LIMIT ?",
                (limit,),
            ).fetchall()

    def mark_screenshot_synced(self, screenshot_id: int) -> None:
        with self._lock, self.connection:
            self.connection.execute("UPDATE screenshots SET synced = 1 WHERE id = ?", (screenshot_id,))

    def screenshot_count(self, session_uuid: str) -> int:
        with self._lock:
            row = self.connection.execute(
                "SELECT COUNT(*) AS total FROM screenshots WHERE session_uuid = ?",
                (session_uuid,),
            ).fetchone()
        return int(row["total"]) if row else 0

    def close(self) -> None:
        with self._lock:
            self.connection.close()
