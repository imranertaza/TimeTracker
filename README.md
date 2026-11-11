# Office Time Tracker

A working office time-tracking system with a **Python desktop tracker**, a **Laravel web admin panel**, and a **secured REST API** connecting them.

Built for an office in **Dhaka, Bangladesh (Asia/Dhaka, UTC+6)**.

---

## 1. What is included

### Python desktop tracker (`desktop/`)

- Starts when Windows starts (optional auto-start shortcut, no admin rights required).
- Staff sign in with the account created by the admin.
- **Start** button starts a work session. Tracking is blocked outside the office windows.
- **Work windows enforced in both the desktop app and the API.** Defaults are:
  - 09:00 AM – 01:15 PM
  - 02:15 PM – 06:30 PM
  - The admin can change the global windows or give any staff member a custom office time from the web panel.
- Automatically stops the session when the current office window closes.
- Counts **every keyboard press and mouse click** (counts only — no key content is stored).
- Uses the Windows last-input timer to detect inactivity.
- After **5 minutes without input**, time is recorded as **idle** and shown separately from active time.
- Takes a **random screenshot once in every 10-minute block** while tracking.
- Screenshots pause during namaz breaks and are uploaded to the Laravel server.
- **Asor namaz break: 20 minutes. Maghrib namaz break: 15 minutes.** These allowances are configurable by the admin.
- Break time pauses active/idle tracking and is stored separately.
- Works offline: activity is queued in local SQLite and retried automatically.
- Shows live session timer, active time, idle time, namaz time, keystrokes, clicks, screenshots and sync status.
- Auto-start can be toggled from the app footer.

### Laravel web panel (`web/`)

- Admin and staff logins.
- **Admin dashboard:** live online staff, exact working time, idle time, namaz totals, keyboard/mouse counts, screenshots and last heartbeat.
- **Office time settings:** one global office schedule for every staff member, plus an optional custom office schedule for any individual staff member. Desktop apps receive the schedule on login and on every sync.
- **Daily reports:** filter by date and staff member; exact working, idle, Asor and Maghrib namaz; CSV export.
- **Monthly reports:** exact working, idle and namaz totals for every staff member, daily breakdown, and CSV export.
- **Monthly total-time formula:** the admin can generate the monthly total as exact working only, exact + idle, exact + namaz, or exact + idle + namaz. The chosen formula is also used on staff reports.
- **Staff management:** create, edit, deactivate, reset password; set a custom office time per staff member; deactivation revokes the desktop API token.
- **Staff dashboard:** personal exact working/idle/namaz totals, 7-day chart, session history, breaks and screenshots.
- **Staff reports:** each staff member can view their own daily and monthly exact working, idle, namaz and total time.
- **Session detail page:** minute-by-minute activity and all screenshots.
- All screenshots are private and served only to the owner or an admin.
- API tokens are stored as SHA-256 hashes, not plain text.

---

## 2. Quick start on this PC

The project is already installed. Start everything with:

```bat
D:\python_software\scripts\start_demo.bat
```

This starts the local Laravel server at `http://127.0.0.1:8000` and opens the Python tracker.

### Demo accounts

| Role | Email | Password |
|---|---|---|
| Admin | `admin@officetracker.test` | `Admin@12345` |
| Staff | `staff@officetracker.test` | `Staff@12345` |
| Staff 2 | `staff2@officetracker.test` | `Staff@12345` |

**Change these passwords before real use.**

### First-time setup on another PC

```powershell
powershell -ExecutionPolicy Bypass -File D:\python_software\scripts\setup_windows.ps1
```

The setup script installs Python packages, Laravel dependencies, prepares the database and installs Windows auto-start.

To install auto-start without the local web server (recommended when Laravel is hosted on a central server):

```powershell
powershell -ExecutionPolicy Bypass -File D:\python_software\scripts\install_autostart.ps1
```

To remove auto-start:

```powershell
powershell -ExecutionPolicy Bypass -File D:\python_software\scripts\uninstall_autostart.ps1
```

---

## 3. Office deployment architecture

For a real office, run Laravel on **one central server** and install only the Python tracker on each staff PC.

```
Staff Windows PCs                     Office server / NAS
┌─────────────────────┐               ┌──────────────────────────┐
│ Python tracker      │  HTTPS/API    │ Laravel 11 web panel     │
│ SQLite offline queue│ ────────────► │ MySQL / SQLite database  │
│ Screenshots + time  │               │ Admin + staff dashboards │
└─────────────────────┘               └──────────────────────────┘
```

### Server setup (recommended)

1. Copy the `web/` folder to the server.
2. Create a MySQL database and update `web/.env`:
   ```env
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://tracker.your-office.com
   APP_TIMEZONE=UTC
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_DATABASE=office_tracker
   DB_USERNAME=...
   DB_PASSWORD=...
   ```
3. Run:
   ```bash
   composer install --no-dev --optimize-autoloader
   php artisan key:generate
   php artisan migrate --force
   php artisan db:seed --force
   php artisan optimize
   ```
4. Point Apache/Nginx at `web/public` and enable HTTPS.
5. On each staff PC, sign in to the desktop app with the server URL:
   ```
   https://tracker.your-office.com/api/v1
   ```
6. Install Windows auto-start **without** `-IncludeWebServer`.

For a LAN-only demo, run the server with:

```bat
scripts\run_web.bat
```

This listens on `0.0.0.0:8000`, so staff PCs can use `http://SERVER-IP:8000/api/v1`.

---

## 4. How time is calculated

- The desktop app sends one activity row per minute:
  - keystrokes, clicks
  - active seconds
  - idle seconds
  - idle/active classification
- Active and idle are mutually exclusive. A minute can contain both (for example, 30 active seconds then 30 idle seconds).
- Idle starts **after** five minutes with no keyboard or mouse input. The first five minutes are treated as active; after that, seconds are recorded as idle.
- Break time is **not** counted as active or idle.
- The Laravel server recalculates session totals from synced minute rows and break rows, so a modified client cannot simply inflate totals.
- Daily reports use Asia/Dhaka day boundaries even though timestamps are stored in UTC.

### Monthly total-time formula

Exact working time is the active keyboard/mouse time. The admin selects the formula
used for monthly totals:

```
total = exact working time
total = exact working time + idle time
total = exact working time + namaz break time
total = exact working time + idle time + namaz break time
```

The selected formula is saved as the default and is used on staff monthly reports.
The admin can change it while generating any monthly report.

---

## 5. Screenshot behaviour

- A random moment is selected inside every 10-minute block while a session is running.
- Screenshots are paused during namaz breaks and outside tracking.
- Images are compressed to JPEG and stored locally first:
  ```
  %LOCALAPPDATA%\OfficeTimeTracker\screenshots\YYYY-MM-DD\
  ```
- They are uploaded to:
  ```
  web/storage/app/screenshots/{user_id}/{date}/{session_uuid}/
  ```
- Only the staff member and admins can open them through the web panel.

**Important:** screenshot monitoring may capture personal or confidential information. Inform staff, obtain consent where required, and publish a clear workplace monitoring policy before enabling it.

---

## 6. Windows auto-start

The auto-start shortcut is placed in the current user's Startup folder:

```
%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup\Office Time Tracker.lnk
```

- `install_autostart.ps1` starts only the Python tracker.
- `install_autostart.ps1 -IncludeWebServer` also starts the local Laravel demo server.
- The app footer also has an **Enable auto-start with Windows** button.

The app uses a Windows mutex, so a second copy will not open.

---

## 7. Project structure

```
D:\python_software\
├── desktop\
│   ├── main.py              # entry point, logging, single-instance lock
│   ├── ui.py                # Tkinter login + tracker dashboard
│   ├── engine.py            # input counts, idle split, breaks, screenshots
│   ├── sync_service.py      # background offline queue + API sync
│   ├── api_client.py        # dependency-free HTTP client
│   ├── database.py          # local SQLite queue
│   ├── config.py            # Dhaka time, office windows, break rules
│   ├── autostart.py         # Windows Startup shortcut
│   └── requirements.txt
├── web\                     # Laravel 11 admin panel + API
│   ├── app\Http\Controllers\Api\
│   ├── app\Http\Controllers\Admin\
│   ├── app\Services\ReportService.php
│   ├── app\Support\WorkSchedule.php
│   ├── database\migrations\
│   └── resources\views\
├── scripts\
│   ├── setup_windows.ps1
│   ├── install_autostart.ps1
│   ├── uninstall_autostart.ps1
│   ├── start_demo.bat
│   ├── start_demo.vbs
│   ├── run_web.bat
│   └── run_tracker.bat
├── docs\API.md
└── README.md
```

---

## 8. API overview

Base URL: `http://127.0.0.1:8000/api/v1`

| Method | Endpoint | Purpose |
|---|---|---|
| POST | `/auth/login` | Desktop login, returns Bearer token |
| GET | `/auth/me` | Validate token, get schedule and current session |
| POST | `/auth/logout` | Revoke desktop token |
| GET | `/sessions/current` | Get the open session |
| POST | `/sessions/start` | Start a session (office-window enforced) |
| POST | `/sessions/{uuid}/sync` | Upload activity minutes and breaks |
| POST | `/sessions/{uuid}/stop` | Stop the session |
| POST | `/breaks/start` | Start Asor/Maghrib break |
| POST | `/breaks/{uuid}/end` | End a break |
| POST | `/screenshots` | Upload a screenshot (multipart) |

Full request/response examples are in [`docs/API.md`](docs/API.md).

---

## 9. Testing

Laravel:

```bat
cd D:\python_software\web
php artisan test
```

Desktop smoke tests (no GUI):

```bat
cd D:\python_software\desktop
python -m compileall -q .
python -c "import config, database, engine, api_client, sync_service, ui, autostart; print('ok')"
```

---

## 10. Security and production checklist

- Change the seeded admin/staff passwords.
- Use HTTPS for the Laravel server.
- Set `APP_DEBUG=false` and `APP_ENV=production`.
- Keep `web/storage/app/screenshots` outside public web access (it already is).
- Back up the database and screenshot storage.
- Restrict the admin panel to trusted networks or add VPN/2FA.
- Add a written employee monitoring policy and obtain consent.
- The desktop API token is stored locally in the SQLite settings table. For higher-security deployments, wrap it with Windows DPAPI.
- The local SQLite database and screenshots are under `%LOCALAPPDATA%\OfficeTimeTracker\`.
- Rate limiting is enabled on desktop login and web login.
- Deactivating a staff account revokes its API token immediately.

---

## 11. Useful commands

Reset demo database and accounts:

```bat
cd D:\python_software\web
php artisan migrate:fresh --seed
```

View the web panel:

```
http://127.0.0.1:8000
```

Desktop logs:

```
%LOCALAPPDATA%\OfficeTimeTracker\tracker.log
```

Local tracker database:

```
%LOCALAPPDATA%\OfficeTimeTracker\tracker.db
```

---

## 12. Notes / next steps

This is a complete working foundation for office time tracking. Natural next additions:

- payroll and hourly-rate calculations
- overtime approvals and leave management
- task/project selection per session
- manager comments on screenshots
- WhatsApp/email daily summaries
- mobile app or PWA
- Windows service deployment for the Laravel API
- Active Directory / single sign-on
- automated backups and retention policy for screenshots
