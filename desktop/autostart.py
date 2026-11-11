"""Windows startup shortcut management (no admin rights required)."""
from __future__ import annotations

import os
import subprocess
import sys
from pathlib import Path

SHORTCUT_NAME = "Office Time Tracker.lnk"


def startup_folder() -> Path:
    appdata = os.environ.get("APPDATA")
    if appdata:
        return Path(appdata) / "Microsoft" / "Windows" / "Start Menu" / "Programs" / "Startup"
    return Path.home() / "AppData" / "Roaming" / "Microsoft" / "Windows" / "Start Menu" / "Programs" / "Startup"


def shortcut_path() -> Path:
    return startup_folder() / SHORTCUT_NAME


def is_installed() -> bool:
    return shortcut_path().exists()


def pythonw_path() -> Path:
    executable = Path(sys.executable)
    candidate = executable.with_name("pythonw.exe")
    return candidate if candidate.exists() else executable


def _powershell(script: str) -> None:
    flags = 0
    if hasattr(subprocess, "CREATE_NO_WINDOW"):
        flags = subprocess.CREATE_NO_WINDOW
    completed = subprocess.run(
        ["powershell", "-NoProfile", "-ExecutionPolicy", "Bypass", "-Command", script],
        capture_output=True,
        text=True,
        creationflags=flags,
        timeout=30,
    )
    if completed.returncode != 0:
        raise RuntimeError(completed.stderr.strip() or completed.stdout.strip() or "PowerShell failed")


def install(main_script: str | Path) -> Path:
    target = shortcut_path()
    target.parent.mkdir(parents=True, exist_ok=True)
    script_path = Path(main_script).resolve()
    working_dir = script_path.parent
    python = pythonw_path()
    escaped_target = str(target).replace("'", "''")
    escaped_python = str(python).replace("'", "''")
    escaped_script = str(script_path).replace("'", "''")
    escaped_workdir = str(working_dir).replace("'", "''")
    script = (
        "$ws = New-Object -ComObject WScript.Shell; "
        f"$sc = $ws.CreateShortcut('{escaped_target}'); "
        f"$sc.TargetPath = '{escaped_python}'; "
        f"$sc.Arguments = '\"{escaped_script}\"'; "
        f"$sc.WorkingDirectory = '{escaped_workdir}'; "
        "$sc.WindowStyle = 1; "
        "$sc.Description = 'Office Time Tracker - Dhaka'; "
        "$sc.Save()"
    )
    _powershell(script)
    return target


def uninstall() -> None:
    target = shortcut_path()
    if target.exists():
        target.unlink()
