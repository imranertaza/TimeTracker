"""Activity tracking engine: global input counts, idle split, breaks and screenshots."""
from __future__ import annotations

import ctypes
import logging
import math
import random
import threading
import time
import uuid as uuid_module
from collections import deque
from datetime import datetime, timedelta, timezone
from pathlib import Path

from config import (
    SCREENSHOT_DIR,
    SCREENSHOT_JPEG_QUALITY,
    SCREENSHOT_MAX_WIDTH,
    dhaka_now,
    format_duration,
    is_within_work_window,
    iso_utc,
    minute_iso,
    schedule_status,
    to_local,
    utc_now,
)
from database import Database

import config

log = logging.getLogger(__name__)

try:  # pynput is an optional runtime dependency; the rest still works without it.
    from pynput import keyboard, mouse

    PYNPUT_AVAILABLE = True
except Exception as exc:  # pragma: no cover
    keyboard = None
    mouse = None
    PYNPUT_AVAILABLE = False
    log.warning("pynput unavailable: %s", exc)

try:
    from PIL import ImageGrab

    PIL_AVAILABLE = True
except Exception as exc:  # pragma: no cover
    ImageGrab = None
    PIL_AVAILABLE = False
    log.warning("Pillow unavailable: %s", exc)


class WindowsIdle:
    """Read the operating system's last-input timer on Windows."""

    def __init__(self) -> None:
        self.available = False
        if not hasattr(ctypes, "windll"):
            return

        class LASTINPUTINFO(ctypes.Structure):
            _fields_ = [("cbSize", ctypes.c_uint), ("dwTime", ctypes.c_uint)]

        self._struct = LASTINPUTINFO
        self._info = LASTINPUTINFO()
        self._info.cbSize = ctypes.sizeof(LASTINPUTINFO)
        self._user32 = ctypes.windll.user32
        self._kernel32 = ctypes.windll.kernel32
        self.available = bool(self._user32.GetLastInputInfo(ctypes.byref(self._info)))

    def seconds(self) -> float | None:
        if not self.available:
            return None
        if not self._user32.GetLastInputInfo(ctypes.byref(self._info)):
            return None
        tick = self._kernel32.GetTickCount() & 0xFFFFFFFF
        elapsed = (tick - self._info.dwTime) & 0xFFFFFFFF
        return elapsed / 1000.0


class TrackingEngine:
    def __init__(self, db: Database):
        self.db = db
        self._lock = threading.RLock()
        self._messages: deque[str] = deque(maxlen=80)

        self.state = "idle"  # idle | running | on_break
        self.session_uuid: str | None = None
        self.started_at: datetime | None = None
        self.ended_at: datetime | None = None

        self.active_seconds = 0
        self.idle_seconds = 0
        self.break_seconds = 0

        self._current_minute: str | None = None
        self._minutes: dict[str, dict[str, int]] = {}
        self._last_tick = time.monotonic()
        self._last_persist = 0.0
        self._partial_second = 0.0

        self._last_input = utc_now()
        self._win_idle = WindowsIdle()

        self.break_uuid: str | None = None
        self.break_type: str | None = None
        self.break_started_at: datetime | None = None
        self.break_ends_at: datetime | None = None
        self.break_planned_seconds = 0

        self.next_screenshot_at: datetime | None = None
        self.last_screenshot_at: datetime | None = None
        self.screenshot_count = 0

        self._keyboard_listener = None
        self._mouse_listener = None
        self._running_listeners = False

    # ------------------------------------------------------------------ messages
    def emit(self, message: str) -> None:
        with self._lock:
            self._messages.append(f"{dhaka_now().strftime('%I:%M:%S %p').lstrip('0')}  {message}")
        log.info(message)

    def pop_messages(self) -> list[str]:
        with self._lock:
            messages = list(self._messages)
            self._messages.clear()
        return messages

    # ------------------------------------------------------------------ listeners
    def start_listeners(self) -> None:
        if not PYNPUT_AVAILABLE or self._running_listeners:
            return
        try:
            self._keyboard_listener = keyboard.Listener(on_press=self._on_key_press)
            self._mouse_listener = mouse.Listener(on_click=self._on_click)
            self._keyboard_listener.daemon = True
            self._mouse_listener.daemon = True
            self._keyboard_listener.start()
            self._mouse_listener.start()
            self._running_listeners = True
            self.emit("Keyboard and mouse monitoring started (counts only, no content).")
        except Exception as exc:  # pragma: no cover
            self.emit(f"Input monitoring could not start: {exc}")

    def stop_listeners(self) -> None:
        for listener in (self._keyboard_listener, self._mouse_listener):
            try:
                if listener:
                    listener.stop()
            except Exception:
                pass
        self._keyboard_listener = None
        self._mouse_listener = None
        self._running_listeners = False

    def _on_key_press(self, _key) -> None:
        self.record_key()

    def _on_click(self, _x, _y, _button, _pressed) -> None:
        self.record_click()

    def record_key(self) -> None:
        self._record_event("keystrokes")

    def record_click(self) -> None:
        self._record_event("clicks")

    def _record_event(self, field: str) -> None:
        now = utc_now()
        with self._lock:
            self._last_input = now
            if self.state != "running" or not self.session_uuid:
                return
            key = minute_iso(now)
            data = self._minutes.setdefault(key, self._blank_minute())
            data[field] += 1

    # ------------------------------------------------------------------ session
    def start_session(self) -> bool:
        with self._lock:
            if self.state in ("running", "on_break"):
                return False
            if not is_within_work_window():
                self.emit(f"Start blocked. {schedule_status().label}.")
                return False

            open_row = self.db.get_open_session()
            if open_row:
                self._stop_open_row(open_row)

            now = utc_now()
            self.session_uuid = str(uuid_module.uuid4())
            self.started_at = now
            self.ended_at = None
            self.state = "running"
            self.active_seconds = 0
            self.idle_seconds = 0
            self.break_seconds = 0
            self._minutes.clear()
            self._current_minute = minute_iso(now)
            self._minutes[self._current_minute] = self._blank_minute()
            self._last_tick = time.monotonic()
            self._last_persist = 0.0
            self._partial_second = 0.0
            self._last_input = now
            self.screenshot_count = 0
            self.break_uuid = None
            self.break_type = None
            self.break_started_at = None
            self.break_ends_at = None
            self._schedule_next_screenshot(now, skip_current=False)
            self.db.create_session(self.session_uuid, iso_utc(now) or "", self._device_name())
            self.emit("Time tracking started. Dhaka office window is open.")
            return True

    def stop_session(self, reason: str = "Stopped by staff") -> None:
        with self._lock:
            if self.state not in ("running", "on_break") or not self.session_uuid:
                return
            now = utc_now()
            if self.state == "on_break":
                self._finish_break_locked(now, auto=True)

            self._persist_current_locked(now, force=True)
            self.ended_at = now
            self.state = "idle"
            self.db.update_session(
                self.session_uuid,
                status="stopped",
                ended_at=iso_utc(now),
                active_seconds=self.active_seconds,
                idle_seconds=self.idle_seconds,
                break_seconds=self.break_seconds,
                stop_synced=0,
            )
            self.emit(f"Tracking stopped. Active {format_duration(self.active_seconds)}, idle {format_duration(self.idle_seconds)}.")
            self.session_uuid = None
            self.started_at = None
            self.next_screenshot_at = None
            self._minutes.clear()
            self._current_minute = None

    def _stop_open_row(self, row) -> None:
        now = iso_utc(utc_now())
        self.db.update_session(
            row["uuid"],
            status="stopped",
            ended_at=now,
            stop_synced=0,
        )

    def resume_if_possible(self) -> bool:
        """Resume today's open session after a crash/restart when still in office hours."""
        row = self.db.get_open_session()
        if not row:
            return False
        if not is_within_work_window():
            self._stop_open_row(row)
            self.emit("A previous open session was closed because office hours ended.")
            return False

        from config import parse_iso

        started = parse_iso(row["started_at"])
        if not started or to_local(started).date() != dhaka_now().date():
            self._stop_open_row(row)
            self.emit("A previous day's open session was closed.")
            return False

        with self._lock:
            self.session_uuid = row["uuid"]
            self.started_at = started
            self.active_seconds = int(row["active_seconds"] or 0)
            self.idle_seconds = int(row["idle_seconds"] or 0)
            self.break_seconds = int(row["break_seconds"] or 0)
            self.screenshot_count = self.db.screenshot_count(row["uuid"])
            self.state = "running"
            self._last_tick = time.monotonic()
            self._last_persist = 0.0
            self._partial_second = 0.0
            self._last_input = utc_now()
            self._current_minute = minute_iso()
            self._minutes[self._current_minute] = self._blank_minute()
            self._schedule_next_screenshot(utc_now(), skip_current=False)
            self.emit("Recovered and resumed today's open tracking session.")
            return True

    # ------------------------------------------------------------------ breaks
    def start_break(self, break_type: str) -> bool:
        with self._lock:
            if self.state != "running" or not self.session_uuid:
                self.emit("Start a work session before taking a namaz break.")
                return False
            break_config = config.BREAK_TYPES.get(break_type)
            if not break_config:
                return False

            now = utc_now()
            self._persist_current_locked(now, force=True)
            self.break_uuid = str(uuid_module.uuid4())
            self.break_type = break_type
            self.break_started_at = now
            self.break_planned_seconds = int(break_config["minutes"]) * 60
            self.break_ends_at = now.replace(microsecond=0) + timedelta(seconds=self.break_planned_seconds)
            self.state = "on_break"
            self.db.add_break(self.break_uuid, self.session_uuid, break_type, iso_utc(now) or "")
            self.db.update_session(self.session_uuid, status="paused")
            self.emit(f"{break_config['label']} break started ({break_config['minutes']} minutes). Tracking is paused.")
            return True

    def end_break(self) -> None:
        with self._lock:
            if self.state != "on_break":
                return
            self._finish_break_locked(utc_now(), auto=False)

    def _finish_break_locked(self, now: datetime, auto: bool) -> None:
        if not self.break_uuid or not self.break_started_at or not self.session_uuid:
            self.state = "running" if self.session_uuid else "idle"
            return
        duration = min(
            self.break_planned_seconds,
            max(0, int((now - self.break_started_at).total_seconds())),
        )
        self.db.finish_break(self.break_uuid, iso_utc(now) or "", duration, completed=True)
        self.break_seconds += duration
        self.db.update_session(
            self.session_uuid,
            status="running",
            break_seconds=self.break_seconds,
        )
        label = config.BREAK_TYPES.get(self.break_type or "", {}).get("label", "Namaz")
        self.emit(f"{label} break ended ({format_duration(duration)}). Tracking resumed.")
        self.break_uuid = None
        self.break_type = None
        self.break_started_at = None
        self.break_ends_at = None
        self.break_planned_seconds = 0
        self.state = "running"
        self._last_tick = time.monotonic()
        self._partial_second = 0.0
        self._last_input = now
        self._schedule_next_screenshot(now, skip_current=True)

    # ------------------------------------------------------------------ ticking
    def tick(self) -> None:
        now = utc_now()
        with self._lock:
            if self.state == "on_break":
                if self.break_ends_at and now >= self.break_ends_at:
                    self._finish_break_locked(now, auto=True)
                if self.state == "on_break" and not is_within_work_window(now):
                    self._finish_break_locked(now, auto=True)
                    self.stop_session("Office hours ended")
                return

            if self.state != "running" or not self.session_uuid:
                return

            if not is_within_work_window(now):
                self.stop_session("Office hours ended")
                return

            elapsed = max(0.0, min(2.0, time.monotonic() - self._last_tick))
            self._last_tick = time.monotonic()

            key = minute_iso(now)
            if self._current_minute and key != self._current_minute:
                self._flush_minute(self._current_minute)
                self._current_minute = key
            self._current_minute = key
            data = self._minutes.setdefault(key, self._blank_minute())

            self._partial_second += elapsed
            whole_seconds = int(self._partial_second)
            if whole_seconds:
                self._partial_second -= whole_seconds
                idle_age = self._idle_age(now)
                if idle_age > config.IDLE_THRESHOLD_SECONDS:
                    data["idle_seconds"] = min(60, data["idle_seconds"] + whole_seconds)
                    self.idle_seconds += whole_seconds
                else:
                    data["active_seconds"] = min(60, data["active_seconds"] + whole_seconds)
                    self.active_seconds += whole_seconds
            data["is_idle"] = 1 if data["idle_seconds"] > data["active_seconds"] else 0

            if time.monotonic() - self._last_persist >= 5:
                self._persist_current_locked(now, force=False)
                self._last_persist = time.monotonic()

            if self.next_screenshot_at and now >= self.next_screenshot_at:
                self._capture_screenshot(now)

    def _blank_minute(self) -> dict[str, int]:
        return {"keystrokes": 0, "clicks": 0, "active_seconds": 0, "idle_seconds": 0, "is_idle": 0}

    def _idle_age(self, now: datetime) -> float:
        hook_age = max(0.0, (now - self._last_input).total_seconds())
        windows_age = self._win_idle.seconds()
        if windows_age is None:
            return hook_age
        return max(hook_age, windows_age)

    def _persist_current_locked(self, now: datetime, force: bool = False) -> None:
        if not self.session_uuid or not self._current_minute:
            return
        data = self._minutes.get(self._current_minute)
        if not data:
            return
        if not force and data["active_seconds"] + data["idle_seconds"] == 0 and data["keystrokes"] + data["clicks"] == 0:
            return
        self.db.upsert_activity(
            self.session_uuid,
            self._current_minute,
            data["keystrokes"],
            data["clicks"],
            data["active_seconds"],
            data["idle_seconds"],
            bool(data["is_idle"]),
        )
        self.db.update_session(
            self.session_uuid,
            active_seconds=self.active_seconds,
            idle_seconds=self.idle_seconds,
            break_seconds=self.break_seconds,
        )

    def _flush_minute(self, key: str) -> None:
        if not self.session_uuid:
            return
        data = self._minutes.pop(key, None)
        if not data:
            return
        self.db.upsert_activity(
            self.session_uuid,
            key,
            data["keystrokes"],
            data["clicks"],
            data["active_seconds"],
            data["idle_seconds"],
            bool(data["is_idle"]),
        )

    # ------------------------------------------------------------------ screenshots
    def refresh_schedule(self) -> None:
        """Re-arm schedule driven timers after the admin changed a setting.

        The screenshot interval is baked into the next scheduled capture, so a
        new interval only takes effect once the timer is re-armed.
        """
        with self._lock:
            if self.state == "running":
                self._schedule_next_screenshot(utc_now(), skip_current=True)

    def _schedule_next_screenshot(self, now: datetime, skip_current: bool) -> None:
        interval = max(60, int(config.SCREENSHOT_INTERVAL_SECONDS))
        timestamp = now.timestamp()
        block = int(timestamp // interval)
        block_end = (block + 1) * interval
        if skip_current or block_end - timestamp < 45:
            block += 1
            start = block * interval + 15
            end = block * interval + interval - 15
        else:
            start = timestamp + 8
            end = block_end - 8
        if end <= start:
            end = start + 60
        self.next_screenshot_at = datetime.fromtimestamp(random.uniform(start, end), tz=timezone.utc)

    def _capture_screenshot(self, now: datetime) -> None:
        if self.state != "running" or not self.session_uuid:
            return
        if not PIL_AVAILABLE:
            self.emit("Screenshot skipped: Pillow is not installed.")
            self._schedule_next_screenshot(now, skip_current=True)
            return
        try:
            image = ImageGrab.grab(all_screens=True)
            if image.width > SCREENSHOT_MAX_WIDTH:
                ratio = SCREENSHOT_MAX_WIDTH / image.width
                image = image.resize((SCREENSHOT_MAX_WIDTH, int(image.height * ratio)))
            local = to_local(now)
            folder = SCREENSHOT_DIR / local.strftime("%Y-%m-%d")
            folder.mkdir(parents=True, exist_ok=True)
            filename = f"{local.strftime('%H%M%S')}-{uuid_module.uuid4().hex[:8]}.jpg"
            path = folder / filename
            image.convert("RGB").save(path, "JPEG", quality=SCREENSHOT_JPEG_QUALITY, optimize=True)
            self.db.add_screenshot(self.session_uuid, str(path), iso_utc(now) or "")
            self.screenshot_count += 1
            self.last_screenshot_at = now
            self.emit(f"Random screenshot captured ({self.screenshot_count} this session).")
        except Exception as exc:
            self.emit(f"Screenshot failed: {exc}")
        finally:
            self._schedule_next_screenshot(now, skip_current=True)

    # ------------------------------------------------------------------ summary
    def snapshot(self) -> dict:
        with self._lock:
            now = utc_now()
            elapsed = self.active_seconds + self.idle_seconds if self.state in ("running", "on_break") else 0
            idle_age = self._idle_age(now) if self.state == "running" else 0
            return {
                "state": self.state,
                "session_uuid": self.session_uuid,
                "started_at": self.started_at,
                "elapsed": elapsed,
                "active_seconds": self.active_seconds,
                "idle_seconds": self.idle_seconds,
                "break_seconds": self.break_seconds,
                "idle_age": idle_age,
                "is_idle": idle_age > config.IDLE_THRESHOLD_SECONDS,
                "screenshot_count": self.screenshot_count,
                "next_screenshot_at": self.next_screenshot_at,
                "break_type": self.break_type,
                "break_ends_at": self.break_ends_at,
                "schedule": schedule_status(now),
            }

    @staticmethod
    def _device_name() -> str:
        import platform

        return f"{platform.node() or 'Windows PC'} · {platform.system()}"
