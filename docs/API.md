# Office Time Tracker API

Base URL (local demo):

```
http://127.0.0.1:8000/api/v1
```

All authenticated requests need:

```http
Authorization: Bearer <token>
Accept: application/json
```

Timestamps are ISO 8601. The server stores UTC and applies Asia/Dhaka rules for office windows and reports.

---

## 1. Login

```http
POST /auth/login
Content-Type: application/json
```

```json
{
  "email": "staff@officetracker.test",
  "password": "Staff@12345",
  "device_name": "OFFICE-PC-01"
}
```

Response `200`:

```json
{
  "message": "Login successful.",
  "token": "80-character-token",
  "user": {
    "id": 2,
    "name": "Demo Staff",
    "email": "staff@officetracker.test",
    "role": "staff",
    "timezone": "Asia/Dhaka"
  },
  "server_time": "2026-11-09T16:00:00+06:00",
  "schedule": {
    "timezone": "Asia/Dhaka",
    "windows": [
      {"start": "09:00", "end": "13:15", "start_minutes": 540, "end_minutes": 795},
      {"start": "14:15", "end": "18:30", "start_minutes": 855, "end_minutes": 1110}
    ],
    "idle_threshold_minutes": 5,
    "screenshot_interval_minutes": 10,
    "breaks": {
      "asor": {"label": "Asor Namaz", "minutes": 20},
      "maghrib": {"label": "Maghrib Namaz", "minutes": 15}
    },
    "status": {
      "is_open": true,
      "window": {"start": "09:00", "end": "13:15"},
      "starts_at": "2026-11-09 09:00",
      "ends_at": "2026-11-09 13:15",
      "next_start": null
    }
  }
}
```

Logging in invalidates the previous desktop token for that user.

The `schedule` object is per user. If an admin has set a custom office time for
this staff member, `schedule.windows` contains those custom windows. Otherwise
it contains the global office windows. Break allowances, idle threshold and
screenshot interval come from the global office settings.

---

## 2. Validate token / get schedule

```http
GET /auth/me
```

Response:

```json
{
  "user": {...},
  "server_time": "2026-11-09T16:00:00+06:00",
  "schedule": {...},
  "current_session": null
}
```

---

## 3. Logout

```http
POST /auth/logout
```

Revokes the current token.

---

## 4. Start a session

```http
POST /sessions/start
Content-Type: application/json
```

```json
{
  "uuid": "0e5b7c96-8a7a-4e29-a62b-97b85d6e5b84",
  "started_at": "2026-11-09T04:00:00Z",
  "device_name": "OFFICE-PC-01"
}
```

- `started_at` must fall inside the staff member's office windows (global or custom) in Asia/Dhaka.
- The client generates the UUID, so offline sessions can be retried idempotently.
- Any older open session is closed at the new start time.
- Returns `422` outside office hours.

Response `201`:

```json
{
  "message": "Session started.",
  "session": {
    "uuid": "0e5b7c96-8a7a-4e29-a62b-97b85d6e5b84",
    "started_at": "2026-11-09T04:00:00+00:00",
    "ended_at": null,
    "status": "running",
    "active_seconds": 0,
    "idle_seconds": 0,
    "break_seconds": 0
  },
  "schedule": {...}
}
```

---

## 5. Sync minute activity and breaks

```http
POST /sessions/{uuid}/sync
Content-Type: application/json
```

```json
{
  "status": "running",
  "activity": [
    {
      "minute_start": "2026-11-09T04:00:00Z",
      "keystrokes": 35,
      "clicks": 7,
      "active_seconds": 60,
      "idle_seconds": 0,
      "is_idle": false
    },
    {
      "minute_start": "2026-11-09T04:01:00Z",
      "keystrokes": 0,
      "clicks": 0,
      "active_seconds": 0,
      "idle_seconds": 60,
      "is_idle": true
    }
  ],
  "breaks": [
    {
      "uuid": "7b30d28a-b5f4-4f3e-a6e4-86af03e43811",
      "type": "asor",
      "started_at": "2026-11-09T04:05:00Z",
      "ended_at": "2026-11-09T04:25:00Z",
      "duration_seconds": 1200,
      "completed": true
    }
  ]
}
```

Notes:

- Activity rows are idempotent per `(session, minute_start)`.
- `active_seconds` and `idle_seconds` are capped at 60.
- Break duration is capped at the configured allowance (Asor 1200 seconds, Maghrib 900 seconds).
- The server recalculates session totals from all activity and break rows.

Response:

```json
{
  "message": "Session synced.",
  "session": {
    "uuid": "...",
    "status": "running",
    "active_seconds": 60,
    "idle_seconds": 60,
    "break_seconds": 1200
  },
  "server_time": "2026-11-09T16:30:00+06:00"
}
```

---

## 6. Stop a session

```http
POST /sessions/{uuid}/stop
Content-Type: application/json
```

```json
{
  "ended_at": "2026-11-09T05:30:00Z"
}
```

Any open break is closed and capped at its allowance.

---

## 7. Current open session

```http
GET /sessions/current
```

Returns the current `running` or `paused` session, or `null`.

---

## 8. Namaz breaks

### Start

```http
POST /breaks/start
Content-Type: application/json
```

```json
{
  "uuid": "7b30d28a-b5f4-4f3e-a6e4-86af03e43811",
  "session_uuid": "0e5b7c96-8a7a-4e29-a62b-97b85d6e5b84",
  "type": "asor",
  "started_at": "2026-11-09T04:05:00Z"
}
```

`type` is `asor` or `maghrib`. The session status becomes `paused`.

### End

```http
POST /breaks/{uuid}/end
Content-Type: application/json
```

```json
{
  "ended_at": "2026-11-09T04:25:00Z"
}
```

The actual duration is capped at the configured allowance.

---

## 9. Upload a screenshot

```http
POST /screenshots
Content-Type: multipart/form-data
```

Fields:

| Field | Type | Description |
|---|---|---|
| `session_uuid` | text | Work session UUID |
| `taken_at` | text | ISO timestamp |
| `screenshot` | file | JPG, PNG or WebP, max 10 MB |

Response `201`:

```json
{
  "message": "Screenshot uploaded.",
  "screenshot": {
    "id": 12,
    "taken_at": "2026-11-09T04:03:00+00:00",
    "url": "http://127.0.0.1:8000/screenshots/12"
  }
}
```

The screenshot URL requires an authenticated web session; staff can only see their own, admins can see all.

---

## 10. Error format

Validation errors return `422`:

```json
{
  "message": "Tracking can only start between 09:00-13:15 or 14:15-18:30 Bangladesh time.",
  "errors": {
    "started_at": [
      "Tracking can only start between 09:00-13:15 or 14:15-18:30 Bangladesh time."
    ]
  }
}
```

Authentication errors return `401`:

```json
{
  "message": "Invalid or expired authentication token."
}
```
