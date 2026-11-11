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

from config import format_duration  # noqa: E402
from database import Database  # noqa: E402
from engine import TrackingEngine  # noqa: E402

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


if __name__ == "__main__":
    test_durations_keep_their_seconds()
    test_engine_counts_the_wall_clock()

    print()
    if FAILURES:
        print(f"{len(FAILURES)} check(s) failed: {', '.join(FAILURES)}")
        sys.exit(1)

    print("All checks passed.")
