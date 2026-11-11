"""Tkinter desktop interface for the Office Time Tracker."""
from __future__ import annotations

import json
import logging
import socket
import threading
import tkinter as tk
from datetime import date, datetime
from pathlib import Path
from tkinter import messagebox, ttk

import autostart
from api_client import ApiClient, ApiError
from config import (
    APP_NAME,
    APP_VERSION,
    DEFAULT_API_URL,
    ROOT_DIR,
    apply_schedule_payload,
    break_summary,
    dhaka_now,
    format_clock,
    format_duration,
    format_local,
    load_saved_schedule,
    parse_iso,
    schedule_status,
    schedule_windows_label,
    schedule_windows_multiline,
)
from database import Database
from engine import TrackingEngine
from sync_service import SyncService

import config

log = logging.getLogger(__name__)

BG = "#f3f6f4"
SURFACE = "#ffffff"
SOFT = "#f8faf9"
BORDER = "#dfe7e2"
TEXT = "#16231d"
MUTED = "#68776f"
GREEN = "#0f7a53"
GREEN_DARK = "#0a5138"
GREEN_SOFT = "#e5f5ed"
ORANGE = "#c77715"
ORANGE_SOFT = "#fff3d8"
PURPLE = "#7553a6"
PURPLE_SOFT = "#f0eafa"
BLUE = "#2f6fb0"
BLUE_SOFT = "#e7f0fb"
RED = "#c2413b"
RED_SOFT = "#fdeceb"
DARK = "#10271f"
DARK_SOFT = "#17372b"
SIDEBAR_TEXT = "#c4d8cf"

FONT = "Segoe UI"
MONO = "Cascadia Code"


class TrackerApp:
    def __init__(self, root: tk.Tk):
        self.root = root
        self.root.title(f"{APP_NAME} · Dhaka")
        self.root.geometry("1160x780")
        self.root.minsize(1020, 720)
        self.root.configure(bg=BG)

        self.db = Database()
        load_saved_schedule(self.db)
        self.engine = TrackingEngine(self.db)
        self.engine.start_listeners()
        self.sync = SyncService(self.db)
        self.sync.start()

        self.api_url = self.db.get_setting("api_url", DEFAULT_API_URL) or DEFAULT_API_URL
        self.token = self.db.get_setting("api_token")
        cached_user = self.db.get_setting("user_json")
        try:
            self.user = json.loads(cached_user) if cached_user else None
        except json.JSONDecodeError:
            self.user = None

        self.view = "login"
        self._tick_job: str | None = None
        self._last_activity_refresh = 0.0
        self._last_log_text = ""
        self._login_busy = False
        self._session_display_uuid: str | None = None

        self.root.protocol("WM_DELETE_WINDOW", self.on_close)
        self._build_login()
        self.root.after(350, self._try_auto_login)

    # ------------------------------------------------------------------ helpers
    def _clear(self) -> None:
        for child in self.root.winfo_children():
            child.destroy()

    def _button(self, parent, text, command, bg=GREEN, fg="#ffffff", **kwargs):
        button = tk.Button(
            parent,
            text=text,
            command=command,
            bg=bg,
            fg=fg,
            activebackground=kwargs.pop("activebackground", bg),
            activeforeground=fg,
            relief="flat",
            bd=0,
            cursor="hand2",
            font=(FONT, 10, "bold"),
            padx=14,
            pady=9,
            highlightthickness=0,
        )
        for key, value in kwargs.items():
            button.configure(**{key: value})
        return button

    def _card(self, parent, **kwargs):
        frame = tk.Frame(
            parent,
            bg=kwargs.pop("bg", SURFACE),
            highlightbackground=BORDER,
            highlightthickness=1,
            bd=0,
            **kwargs,
        )
        return frame

    # ------------------------------------------------------------------ login
    def _build_login(self, message: str | None = None) -> None:
        self.view = "login"
        self._clear()
        self.root.configure(bg=DARK)

        wrap = tk.Frame(self.root, bg=DARK, width=1080, height=680)
        wrap.place(relx=0.5, rely=0.5, anchor="center")
        wrap.pack_propagate(False)
        wrap.grid_columnconfigure(0, weight=55, uniform="login")
        wrap.grid_columnconfigure(1, weight=45, uniform="login")
        wrap.grid_rowconfigure(0, weight=1)

        story = tk.Frame(wrap, bg=DARK)
        story.grid(row=0, column=0, sticky="nsew", padx=(28, 20), pady=30)

        brand = tk.Frame(story, bg=DARK)
        brand.pack(anchor="w")
        tk.Label(brand, text="OT", bg=GREEN, fg="#ffffff", font=(FONT, 17, "bold"), width=3, height=1).pack(side="left", padx=(0, 12))
        brand_text = tk.Frame(brand, bg=DARK)
        brand_text.pack(side="left")
        tk.Label(brand_text, text=APP_NAME, bg=DARK, fg="#ffffff", font=(FONT, 14, "bold")).pack(anchor="w")
        tk.Label(brand_text, text="DHAKA · BANGLADESH", bg=DARK, fg="#86a899", font=(FONT, 8, "bold")).pack(anchor="w")

        tk.Label(
            story,
            text="Every working\nminute, accounted for.",
            bg=DARK,
            fg="#eaf7f1",
            font=(FONT, 35, "bold"),
            justify="left",
            anchor="w",
        ).pack(anchor="w", pady=(58, 16))
        tk.Label(
            story,
            text="A complete office time tracker for your Windows PC. It opens with the computer, enforces office hours, counts activity, captures random screenshots and reports prayer breaks.",
            bg=DARK,
            fg="#9dc2b2",
            font=(FONT, 10),
            justify="left",
            wraplength=440,
            anchor="w",
        ).pack(anchor="w")

        features = [
            f"{schedule_windows_label()} office windows",
            "Keyboard and mouse activity, counted privately",
            f"Idle time starts after {config.IDLE_THRESHOLD_SECONDS // 60} minutes without input",
            f"Random screenshots every {config.SCREENSHOT_INTERVAL_SECONDS // 60} minutes",
            f"{break_summary()} namaz breaks",
            "Automatic API sync with the Laravel admin panel",
        ]
        for feature in features:
            row = tk.Frame(story, bg=DARK)
            row.pack(anchor="w", pady=4)
            tk.Label(row, text="●", bg=DARK, fg="#4bd39e", font=(FONT, 7)).pack(side="left", padx=(0, 10))
            tk.Label(row, text=feature, bg=DARK, fg="#c7ded4", font=(FONT, 9)).pack(side="left")

        tk.Label(story, text="All timestamps use Asia/Dhaka (UTC+6).", bg=DARK, fg="#719687", font=(FONT, 8)).pack(anchor="w", side="bottom")

        panel_wrap = tk.Frame(wrap, bg=DARK)
        panel_wrap.grid(row=0, column=1, sticky="nsew", padx=(10, 28), pady=30)
        panel = tk.Frame(panel_wrap, bg=SURFACE, padx=38, pady=34)
        panel.pack(fill="both", expand=True)

        tk.Label(panel, text="Welcome back", bg=SURFACE, fg=TEXT, font=(FONT, 23, "bold"), anchor="w").pack(fill="x")
        tk.Label(panel, text="Sign in to start your office tracker.", bg=SURFACE, fg=MUTED, font=(FONT, 10), anchor="w").pack(fill="x", pady=(4, 22))

        if message:
            box = tk.Label(
                panel,
                text=message,
                bg=ORANGE_SOFT,
                fg="#76520f",
                font=(FONT, 9),
                justify="left",
                wraplength=340,
                padx=12,
                pady=9,
                anchor="w",
            )
            box.pack(fill="x", pady=(0, 16))

        self.login_error = tk.Label(panel, text="", bg=RED_SOFT, fg=RED, font=(FONT, 9), justify="left", wraplength=340, padx=10, pady=7, anchor="w")
        self.login_error.pack_forget()

        tk.Label(panel, text="EMAIL ADDRESS", bg=SURFACE, fg=MUTED, font=(FONT, 8, "bold"), anchor="w").pack(fill="x", pady=(0, 5))
        self.login_email = tk.Entry(panel, bg="#ffffff", fg=TEXT, insertbackground=TEXT, relief="solid", bd=1, font=(FONT, 10), highlightthickness=0)
        self.login_email.pack(fill="x", ipady=8, pady=(0, 14))
        self.login_email.insert(0, self.db.get_setting("last_email", "") or "")

        tk.Label(panel, text="PASSWORD", bg=SURFACE, fg=MUTED, font=(FONT, 8, "bold"), anchor="w").pack(fill="x", pady=(0, 5))
        self.login_password = tk.Entry(panel, show="•", bg="#ffffff", fg=TEXT, insertbackground=TEXT, relief="solid", bd=1, font=(FONT, 10), highlightthickness=0)
        self.login_password.pack(fill="x", ipady=8, pady=(0, 14))

        tk.Label(panel, text="SERVER API URL", bg=SURFACE, fg=MUTED, font=(FONT, 8, "bold"), anchor="w").pack(fill="x", pady=(0, 5))
        self.login_api = tk.Entry(panel, bg="#ffffff", fg=TEXT, insertbackground=TEXT, relief="solid", bd=1, font=(FONT, 9), highlightthickness=0)
        self.login_api.pack(fill="x", ipady=7, pady=(0, 18))
        self.login_api.insert(0, self.api_url)

        self.login_button = self._button(panel, "Sign in & open tracker", self._do_login, bg=GREEN, pady=11)
        self.login_button.pack(fill="x")

        tk.Label(
            panel,
            text="Demo staff: staff@officetracker.test  /  Staff@12345",
            bg=SURFACE,
            fg=MUTED,
            font=(FONT, 8),
            anchor="w",
        ).pack(fill="x", pady=(16, 0))
        tk.Label(panel, text="Desktop app v" + APP_VERSION + " · Windows startup ready", bg=SURFACE, fg="#9aa9a1", font=(FONT, 7), anchor="w").pack(fill="x", pady=(4, 0))

        self.login_password.bind("<Return>", lambda _e: self._do_login())
        self.login_email.focus_set()
        self.root.after(50, lambda: self.login_password.focus_set())

    def _set_login_busy(self, busy: bool, text: str = "Signing in…") -> None:
        self._login_busy = busy
        try:
            self.login_button.configure(state="disabled" if busy else "normal", text=text)
        except tk.TclError:
            pass

    def _show_login_error(self, message: str) -> None:
        try:
            self.login_error.configure(text=message)
            self.login_error.pack(fill="x", pady=(0, 14), before=self.login_email)
        except tk.TclError:
            pass

    def _do_login(self) -> None:
        if self._login_busy:
            return
        email = self.login_email.get().strip()
        password = self.login_password.get()
        api_url = self.login_api.get().strip() or DEFAULT_API_URL
        if not email or not password:
            self._show_login_error("Email and password are required.")
            return
        try:
            api_url = ApiClient.normalise_url(api_url)
        except ApiError as exc:
            self._show_login_error(str(exc))
            return

        self._set_login_busy(True)
        self._show_login_error("")

        def worker() -> None:
            try:
                client = ApiClient(api_url)
                data = client.login(email, password, socket.gethostname())
                apply_schedule_payload(data.get("schedule"), self.db)
                self.db.set_setting("api_url", api_url)
                self.db.set_setting("api_token", data["token"])
                self.db.set_setting("user_json", json.dumps(data["user"]))
                self.db.set_setting("last_email", email)
                self.root.after(0, lambda: self._login_success(api_url, data["token"], data["user"]))
            except ApiError as exc:
                self.root.after(0, lambda: self._login_failed(str(exc)))
            except Exception as exc:  # pragma: no cover
                log.exception("Login failed")
                self.root.after(0, lambda: self._login_failed(f"Unexpected error: {exc}"))

        threading.Thread(target=worker, daemon=True).start()

    def _login_failed(self, message: str) -> None:
        self._set_login_busy(False)
        self._show_login_error(message)

    def _login_success(self, api_url: str, token: str, user: dict) -> None:
        self._set_login_busy(False)
        self.api_url = api_url
        self.token = token
        self.user = user
        self.sync.trigger()
        self._build_main()

    def _try_auto_login(self) -> None:
        if not self.token or not self.user:
            return
        cached_user = self.user
        api_url = self.api_url
        token = self.token

        def worker() -> None:
            try:
                client = ApiClient(api_url, token)
                data = client.me()
                apply_schedule_payload(data.get("schedule"), self.db)
                self.db.set_setting("user_json", json.dumps(data["user"]))
                self.root.after(0, lambda: self._auto_login_done(api_url, data["user"], None))
            except ApiError as exc:
                if exc.is_auth_error:
                    self.db.set_setting("api_token", None)
                    self.root.after(0, lambda: self._auto_login_done(api_url, None, "Your saved session expired. Please sign in again."))
                else:
                    self.root.after(0, lambda: self._auto_login_done(api_url, cached_user, None, offline=True))
            except Exception:
                self.root.after(0, lambda: self._auto_login_done(api_url, cached_user, None, offline=True))

        threading.Thread(target=worker, daemon=True).start()

    def _auto_login_done(self, api_url: str, user: dict | None, message: str | None, offline: bool = False) -> None:
        if user:
            self.api_url = api_url
            self.user = user
            self._build_main()
            if offline:
                self._append_log("Starting offline. Data will sync when the office server is reachable.")
        else:
            self.token = None
            self._build_login(message)

    # ------------------------------------------------------------------ main view
    def _build_main(self) -> None:
        self.view = "main"
        self._clear()
        self.root.configure(bg=BG)

        top = tk.Frame(self.root, bg=DARK, height=84)
        top.pack(fill="x")
        top.pack_propagate(False)

        brand = tk.Frame(top, bg=DARK)
        brand.pack(side="left", padx=26, pady=17)
        tk.Label(brand, text="OT", bg=GREEN, fg="#ffffff", font=(FONT, 16, "bold"), width=2, height=1).pack(side="left", padx=(0, 12))
        brand_text = tk.Frame(brand, bg=DARK)
        brand_text.pack(side="left")
        tk.Label(brand_text, text=APP_NAME, bg=DARK, fg="#ffffff", font=(FONT, 14, "bold")).pack(anchor="w")
        tk.Label(brand_text, text="ASIA/DHAKA · OFFICE TRACKER", bg=DARK, fg="#86a899", font=(FONT, 8, "bold")).pack(anchor="w")

        right = tk.Frame(top, bg=DARK)
        right.pack(side="right", padx=26, pady=14)
        self.top_clock = tk.Label(right, text="", bg=DARK, fg="#ffffff", font=(MONO, 13, "bold"))
        self.top_clock.pack(side="left", padx=(0, 18))
        user_box = tk.Frame(right, bg=DARK)
        user_box.pack(side="left", padx=(0, 14))
        name = (self.user or {}).get("name", "Staff")
        self.top_user = tk.Label(user_box, text=name, bg=DARK, fg="#dfece6", font=(FONT, 9, "bold"))
        self.top_user.pack(anchor="e")
        tk.Label(user_box, text="STAFF", bg=DARK, fg="#86a899", font=(FONT, 7, "bold")).pack(anchor="e")
        self._button(right, "Sign out", self._logout, bg=DARK_SOFT, fg="#dfece6", font=(FONT, 9, "bold"), padx=11, pady=7).pack(side="left")

        content = tk.Frame(self.root, bg=BG)
        content.pack(fill="both", expand=True, padx=24, pady=16)

        schedule = tk.Frame(content, bg=SURFACE, highlightbackground=BORDER, highlightthickness=1)
        schedule.pack(fill="x", pady=(0, 14))
        self.schedule_badge = tk.Label(schedule, text="", bg=GREEN_SOFT, fg=GREEN_DARK, font=(FONT, 8, "bold"), padx=10, pady=5)
        self.schedule_badge.pack(side="left", padx=16, pady=11)
        self.schedule_text = tk.Label(schedule, text="", bg=SURFACE, fg=MUTED, font=(FONT, 9))
        self.schedule_text.pack(side="left", padx=(0, 12))
        self.schedule_right = tk.Label(schedule, text="", bg=SURFACE, fg=MUTED, font=(FONT, 9, "bold"))
        self.schedule_right.pack(side="right", padx=16)

        body = tk.Frame(content, bg=BG)
        body.pack(fill="both", expand=True)
        body.grid_columnconfigure(0, weight=58, uniform="body")
        body.grid_columnconfigure(1, weight=42, uniform="body")
        body.grid_rowconfigure(0, weight=1)

        left = tk.Frame(body, bg=BG)
        left.grid(row=0, column=0, sticky="nsew", padx=(0, 14))
        right_col = tk.Frame(body, bg=BG)
        right_col.grid(row=0, column=1, sticky="nsew", padx=(14, 0))

        # Session card
        session = self._card(left)
        session.pack(fill="x")
        head = tk.Frame(session, bg=SURFACE)
        head.pack(fill="x", padx=24, pady=(20, 0))
        tk.Label(head, text="CURRENT SESSION", bg=SURFACE, fg=MUTED, font=(FONT, 8, "bold")).pack(side="left")
        self.status_pill = tk.Label(head, text="NOT TRACKING", bg="#eef2ef", fg=MUTED, font=(FONT, 8, "bold"), padx=9, pady=4)
        self.status_pill.pack(side="right")
        self.session_status = tk.Label(session, text="Ready to start", bg=SURFACE, fg=TEXT, font=(FONT, 17, "bold"), anchor="w")
        self.session_status.pack(fill="x", padx=24, pady=(10, 0))
        self.timer_label = tk.Label(session, text="00:00:00", bg=SURFACE, fg=TEXT, font=(MONO, 47, "bold"), anchor="w")
        self.timer_label.pack(fill="x", padx=24, pady=(0, 2))
        self.session_sub = tk.Label(session, text="Active 00:00:00   ·   Idle 00:00:00   ·   Namaz 00:00:00", bg=SURFACE, fg=MUTED, font=(FONT, 9), anchor="w")
        self.session_sub.pack(fill="x", padx=24)
        self.idle_hint = tk.Label(session, text="", bg=SURFACE, fg=MUTED, font=(FONT, 9), anchor="w")
        self.idle_hint.pack(fill="x", padx=24, pady=(7, 0))

        controls = tk.Frame(session, bg=SURFACE)
        controls.pack(fill="x", padx=24, pady=(17, 20))
        self.start_button = self._button(controls, "▶  Start tracking", self._start, bg=GREEN)
        self.start_button.pack(side="left", padx=(0, 8))
        self.stop_button = self._button(controls, "■  Stop", self._stop, bg=RED_SOFT, fg=RED)
        self.stop_button.pack(side="left", padx=(0, 8))
        asor_rule = config.BREAK_TYPES.get("asor", {"label": "Asor", "minutes": 20})
        maghrib_rule = config.BREAK_TYPES.get("maghrib", {"label": "Maghrib", "minutes": 15})
        self.asor_button = self._button(controls, f"{asor_rule['label']} · {asor_rule['minutes']}m", lambda: self._start_break("asor"), bg=SOFT, fg=TEXT, highlightbackground=BORDER, highlightthickness=1)
        self.asor_button.pack(side="left", padx=(0, 8))
        self.maghrib_button = self._button(controls, f"{maghrib_rule['label']} · {maghrib_rule['minutes']}m", lambda: self._start_break("maghrib"), bg=SOFT, fg=TEXT, highlightbackground=BORDER, highlightthickness=1)
        self.maghrib_button.pack(side="left", padx=(0, 8))
        self.end_break_button = self._button(controls, "End break", self._end_break, bg=ORANGE_SOFT, fg=ORANGE)
        self.end_break_button.pack(side="left")

        # Metrics
        metrics_head = tk.Frame(right_col, bg=BG)
        metrics_head.pack(fill="x", pady=(0, 8))
        tk.Label(metrics_head, text="LIVE METRICS", bg=BG, fg=MUTED, font=(FONT, 8, "bold")).pack(side="left")
        self.shot_hint = tk.Label(metrics_head, text="", bg=BG, fg=MUTED, font=(FONT, 8))
        self.shot_hint.pack(side="right")

        metrics = tk.Frame(right_col, bg=BG)
        metrics.pack(fill="x")
        for col in range(2):
            metrics.grid_columnconfigure(col, weight=1, uniform="metrics")

        self.metric_vars: dict[str, tk.StringVar] = {}
        metric_specs = [
            ("active", "ACTIVE TIME", GREEN, GREEN_SOFT),
            ("idle", "IDLE TIME", ORANGE, ORANGE_SOFT),
            ("namaz", "NAMAZ BREAK", PURPLE, PURPLE_SOFT),
            ("screens", "SCREENSHOTS", BLUE, BLUE_SOFT),
            ("keys", "KEYSTROKES", GREEN, GREEN_SOFT),
            ("clicks", "MOUSE CLICKS", BLUE, BLUE_SOFT),
        ]
        for index, (key, title, color, soft) in enumerate(metric_specs):
            card = tk.Frame(metrics, bg=SURFACE, highlightbackground=BORDER, highlightthickness=1)
            card.grid(row=index // 2, column=index % 2, sticky="nsew", padx=(0, 8) if index % 2 == 0 else (8, 0), pady=(0, 10))
            tk.Label(card, text=title, bg=SURFACE, fg=MUTED, font=(FONT, 7, "bold")).pack(anchor="w", padx=14, pady=(11, 0))
            var = tk.StringVar(value="0")
            tk.Label(card, textvariable=var, bg=SURFACE, fg=color, font=(MONO, 18, "bold")).pack(anchor="w", padx=14, pady=(0, 1))
            note = {"active": "Productive time", "idle": f"After {config.IDLE_THRESHOLD_SECONDS // 60} min no input", "namaz": "Today total", "screens": f"Every {config.SCREENSHOT_INTERVAL_SECONDS // 60} minutes", "keys": "This session", "clicks": "This session"}[key]
            tk.Label(card, text=note, bg=SURFACE, fg=MUTED, font=(FONT, 7)).pack(anchor="w", padx=14, pady=(0, 10))
            self.metric_vars[key] = var

        # Sync card
        sync_card = self._card(right_col)
        sync_card.pack(fill="x", pady=(4, 0))
        sync_top = tk.Frame(sync_card, bg=SURFACE)
        sync_top.pack(fill="x", padx=16, pady=(12, 0))
        self.sync_dot = tk.Label(sync_top, text="●", bg=SURFACE, fg=MUTED, font=(FONT, 12, "bold"))
        self.sync_dot.pack(side="left")
        self.sync_label = tk.Label(sync_top, text="Connecting to office server…", bg=SURFACE, fg=TEXT, font=(FONT, 8, "bold"))
        self.sync_label.pack(side="left", padx=(6, 0))
        self._button(sync_top, "Sync now", self._sync_now, bg=SOFT, fg=TEXT, font=(FONT, 8, "bold"), padx=9, pady=4).pack(side="right")
        self.sync_meta = tk.Label(sync_card, text="", bg=SURFACE, fg=MUTED, font=(FONT, 7), anchor="w")
        self.sync_meta.pack(fill="x", padx=16, pady=(2, 11))

        # Bottom area
        bottom = tk.Frame(content, bg=BG)
        bottom.pack(fill="both", expand=True, pady=(14, 0))
        bottom.grid_columnconfigure(0, weight=62, uniform="bottom")
        bottom.grid_columnconfigure(1, weight=38, uniform="bottom")
        bottom.grid_rowconfigure(0, weight=1)

        activity_card = self._card(bottom)
        activity_card.grid(row=0, column=0, sticky="nsew", padx=(0, 14))
        act_head = tk.Frame(activity_card, bg=SURFACE)
        act_head.pack(fill="x", padx=16, pady=(11, 5))
        tk.Label(act_head, text="MINUTE ACTIVITY", bg=SURFACE, fg=MUTED, font=(FONT, 8, "bold")).pack(side="left")
        self.activity_meta = tk.Label(act_head, text="", bg=SURFACE, fg=MUTED, font=(FONT, 8))
        self.activity_meta.pack(side="right")

        tree_wrap = tk.Frame(activity_card, bg=SURFACE)
        tree_wrap.pack(fill="both", expand=True, padx=10, pady=(0, 8))
        style = ttk.Style(self.root)
        style.theme_use("clam")
        style.configure("Activity.Treeview", background=SURFACE, fieldbackground=SURFACE, foreground=TEXT, rowheight=23, font=(FONT, 8), borderwidth=0)
        style.configure("Activity.Treeview.Heading", background=SOFT, foreground=MUTED, font=(FONT, 7, "bold"), borderwidth=0, relief="flat")
        style.map("Activity.Treeview", background=[("selected", GREEN_SOFT)], foreground=[("selected", TEXT)])
        self.activity_tree = ttk.Treeview(
            tree_wrap,
            columns=("time", "keys", "clicks", "active", "idle", "kind"),
            show="headings",
            style="Activity.Treeview",
            height=6,
        )
        for column, label, width in (
            ("time", "MINUTE", 90),
            ("keys", "KEYS", 65),
            ("clicks", "CLICKS", 65),
            ("active", "ACTIVE", 70),
            ("idle", "IDLE", 70),
            ("kind", "TYPE", 70),
        ):
            self.activity_tree.heading(column, text=label)
            self.activity_tree.column(column, width=width, anchor="w", stretch=column == "time")
        self.activity_tree.pack(fill="both", expand=True)
        self.activity_tree.tag_configure("idle", foreground=ORANGE)
        self.activity_tree.tag_configure("active", foreground=TEXT)

        side = tk.Frame(bottom, bg=BG)
        side.grid(row=0, column=1, sticky="nsew", padx=(14, 0))

        info_card = self._card(side)
        info_card.pack(fill="x")
        tk.Label(info_card, text="OFFICE RULES", bg=SURFACE, fg=MUTED, font=(FONT, 8, "bold"), anchor="w").pack(fill="x", padx=16, pady=(11, 5))
        rules = [
            f"Window {index + 1} · {start.strftime('%H:%M')} – {end.strftime('%H:%M')}"
            for index, (start, end) in enumerate(config.WORK_WINDOWS)
        ]
        rules.extend([
            f"Idle threshold · {config.IDLE_THRESHOLD_SECONDS // 60} minutes without input",
            f"Screenshots · random once per {config.SCREENSHOT_INTERVAL_SECONDS // 60} minutes",
            " · ".join(f"{rule['label']} · {rule['minutes']} minutes" for rule in config.BREAK_TYPES.values()),
        ])
        for text in rules:
            tk.Label(info_card, text="•  " + text, bg=SURFACE, fg=MUTED, font=(FONT, 8), anchor="w").pack(fill="x", padx=16, pady=1)

        self.today_label = tk.Label(info_card, text="Today: 0m active · 0m idle · 0m namaz", bg=SURFACE, fg=TEXT, font=(FONT, 8, "bold"), anchor="w")
        self.today_label.pack(fill="x", padx=16, pady=(8, 4))

        self.autostart_button = self._button(info_card, "", self._toggle_autostart, bg=SOFT, fg=TEXT, font=(FONT, 8, "bold"), padx=9, pady=4)
        self.autostart_button.pack(anchor="w", padx=16, pady=(0, 12))

        log_card = self._card(side)
        log_card.pack(fill="both", expand=True, pady=(12, 0))
        tk.Label(log_card, text="ACTIVITY LOG", bg=SURFACE, fg=MUTED, font=(FONT, 8, "bold"), anchor="w").pack(fill="x", padx=16, pady=(9, 3))
        self.log_text = tk.Text(log_card, bg=SURFACE, fg=MUTED, font=(FONT, 7), relief="flat", bd=0, height=4, wrap="word", state="disabled")
        self.log_text.pack(fill="both", expand=True, padx=14, pady=(0, 8))

        self._refresh_autostart_button()
        self._append_log("Tracker ready. Office hours and idle rules are enforced.")
        if self.engine.resume_if_possible():
            self.sync.trigger()
        self._tick_loop()

    # ------------------------------------------------------------------ actions
    def _start(self) -> None:
        if self.engine.start_session():
            self.sync.trigger()
            self._append_log("Session started. Screenshot timer is running.")
        else:
            status = schedule_status()
            messagebox.showinfo(
                "Tracking unavailable",
                "Time tracking can only start inside your office windows:\n\n"
                + schedule_windows_multiline()
                + " (Asia/Dhaka)\n\n"
                + status.label,
                parent=self.root,
            )

    def _stop(self) -> None:
        snap = self.engine.snapshot()
        if snap["state"] not in ("running", "on_break"):
            return
        if not messagebox.askyesno("Stop tracking?", "Stop this work session now? The time will be saved and synced.", parent=self.root):
            return
        self.engine.stop_session()
        self.sync.trigger()
        self._append_log("Session stopped and queued for sync.")

    def _start_break(self, break_type: str) -> None:
        break_config = config.BREAK_TYPES[break_type]
        if not messagebox.askyesno(
            "Start namaz break?",
            f"{break_config['label']} break is {break_config['minutes']} minutes.\n\n"
            "Time tracking and screenshots will pause during the break.",
            parent=self.root,
        ):
            return
        if self.engine.start_break(break_type):
            self.sync.trigger()

    def _end_break(self) -> None:
        self.engine.end_break()
        self.sync.trigger()

    def _sync_now(self) -> None:
        self.sync.trigger()
        self._append_log("Manual sync requested.")

    def _toggle_autostart(self) -> None:
        try:
            if autostart.is_installed():
                autostart.uninstall()
                self._append_log("Windows auto-start disabled.")
            else:
                target = autostart.install(ROOT_DIR / "main.py")
                self._append_log(f"Windows auto-start enabled: {target.name}")
            self._refresh_autostart_button()
        except Exception as exc:
            messagebox.showerror("Auto-start", f"Could not update Windows auto-start:\n{exc}", parent=self.root)

    def _refresh_autostart_button(self) -> None:
        try:
            installed = autostart.is_installed()
            self.autostart_button.configure(
                text=("✓ Auto-start with Windows · On" if installed else "Enable auto-start with Windows"),
                fg=GREEN_DARK if installed else TEXT,
                bg=GREEN_SOFT if installed else SOFT,
            )
        except tk.TclError:
            pass

    def _logout(self) -> None:
        snap = self.engine.snapshot()
        if snap["state"] in ("running", "on_break"):
            if not messagebox.askyesno("Sign out?", "Tracking is running. Signing out will stop the session. Continue?", parent=self.root):
                return
            self.engine.stop_session()
            self.sync.trigger()
        token = self.token
        api_url = self.api_url
        self.token = None
        self.user = None
        self.db.set_setting("api_token", None)
        self.db.set_setting("user_json", None)
        if token:
            def worker() -> None:
                try:
                    ApiClient(api_url, token).logout()
                except Exception:
                    pass
            threading.Thread(target=worker, daemon=True).start()
        if self._tick_job:
            try:
                self.root.after_cancel(self._tick_job)
            except tk.TclError:
                pass
            self._tick_job = None
        self._build_login("Signed out. Sign in to resume tracking.")

    # ------------------------------------------------------------------ refresh
    def _tick_loop(self) -> None:
        if self.view != "main":
            return
        try:
            self.engine.tick()
            self._refresh_ui()
            self._drain_messages()
            now = datetime.now().timestamp()
            if now - self._last_activity_refresh >= 5:
                self._refresh_activity_table()
                self._last_activity_refresh = now
        except Exception as exc:  # pragma: no cover
            log.exception("UI tick failed")
            self._append_log(f"UI warning: {exc}")
        self._tick_job = self.root.after(1000, self._tick_loop)

    def _refresh_ui(self) -> None:
        snap = self.engine.snapshot()
        state = snap["state"]
        schedule = snap["schedule"]
        self.top_clock.configure(text=format_clock())

        if schedule.is_open:
            self.schedule_badge.configure(text="OFFICE OPEN", bg=GREEN_SOFT, fg=GREEN_DARK)
            self.schedule_text.configure(text=f"{schedule_windows_label()}  ·  Asia/Dhaka")
            self.schedule_right.configure(text="Tracking allowed now", fg=GREEN_DARK)
        else:
            self.schedule_badge.configure(text="OFFICE CLOSED", bg="#eef2ef", fg=MUTED)
            self.schedule_text.configure(text=f"{schedule_windows_label()}  ·  Asia/Dhaka")
            self.schedule_right.configure(text=f"Next start {format_local(schedule.next_start, '%d %b, %I:%M %p')}", fg=MUTED)

        if state == "running":
            status_text = "TRACKING"
            status_bg, status_fg = GREEN_SOFT, GREEN_DARK
            session_text = "Work session in progress"
            hint = f"Activity detected just now · idle starts after {config.IDLE_THRESHOLD_SECONDS // 60} minutes without input"
            hint_fg = MUTED
            if snap["is_idle"]:
                hint = f"Idle for {format_duration(snap['idle_age'])} — this time is being recorded as idle"
                hint_fg = ORANGE
            self.timer_label.configure(fg=TEXT)
        elif state == "on_break":
            status_text = "ON NAMAZ BREAK"
            status_bg, status_fg = ORANGE_SOFT, ORANGE
            label = config.BREAK_TYPES.get(snap["break_type"] or "", {}).get("label", "Namaz")
            remaining = 0
            if snap["break_ends_at"]:
                remaining = max(0, int((snap["break_ends_at"] - datetime.now(snap["break_ends_at"].tzinfo)).total_seconds()))
            session_text = f"{label} break · {format_duration(remaining, True)} remaining"
            hint = "Tracking and screenshots are paused. Press End break when finished."
            hint_fg = ORANGE
            self.timer_label.configure(fg=ORANGE)
        else:
            status_text = "NOT TRACKING"
            status_bg, status_fg = "#eef2ef", MUTED
            session_text = "Ready to start" if schedule.is_open else "Outside office hours"
            hint = "Click Start tracking during an allowed window." if schedule.is_open else schedule.label
            hint_fg = MUTED
            self.timer_label.configure(fg="#9aa9a1")

        self.status_pill.configure(text=status_text, bg=status_bg, fg=status_fg)
        self.session_status.configure(text=session_text)
        self.timer_label.configure(text=format_duration(snap["elapsed"], clock=True))
        self.session_sub.configure(
            text=(
                f"Active {format_duration(snap['active_seconds'], True)}   ·   "
                f"Idle {format_duration(snap['idle_seconds'], True)}   ·   "
                f"Namaz {format_duration(snap['break_seconds'], True)}"
            )
        )
        self.idle_hint.configure(text=hint, fg=hint_fg)

        self.start_button.configure(state="normal" if state == "idle" and schedule.is_open else "disabled")
        self.stop_button.configure(state="normal" if state in ("running", "on_break") else "disabled")
        self.asor_button.configure(state="normal" if state == "running" else "disabled")
        self.maghrib_button.configure(state="normal" if state == "running" else "disabled")
        self.end_break_button.configure(state="normal" if state == "on_break" else "disabled")

        self.metric_vars["active"].set(format_duration(snap["active_seconds"], True))
        self.metric_vars["idle"].set(format_duration(snap["idle_seconds"], True))
        self.metric_vars["namaz"].set(format_duration(snap["break_seconds"], True))
        self.metric_vars["screens"].set(str(snap["screenshot_count"]))
        session_uuid = self.engine.session_uuid
        keys = clicks = 0
        if session_uuid:
            rows = self.db.recent_activity(session_uuid, 200)
            keys = sum(int(row["keystrokes"]) for row in rows)
            clicks = sum(int(row["clicks"]) for row in rows)
        self.metric_vars["keys"].set(f"{keys:,}")
        self.metric_vars["clicks"].set(f"{clicks:,}")

        if state == "running" and snap["next_screenshot_at"]:
            remaining = max(0, int((snap["next_screenshot_at"] - datetime.now(snap["next_screenshot_at"].tzinfo)).total_seconds()))
            self.shot_hint.configure(text=f"Next screenshot in {format_duration(remaining)}")
        elif state == "on_break":
            self.shot_hint.configure(text="Screenshots paused")
        else:
            self.shot_hint.configure(text="Screenshots paused")

        today = self.db.today_summary(dhaka_now().date())
        self.today_label.configure(
            text=(
                f"Today: {format_duration(today['active_seconds'])} active · "
                f"{format_duration(today['idle_seconds'])} idle · "
                f"{format_duration(today['break_seconds'])} namaz"
            )
        )

        sync = self.sync.status()
        if sync["last_error"]:
            self.sync_dot.configure(fg=RED)
            self.sync_label.configure(text="Sync needs attention", fg=RED)
            self.sync_meta.configure(text=sync["last_error"][:95])
        elif sync["online"]:
            self.sync_dot.configure(fg=GREEN)
            self.sync_label.configure(text="Connected to office server", fg=GREEN_DARK)
            self.sync_meta.configure(text=f"Last sync: {format_local(sync['last_sync_at'], '%I:%M:%S %p')} · {self.api_url}")
        else:
            self.sync_dot.configure(fg=ORANGE)
            self.sync_label.configure(text="Waiting for office server", fg=ORANGE)
            self.sync_meta.configure(text="Data is stored locally and will retry automatically.")

    def _refresh_activity_table(self) -> None:
        for item in self.activity_tree.get_children():
            self.activity_tree.delete(item)

        session_uuid = self.engine.session_uuid
        if not session_uuid:
            latest = self.db.latest_session()
            session_uuid = latest["uuid"] if latest else None
        if not session_uuid:
            self.activity_meta.configure(text="No activity yet")
            return

        rows = self.db.recent_activity(session_uuid, 24)
        self.activity_meta.configure(text=f"{len(rows)} minute(s) recorded")
        for row in rows:
            moment = parse_iso(row["minute_start"])
            minute = format_local(moment, "%I:%M %p") if moment else row["minute_start"]
            kind = "Idle" if row["is_idle"] else "Active"
            self.activity_tree.insert(
                "",
                "end",
                values=(
                    minute,
                    f"{int(row['keystrokes']):,}",
                    f"{int(row['clicks']):,}",
                    f"{int(row['active_seconds'])}s",
                    f"{int(row['idle_seconds'])}s",
                    kind,
                ),
                tags=("idle" if row["is_idle"] else "active",),
            )

    def _append_log(self, message: str) -> None:
        try:
            self.log_text.configure(state="normal")
            self.log_text.insert("end", message + "\n")
            self.log_text.see("end")
            line_count = int(self.log_text.index("end-1c").split(".")[0])
            if line_count > 120:
                self.log_text.delete("1.0", f"{line_count - 100}.0")
            self.log_text.configure(state="disabled")
        except (tk.TclError, AttributeError):
            pass

    def _drain_messages(self) -> None:
        for message in self.engine.pop_messages():
            self._append_log(message)

    # ------------------------------------------------------------------ close
    def on_close(self) -> None:
        snap = self.engine.snapshot()
        if snap["state"] in ("running", "on_break"):
            if not messagebox.askyesno(
                "Close tracker?",
                "Tracking is still running. Closing will stop this session and save it locally for the next sync.\n\nClose anyway?",
                parent=self.root,
            ):
                return
            self.engine.stop_session("App closed")
            self.sync.trigger()
        self.engine.stop_listeners()
        self.sync.stop()
        try:
            self.db.close()
        except Exception:
            pass
        self.root.destroy()
