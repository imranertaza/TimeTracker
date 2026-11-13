"""Shared configuration and Bangladesh-time helpers for the desktop tracker."""
from __future__ import annotations

import json
import os
from dataclasses import dataclass
from datetime import date, datetime, time, timedelta, timezone
from pathlib import Path

try:
    from zoneinfo import ZoneInfo

    DHAKA_TZ = ZoneInfo("Asia/Dhaka")
except Exception:  # pragma: no cover - Windows fallback when tzdata is unavailable
    DHAKA_TZ = timezone(timedelta(hours=6), name="Asia/Dhaka")

APP_NAME = "Office Time Tracker"
APP_VERSION = "1.0.0"

ROOT_DIR = Path(__file__).resolve().parent

def _data_dir() -> Path:
    override = os.environ.get("OTT_DATA_DIR")
    if override:
        return Path(override).expanduser().resolve()
    local = os.environ.get("LOCALAPPDATA")
    if local:
        return Path(local) / "OfficeTimeTracker"
    return ROOT_DIR / "data"

DATA_DIR = _data_dir()
DATA_DIR.mkdir(parents=True, exist_ok=True)

DB_PATH = DATA_DIR / "tracker.db"
SCREENSHOT_DIR = DATA_DIR / "screenshots"
SCREENSHOT_DIR.mkdir(parents=True, exist_ok=True)
LOG_PATH = DATA_DIR / "tracker.log"

DEFAULT_API_URL = os.environ.get("OTT_API_URL", "http://127.0.0.1:8000/api/v1")

WORK_WINDOWS: tuple[tuple[time, time], ...] = (
    (time(9, 0), time(13, 15)),
    (time(14, 15), time(18, 30)),
)

BREAK_TYPES: dict[str, dict[str, object]] = {
    "asor": {"label": "Asor Namaz", "minutes": 20},
    "maghrib": {"label": "Maghrib Namaz", "minutes": 15},
    "toilet": {"label": "Toilet Break", "minutes": 15},
}

IDLE_THRESHOLD_SECONDS = 5 * 60
SCREENSHOT_INTERVAL_SECONDS = 10 * 60
SYNC_INTERVAL_SECONDS = 20
SCREENSHOT_MAX_WIDTH = 1920
SCREENSHOT_JPEG_QUALITY = 72


def _poll_seconds(name: str, fallback: float) -> float:
    raw = os.environ.get(name)
    if not raw:
        return fallback
    try:
        value = float(raw)
    except (TypeError, ValueError):
        return fallback
    return max(1.0, value)


# How often the desktop asks the server whether the office settings changed.
# The admin panel writes are picked up within this many seconds.
SETTINGS_POLL_SECONDS = _poll_seconds("OTT_SETTINGS_POLL_SECONDS", 2.0)


@dataclass(frozen=True)
class ScheduleStatus:
    is_open: bool
    window_start: datetime | None
    window_end: datetime | None
    next_start: datetime

    @property
    def label(self) -> str:
        if self.is_open and self.window_end:
            return f"Open until {self.window_end.strftime('%I:%M %p').lstrip('0')}"
        return f"Closed · next start {self.next_start.strftime('%d %b, %I:%M %p').lstrip('0')}"


def dhaka_now() -> datetime:
    return datetime.now(DHAKA_TZ)


def utc_now() -> datetime:
    return datetime.now(timezone.utc)


def to_local(value: datetime) -> datetime:
    if value.tzinfo is None:
        value = value.replace(tzinfo=timezone.utc)
    return value.astimezone(DHAKA_TZ)


def to_utc(value: datetime) -> datetime:
    if value.tzinfo is None:
        value = value.replace(tzinfo=DHAKA_TZ)
    return value.astimezone(timezone.utc)


def parse_iso(value: str | None) -> datetime | None:
    if not value:
        return None
    text = value.strip()
    if text.endswith("Z"):
        text = text[:-1] + "+00:00"
    parsed = datetime.fromisoformat(text)
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=timezone.utc)
    return parsed


def iso_utc(value: datetime | None) -> str | None:
    if value is None:
        return None
    return to_utc(value).isoformat(timespec="seconds").replace("+00:00", "Z")


def minute_iso(value: datetime | None = None) -> str:
    value = to_utc(value or utc_now()).replace(second=0, microsecond=0)
    return iso_utc(value) or ""


def schedule_status(at: datetime | None = None) -> ScheduleStatus:
    local = to_local(at or utc_now())
    current = local.time()
    today = local.date()

    for start, end in WORK_WINDOWS:
        if start <= current < end:
            return ScheduleStatus(
                is_open=True,
                window_start=datetime.combine(today, start, tzinfo=DHAKA_TZ),
                window_end=datetime.combine(today, end, tzinfo=DHAKA_TZ),
                next_start=datetime.combine(today, start, tzinfo=DHAKA_TZ),
            )

    for offset in range(0, 8):
        day = today + timedelta(days=offset)
        for start, _end in WORK_WINDOWS:
            candidate = datetime.combine(day, start, tzinfo=DHAKA_TZ)
            if candidate > local:
                return ScheduleStatus(False, None, None, candidate)

    return ScheduleStatus(False, None, None, datetime.combine(today + timedelta(days=1), time(9, 0), tzinfo=DHAKA_TZ))


def is_within_work_window(at: datetime | None = None) -> bool:
    return schedule_status(at).is_open


def _parse_window_time(value: object) -> time | None:
    if value is None:
        return None
    text = str(value).strip()
    if not text:
        return None
    try:
        return time.fromisoformat(text[:5])
    except ValueError:
        return None


def apply_schedule_payload(payload: dict | None, db: object | None = None) -> bool:
    """Apply the schedule returned by the Laravel API to this process.

    Returns True when the applied values actually differ from the ones that
    were active before, so callers can refresh anything that was rendered from
    the old schedule.
    """
    global WORK_WINDOWS, BREAK_TYPES, IDLE_THRESHOLD_SECONDS, SCREENSHOT_INTERVAL_SECONDS

    if not isinstance(payload, dict):
        return False

    before = (WORK_WINDOWS, BREAK_TYPES, IDLE_THRESHOLD_SECONDS, SCREENSHOT_INTERVAL_SECONDS)

    windows: list[tuple[time, time]] = []
    raw_windows = payload.get("windows")
    if isinstance(raw_windows, list):
        for item in raw_windows:
            if not isinstance(item, dict):
                continue
            start = _parse_window_time(item.get("start"))
            end = _parse_window_time(item.get("end"))
            if start is not None and end is not None and end > start:
                windows.append((start, end))
    if windows:
        WORK_WINDOWS = tuple(windows)

    try:
        idle_minutes = int(payload.get("idle_threshold_minutes") or 0)
        if idle_minutes > 0:
            IDLE_THRESHOLD_SECONDS = idle_minutes * 60
    except (TypeError, ValueError):
        pass

    try:
        screenshot_minutes = int(payload.get("screenshot_interval_minutes") or 0)
        if screenshot_minutes > 0:
            SCREENSHOT_INTERVAL_SECONDS = screenshot_minutes * 60
    except (TypeError, ValueError):
        pass

    raw_breaks = payload.get("breaks")
    if isinstance(raw_breaks, dict):
        # Start from the current rules so a break type that an older server
        # payload does not mention (for example toilet) is never dropped.
        breaks: dict[str, dict[str, object]] = {
            key: dict(value) for key, value in BREAK_TYPES.items()
        }
        for key in ("asor", "maghrib", "toilet"):
            item = raw_breaks.get(key)
            if not isinstance(item, dict):
                continue
            try:
                minutes = int(item.get("minutes") or 0)
            except (TypeError, ValueError):
                minutes = 0
            if minutes <= 0:
                continue
            breaks[key] = {
                "label": str(item.get("label") or BREAK_TYPES.get(key, {}).get("label", key.title())),
                "minutes": minutes,
            }
        if breaks:
            BREAK_TYPES = breaks

    if db is not None:
        try:
            db.set_setting("schedule_json", json.dumps(payload))
        except Exception:
            pass

    after = (WORK_WINDOWS, BREAK_TYPES, IDLE_THRESHOLD_SECONDS, SCREENSHOT_INTERVAL_SECONDS)

    return before != after


def load_saved_schedule(db: object) -> bool:
    raw = db.get_setting("schedule_json")
    if not raw:
        return False
    try:
        payload = json.loads(raw)
    except (TypeError, ValueError):
        return False
    return apply_schedule_payload(payload)


def schedule_windows_label() -> str:
    return "  ·  ".join(
        f"{start.strftime('%H:%M')}–{end.strftime('%H:%M')}" for start, end in WORK_WINDOWS
    )


def schedule_windows_multiline() -> str:
    return "\n".join(
        f"{start.strftime('%H:%M')} – {end.strftime('%H:%M')}" for start, end in WORK_WINDOWS
    )


def break_summary() -> str:
    return " · ".join(
        f"{str(config.get('label', key.title()))} {int(config.get('minutes', 0))}m"
        for key, config in BREAK_TYPES.items()
    )


def day_bounds_utc(local_day: date) -> tuple[datetime, datetime]:
    start = datetime.combine(local_day, time(0, 0), tzinfo=DHAKA_TZ)
    end = start + timedelta(days=1)
    return to_utc(start), to_utc(end)


def format_duration(seconds: float | int, clock: bool = False) -> str:
    """Human readable duration.

    Seconds are never silently dropped: 258 seconds is shown as "4m 18s" and
    not as "4m", so the tracker and the web report always agree.
    """
    total = max(0, int(seconds))
    if clock:
        hours, remainder = divmod(total, 3600)
        minutes, secs = divmod(remainder, 60)
        return f"{hours:02d}:{minutes:02d}:{secs:02d}"
    hours, remainder = divmod(total, 3600)
    minutes, secs = divmod(remainder, 60)
    if hours:
        text = f"{hours}h {minutes:02d}m"
        return f"{text} {secs:02d}s" if secs else text
    if minutes:
        text = f"{minutes}m"
        return f"{text} {secs:02d}s" if secs else text
    return f"{total}s"


def format_local(value: datetime | None, pattern: str = "%d %b %Y, %I:%M %p") -> str:
    if not value:
        return "—"
    return to_local(value).strftime(pattern).lstrip("0")


def format_clock(value: datetime | None = None) -> str:
    local = to_local(value or utc_now())
    return local.strftime("%I:%M:%S %p").lstrip("0")
