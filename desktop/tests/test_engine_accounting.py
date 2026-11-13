"""Self running checks for the desktop time accounting.

Run with:  python desktop/tests/test_engine_accounting.py
"""
from __future__ import annotations

import os
import sys
import tempfile
import time
from datetime import datetime, timedelta, timezone
from pathlib import Path

DESKTOP_DIR = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(DESKTOP_DIR))

os.environ.setdefault("OTT_DATA_DIR", tempfile.mkdtemp(prefix="ott-engine-test-"))

from config import format_duration, iso_utc, minute_iso, utc_now  # noqa: E402
from database import Database  # noqa: E402
from engine import TrackingEngine  # noqa: E402
import engine as engine_module  # noqa: E402

FAILURES: list[str] = []


def check(label: str, actual, expected, tolerance: float = 0) -> None:
    if isinstance(expected, (int, float)) and isinstance(actual, (int, float)):
        ok = abs(actual - expected) <= tolerance
    else:
        ok = actual == expected
    print(f"{'PASS' if ok else 'FAIL'}  {label}: {actual!r}" + ("" if ok else f" (expected {expected!r})"))
    if not ok:
        FAILURES.append(label)


def test_durations_keep_their_seconds() -> None:
    print("\n-- duration formatting --")
    check("258 seconds", format_duration(258), "4m 18s")
    check("45 seconds", format_duration(45), "45s")
    check("0 seconds", format_duration(0), "0s")
    check("60 seconds", format_duration(60), "1m")
    check("3600 seconds", format_duration(3600), "1h 00m")
    check("3678 seconds", format_duration(3678), "1h 01m 18s")
    check("clock format", format_duration(258, clock=True), "00:04:18")


def test_engine_counts_the_wall_clock() -> None:
    print("\n-- engine time accounting --")
    db = Database()
    engine = TrackingEngine(db)
    engine.state = "running"
    engine.session_uuid = "accounting-check"
    engine._current_minute = None
    engine._minutes.clear()

    base = datetime(2026, 11, 11, 2, 20, 40, tzinfo=timezone.utc)
    wall = base

    # Normal one second ticks with two stalls. The old engine capped every
    # tick at two seconds and silently lost the rest.
    pattern = [1.0] * 40 + [3.0] + [1.0] * 40 + [5.0] + [1.0] * 40

    for step in pattern:
        wall = wall + timedelta(seconds=step)
        engine._last_tick = time.monotonic() - step
        engine._last_tick_wall = wall - timedelta(seconds=step)
        engine._account_time(wall)
        engine._flush_finished_minutes()

    wall = wall + timedelta(seconds=0.5)
    engine._last_tick = time.monotonic() - 0.5
    engine._last_tick_wall = wall - timedelta(seconds=0.5)
    engine._account_time(wall)
    engine._flush_finished_minutes()
    engine._persist_current_locked(wall, force=True)

    elapsed = (wall - base).total_seconds()
    tracked = engine.active_seconds + engine.idle_seconds
    rows = db.recent_activity("accounting-check", 100)
    row_total = sum(int(row["active_seconds"]) + int(row["idle_seconds"]) for row in rows)
    over_60 = [
        row["minute_start"]
        for row in rows
        if int(row["active_seconds"]) + int(row["idle_seconds"]) > 60
    ]

    check("wall clock is counted", tracked, elapsed, tolerance=1.0)
    check("stored minutes match the counter", row_total, tracked)
    check("no minute holds more than 60 seconds", over_60, [])
    check(
        "minutes are split on the real boundaries",
        sorted(int(row["active_seconds"]) + int(row["idle_seconds"]) for row in rows),
        [20, 48, 60],
    )

    db.close()


def test_break_allowance_expiry_stops_tracking() -> None:
    print("\n-- break allowance expiry --")
    db = Database()
    engine = TrackingEngine(db)
    now = utc_now()

    def prime_break(session_uuid: str, break_uuid: str, break_type: str, minutes_ago: int, ends_in: int) -> None:
        db.create_session(session_uuid, iso_utc(now), "test-pc")
        db.add_break(break_uuid, session_uuid, break_type, iso_utc(now - timedelta(minutes=minutes_ago)))
        with engine._lock:
            engine.state = "on_break"
            engine.session_uuid = session_uuid
            engine.started_at = now
            engine.active_seconds = 0
            engine.idle_seconds = 0
            engine.break_seconds = 0
            engine.break_uuid = break_uuid
            engine.break_type = break_type
            engine.break_started_at = now - timedelta(minutes=minutes_ago)
            engine.break_planned_seconds = 15 * 60
            engine.break_ends_at = now + timedelta(seconds=ends_in)
            engine._current_minute = minute_iso(now)
            engine._minutes.clear()
            engine._minutes[engine._current_minute] = engine._blank_minute()
            engine._last_tick = time.monotonic()
            engine._last_tick_wall = now

    # A break that ended before its allowance lets tracking continue.
    prime_break("break-in-time", "break-in-time-uuid", "asor", minutes_ago=5, ends_in=600)
    engine.end_break()
    check("early return keeps tracking", engine.state, "running")
    check("early return leaves session open", db.get_session("break-in-time")["status"], "running")
    engine.stop_session("test cleanup")

    # A break that runs past its allowance stops tracking automatically.
    prime_break("break-overdue", "break-overdue-uuid", "toilet", minutes_ago=20, ends_in=-5)
    engine.tick()
    check("overdue break stops tracking", engine.state, "idle")
    check("overdue break stops the session", db.get_session("break-overdue")["status"], "stopped")
    check(
        "break duration is capped at the allowance",
        int(db.get_session("break-overdue")["break_seconds"]),
        900,
    )

    db.close()


def test_restart_keeps_an_open_break_paused() -> None:
    print("\n-- restart during break --")
    db = Database()
    engine = TrackingEngine(db)
    now = utc_now()

    original_window_check = engine_module.is_within_work_window
    engine_module.is_within_work_window = lambda at=None: True
    try:
        # The break is still inside its allowance: keep the session paused.
        db.create_session("resume-open-break", iso_utc(now - timedelta(minutes=5)), "test-pc")
        db.add_break(
            "resume-open-break-uuid",
            "resume-open-break",
            "toilet",
            iso_utc(now - timedelta(minutes=2)),
        )
        check("open break is recovered", engine.resume_if_possible(), True)
        check("open break keeps the engine paused", engine.state, "on_break")
        check("open break keeps the session paused", db.get_session("resume-open-break")["status"], "paused")
        check("open break keeps its type", engine.break_type, "toilet")
        engine.stop_session("test cleanup")

        # The allowance ran out while the app was closed: stay stopped.
        db.create_session("resume-expired-break", iso_utc(now - timedelta(minutes=25)), "test-pc")
        db.add_break(
            "resume-expired-break-uuid",
            "resume-expired-break",
            "asor",
            iso_utc(now - timedelta(minutes=21)),
        )
        check("expired break is not resumed", engine.resume_if_possible(), False)
        row = db.get_session("resume-expired-break")
        check("expired break leaves tracking stopped", row["status"], "stopped")
        check("expired break time is recorded and capped", int(row["break_seconds"]), 1200)
    finally:
        engine_module.is_within_work_window = original_window_check
        db.close()


if __name__ == "__main__":
    test_durations_keep_their_seconds()
    test_engine_counts_the_wall_clock()
    test_break_allowance_expiry_stops_tracking()
    test_restart_keeps_an_open_break_paused()

    print()
    if FAILURES:
        print(f"{len(FAILURES)} check(s) failed: {', '.join(FAILURES)}")
        sys.exit(1)

    print("All checks passed.")
