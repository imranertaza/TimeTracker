@echo off
title Office Time Tracker - local demo
cd /d "%~dp0web"
start "Office Time Tracker API" /min cmd /c "php artisan serve --host=127.0.0.1 --port=8000"
timeout /t 2 /nobreak >nul
cd /d "%~dp0desktop"
start "" pythonw.exe main.py
exit /b 0
