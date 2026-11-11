"""Office Time Tracker desktop entry point."""
from __future__ import annotations

import ctypes
import logging
import logging.handlers
import sys
import traceback
import tkinter as tk
from tkinter import messagebox

from config import APP_NAME, LOG_PATH
from ui import TrackerApp

MUTEX_NAME = "Global\\OfficeTimeTracker-Dhaka-8A4C2F1D"
_mutex_handle = None


def configure_logging() -> None:
    handler = logging.handlers.RotatingFileHandler(
        LOG_PATH,
        maxBytes=1_500_000,
        backupCount=3,
        encoding="utf-8",
    )
    formatter = logging.Formatter("%(asctime)s %(levelname)s %(name)s: %(message)s")
    handler.setFormatter(formatter)
    root = logging.getLogger()
    root.setLevel(logging.INFO)
    root.addHandler(handler)


def enable_dpi_awareness() -> None:
    if not hasattr(ctypes, "windll"):
        return
    try:
        ctypes.windll.shcore.SetProcessDpiAwareness(2)
    except Exception:
        try:
            ctypes.windll.user32.SetProcessDPIAware()
        except Exception:
            pass


def acquire_single_instance() -> bool:
    global _mutex_handle
    if not hasattr(ctypes, "windll"):
        return True
    _mutex_handle = ctypes.windll.kernel32.CreateMutexW(None, False, MUTEX_NAME)
    last_error = ctypes.windll.kernel32.GetLastError()
    if last_error == 183:  # ERROR_ALREADY_EXISTS
        return False
    return True


def report_callback_exception(exc_type, exc_value, exc_tb) -> None:
    logging.getLogger(__name__).error(
        "Unhandled UI exception:\n%s",
        "".join(traceback.format_exception(exc_type, exc_value, exc_tb)),
    )
    try:
        messagebox.showerror(
            APP_NAME,
            "An unexpected error occurred. The details were written to:\n\n"
            f"{LOG_PATH}\n\n{exc_value}",
        )
    except Exception:
        pass


def main() -> int:
    configure_logging()
    enable_dpi_awareness()

    if not acquire_single_instance():
        root = tk.Tk()
        root.withdraw()
        messagebox.showinfo(APP_NAME, "Office Time Tracker is already running.")
        root.destroy()
        return 0

    root = tk.Tk()
    root.report_callback_exception = report_callback_exception
    try:
        TrackerApp(root)
        root.mainloop()
        return 0
    except Exception:
        logging.getLogger(__name__).exception("Fatal application error")
        try:
            messagebox.showerror(
                APP_NAME,
                "The tracker could not start. Details were written to:\n\n"
                f"{LOG_PATH}\n\n{traceback.format_exc(limit=2)}",
            )
        except Exception:
            pass
        return 1


if __name__ == "__main__":
    sys.exit(main())
