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

from api_client import ApiClient  # noqa: E402
from config import dhaka_now, format_duration, iso_utc, minute_iso, to_utc, utc_now  # noqa: E402
from database import Database  # noqa: E402
from engine import TrackingEngine  # noqa: E402
import config as config_module  # noqa: E402
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


def test_one_physical_click_is_counted_once() -> None:
    print("\n-- mouse click counting --")
    db = Database()
    engine = TrackingEngine(db)
    engine.state = "running"
    engine.session_uuid = "click-check"
    engine._minutes.clear()
    engine._current_minute = minute_iso(utc_now())
    engine._minutes[engine._current_minute] = engine._blank_minute()

    # The Windows listener reports a press and a release for one click.
    engine._on_click(0, 0, "left", True)
    engine._on_click(0, 0, "left", False)

    clicks = sum(int(row["clicks"]) for row in engine._minutes.values())
    check("one physical click counted once", clicks, 1)
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


def test_inactivity_timers_and_stop_priority() -> None:
    print("\n-- inactivity timers --")
    db = Database()
    engine = TrackingEngine(db)
    now = utc_now()

    class NoIdle:
        def seconds(self):
            return None

    original_window_check = engine_module.is_within_work_window
    original_idle = config_module.IDLE_THRESHOLD_SECONDS
    original_stop = config_module.STOP_AFTER_SECONDS
    engine_module.is_within_work_window = lambda at=None: True
    engine._win_idle = NoIdle()

    def prime(session_uuid: str, idle_minutes: int) -> None:
        db.create_session(session_uuid, iso_utc(now - timedelta(minutes=10)), "test-pc")
        with engine._lock:
            engine.state = "running"
            engine.session_uuid = session_uuid
            engine.started_at = now - timedelta(minutes=10)
            engine.ended_at = None
            engine.active_seconds = 0
            engine.idle_seconds = 0
            engine.break_seconds = 0
            engine._current_minute = minute_iso(now)
            engine._minutes.clear()
            engine._minutes[engine._current_minute] = engine._blank_minute()
            engine._last_tick = time.monotonic() - 2
            engine._last_tick_wall = now - timedelta(seconds=2)
            engine._partial_second = 0.0
            engine._last_input = now - timedelta(minutes=idle_minutes)

    def configure(idle_minutes: int, stop_minutes: int) -> None:
        config_module.IDLE_THRESHOLD_SECONDS = idle_minutes * 60
        config_module.STOP_AFTER_SECONDS = stop_minutes * 60

    try:
        # idle 2, stop 4: idle time starts at 2 minutes and the session stops
        # at 4 minutes.
        configure(2, 4)
        prime("timers-idle-between", idle_minutes=3)
        engine.tick()
        check("idle between timers keeps tracking", engine.state, "running")
        check("idle between timers is recorded", engine.idle_seconds > 0, True)
        engine.stop_session("test cleanup")

        prime("timers-idle-then-stop", idle_minutes=5)
        engine.tick()
        check("stop timer stops the session", engine.state, "idle")
        check(
            "stop timer closes the session",
            db.get_session("timers-idle-then-stop")["status"],
            "stopped",
        )

        # idle 4, stop 2: stop wins, so no idle time is ever recorded.
        configure(4, 2)
        prime("timers-stop-early", idle_minutes=1)
        engine.tick()
        check("stop priority keeps early input active", engine.state, "running")
        check("stop priority records active time", engine.active_seconds > 0, True)
        check("stop priority records no idle time", engine.idle_seconds, 0)
        engine.stop_session("test cleanup")

        prime("timers-stop-before-idle", idle_minutes=3)
        engine.tick()
        check("earlier stop timer stops the session", engine.state, "idle")
        check("earlier stop timer records no idle time", engine.idle_seconds, 0)

        # idle 2, stop 2: equal timers stop without idle time.
        configure(2, 2)
        prime("timers-stop-equal", idle_minutes=3)
        engine.tick()
        check("equal timers stop the session", engine.state, "idle")
        check("equal timers record no idle time", engine.idle_seconds, 0)

        # idle 2, stop 0: idle works and tracking never auto-stops.
        configure(2, 0)
        prime("timers-idle-only", idle_minutes=30)
        engine.tick()
        check("idle only keeps tracking", engine.state, "running")
        check("idle only records idle time", engine.idle_seconds > 0, True)
        engine.stop_session("test cleanup")

        # idle 0, stop 4: no idle time, tracking stops at 4 minutes.
        configure(0, 4)
        prime("timers-stop-only", idle_minutes=3)
        engine.tick()
        check("stop only records active time", engine.active_seconds > 0, True)
        check("stop only records no idle time", engine.idle_seconds, 0)
        engine.stop_session("test cleanup")

        prime("timers-stop-only-late", idle_minutes=5)
        engine.tick()
        check("stop only stops at its timer", engine.state, "idle")
        check("stop only never records idle time", engine.idle_seconds, 0)

        # idle 0, stop 0: both timers disabled.
        configure(0, 0)
        prime("timers-disabled", idle_minutes=30)
        engine.tick()
        check("disabled timers keep tracking", engine.state, "running")
        check("disabled timers record active time", engine.active_seconds > 0, True)
        check("disabled timers record no idle time", engine.idle_seconds, 0)
        engine.stop_session("test cleanup")
    finally:
        engine_module.is_within_work_window = original_window_check
        config_module.IDLE_THRESHOLD_SECONDS = original_idle
        config_module.STOP_AFTER_SECONDS = original_stop
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


def test_away_time_is_billed_from_the_wall_clock() -> None:
    print("\n-- away time accounting --")
    db = Database()
    engine = TrackingEngine(db)
    base = datetime(2026, 11, 11, 3, 0, 0, tzinfo=timezone.utc)  # 09:00 Dhaka

    with engine._lock:
        engine.state = "running"
        engine.session_uuid = "away-accounting"
        engine.started_at = base - timedelta(minutes=5)
        engine.away = True
        engine._current_minute = minute_iso(base)
        engine._minutes.clear()
        engine._last_tick = time.monotonic() - 130
        engine._last_tick_wall = base

    engine._account_time(base + timedelta(seconds=130))
    engine._flush_finished_minutes()
    engine._persist_current_locked(base + timedelta(seconds=130), force=True)

    rows = db.recent_activity("away-accounting", 100)
    check("away seconds counted", engine.away_seconds, 130)
    check("active time stays zero while away", engine.active_seconds, 0)
    check("idle time stays zero while away", engine.idle_seconds, 0)
    check(
        "stored away minutes match the counter",
        sum(int(row["away_seconds"]) for row in rows),
        130,
    )
    check(
        "no away minute holds more than 60 seconds",
        [row["minute_start"] for row in rows if int(row["away_seconds"]) > 60],
        [],
    )
    check(
        "away minutes split on real boundaries",
        sorted(int(row["away_seconds"]) for row in rows),
        [10, 60, 60],
    )
    db.close()


def test_away_mode_bypasses_inactivity_stop() -> None:
    print("\n-- away mode and auto-stop --")
    db = Database()
    engine = TrackingEngine(db)
    now = utc_now()

    class NoIdle:
        def seconds(self):
            return None

    original_window_check = engine_module.is_within_work_window
    original_now = engine_module.utc_now
    original_idle = config_module.IDLE_THRESHOLD_SECONDS
    original_stop = config_module.STOP_AFTER_SECONDS
    engine_module.is_within_work_window = lambda at=None: True
    engine_module.utc_now = lambda: now
    engine._win_idle = NoIdle()
    engine._is_work_second = lambda moment: True
    config_module.IDLE_THRESHOLD_SECONDS = 30 * 60
    config_module.STOP_AFTER_SECONDS = 60 * 60

    try:
        db.create_session("away-stop-check", iso_utc(now), "test-pc")
        with engine._lock:
            engine.state = "running"
            engine.session_uuid = "away-stop-check"
            engine.started_at = now - timedelta(minutes=5)
            engine._current_minute = minute_iso(now)
            engine._minutes.clear()
            engine._last_tick = time.monotonic()
            engine._last_tick_wall = now
            engine._partial_second = 0.0
            # The last input is far in the past, which would normally stop us.
            engine._last_input = now - timedelta(minutes=30)

        check("away mode turns on", engine.set_away(True), True)
        with engine._lock:
            engine._last_tick = time.monotonic() - 2
            engine._last_tick_wall = now - timedelta(seconds=2)
        engine.tick()
        check("away bypasses the stop timer", engine.state, "running")
        check("away tick is recorded", engine.away_seconds >= 2, True)
        check("screenshots are paused", engine.next_screenshot_at, None)

        check("away mode turns off", engine.set_away(False), True)
        check("returning keeps tracking", engine.state, "running")
        with engine._lock:
            engine._last_input = utc_now() - timedelta(minutes=30)
        engine.tick()
        check("idle timer restarts after returning", engine.state, "running")
        check("away flag is cleared", engine.away, False)
        check("away state is synced locally", bool(db.get_session("away-stop-check")["away"]), False)
    finally:
        engine_module.is_within_work_window = original_window_check
        engine_module.utc_now = original_now
        config_module.IDLE_THRESHOLD_SECONDS = original_idle
        config_module.STOP_AFTER_SECONDS = original_stop
        engine.stop_session("test cleanup")
        db.close()


def test_restart_recovers_away_time() -> None:
    print("\n-- restart during away mode --")
    db = Database()
    engine = TrackingEngine(db)
    local_date = dhaka_now().date()
    local_start = datetime.combine(local_date, datetime.min.time().replace(hour=10), tzinfo=config_module.DHAKA_TZ)
    fixed_now = local_start + timedelta(minutes=30)
    started = to_utc(local_start)
    now = to_utc(fixed_now)

    original_window_check = engine_module.is_within_work_window
    original_now = engine_module.utc_now
    engine_module.is_within_work_window = lambda at=None: True
    engine_module.utc_now = lambda: now
    try:
        db.create_session("resume-away", iso_utc(started), "test-pc")
        db.update_session("resume-away", away=1)
        with db._lock, db.connection:
            db.connection.execute(
                "UPDATE sessions SET updated_at = ? WHERE uuid = ?",
                (iso_utc(started), "resume-away"),
            )

        check("away session is resumed", engine.resume_if_possible(), True)
        check("engine restores away mode", engine.away, True)
        check("gap is billed as away time", engine.away_seconds, 1800)
        check(
            "stored away seconds are restored",
            int(db.get_session("resume-away")["away_seconds"]),
            1800,
        )
        check("no screenshots are scheduled while away", engine.next_screenshot_at, None)
        engine.stop_session("test cleanup")
    finally:
        engine_module.is_within_work_window = original_window_check
        engine_module.utc_now = original_now
        db.close()


def test_sync_payload_includes_away_fields() -> None:
    print("\n-- sync payload --")
    captured: dict = {}

    class StubApi(ApiClient):
        def _request(self, method: str, path: str, payload: dict | None = None) -> dict:
            captured.update(method=method, path=path, payload=payload or {})
            return {}

    StubApi("http://example.test").sync_session(
        "session-uuid",
        "running",
        [{"minute_start": "2026-11-11T03:00:00Z", "away_seconds": 60}],
        [],
        away=True,
    )
    payload = captured["payload"]
    check("sync sends the live away flag", payload.get("away"), True)
    check(
        "sync sends per-minute away seconds",
        payload["activity"][0].get("away_seconds"),
        60,
    )


if __name__ == "__main__":
    test_durations_keep_their_seconds()
    test_engine_counts_the_wall_clock()
    test_one_physical_click_is_counted_once()
    test_break_allowance_expiry_stops_tracking()
    test_inactivity_timers_and_stop_priority()
    test_restart_keeps_an_open_break_paused()
    test_away_time_is_billed_from_the_wall_clock()
    test_away_mode_bypasses_inactivity_stop()
    test_restart_recovers_away_time()
    test_sync_payload_includes_away_fields()

    print()
    if FAILURES:
        print(f"{len(FAILURES)} check(s) failed: {', '.join(FAILURES)}")
        sys.exit(1)

    print("All checks passed.")
