"""Small dependency-free HTTP client for the Laravel API."""
from __future__ import annotations

import json
import mimetypes
import socket
import urllib.error
import urllib.parse
import urllib.request
import uuid as uuid_module
from pathlib import Path
from typing import Any


class ApiError(Exception):
    def __init__(self, message: str, status: int | None = None, payload: dict | None = None):
        super().__init__(message)
        self.status = status
        self.payload = payload or {}

    @property
    def is_auth_error(self) -> bool:
        return self.status in (401, 403)


class ApiClient:
    def __init__(self, base_url: str, token: str | None = None, timeout: int = 18):
        self.base_url = base_url.rstrip("/")
        self.token = token
        self.timeout = timeout

    # ------------------------------------------------------------------ plumbing
    def _url(self, path: str) -> str:
        return f"{self.base_url}/{path.lstrip('/')}"

    def _request(self, method: str, path: str, payload: dict | None = None) -> dict:
        body = None
        headers = {"Accept": "application/json"}
        if payload is not None:
            body = json.dumps(payload).encode("utf-8")
            headers["Content-Type"] = "application/json"
        if self.token:
            headers["Authorization"] = f"Bearer {self.token}"

        request = urllib.request.Request(self._url(path), data=body, headers=headers, method=method)
        try:
            with urllib.request.urlopen(request, timeout=self.timeout) as response:
                raw = response.read().decode("utf-8", errors="replace")
                return json.loads(raw) if raw else {}
        except urllib.error.HTTPError as exc:
            raw = exc.read().decode("utf-8", errors="replace")
            try:
                parsed = json.loads(raw) if raw else {}
            except json.JSONDecodeError:
                parsed = {"message": raw[:300]}
            message = parsed.get("message") or f"Server returned HTTP {exc.code}."
            errors = parsed.get("errors")
            if isinstance(errors, dict) and errors:
                first = next(iter(errors.values()))
                if isinstance(first, list) and first:
                    message = str(first[0])
            raise ApiError(message, exc.code, parsed) from exc
        except (urllib.error.URLError, socket.timeout, TimeoutError) as exc:
            raise ApiError(f"Cannot reach the office server: {exc}", None) from exc

    # ------------------------------------------------------------------ auth
    def login(self, email: str, password: str, device_name: str) -> dict:
        data = self._request(
            "POST",
            "auth/login",
            {"email": email, "password": password, "device_name": device_name},
        )
        self.token = data.get("token")
        return data

    def me(self) -> dict:
        return self._request("GET", "auth/me")

    def settings(self, since: str | None = None) -> dict:
        """Ask the server whether the office settings changed.

        The server answers with ``{"version": ..., "changed": bool}`` and only
        sends the full schedule when something actually changed.
        """
        path = "settings"
        if since:
            path += "?" + urllib.parse.urlencode({"since": since})
        return self._request("GET", path)

    def logout(self) -> dict:
        return self._request("POST", "auth/logout")

    # ------------------------------------------------------------------ sessions
    def start_session(self, session_uuid: str, started_at: str, device_name: str) -> dict:
        return self._request(
            "POST",
            "sessions/start",
            {
                "uuid": session_uuid,
                "started_at": started_at,
                "device_name": device_name,
            },
        )

    def sync_session(
        self,
        session_uuid: str,
        status: str,
        activity: list[dict],
        breaks: list[dict],
    ) -> dict:
        return self._request(
            "POST",
            f"sessions/{session_uuid}/sync",
            {
                "status": status,
                "activity": activity,
                "breaks": breaks,
            },
        )

    def stop_session(self, session_uuid: str, ended_at: str) -> dict:
        return self._request("POST", f"sessions/{session_uuid}/stop", {"ended_at": ended_at})

    def upload_screenshot(
        self,
        session_uuid: str,
        taken_at: str,
        file_path: str,
        field_name: str = "screenshot",
    ) -> dict:
        path = Path(file_path)
        if not path.exists():
            raise ApiError("Screenshot file no longer exists on disk.")

        boundary = "----OfficeTracker" + uuid_module.uuid4().hex
        mime = mimetypes.guess_type(path.name)[0] or "image/jpeg"
        parts: list[bytes] = []

        def add_field(name: str, value: str) -> None:
            parts.append(
                f"--{boundary}\r\nContent-Disposition: form-data; name=\"{name}\"\r\n\r\n{value}\r\n".encode("utf-8")
            )

        add_field("session_uuid", session_uuid)
        add_field("taken_at", taken_at)
        parts.append(
            (
                f"--{boundary}\r\n"
                f"Content-Disposition: form-data; name=\"{field_name}\"; filename=\"{path.name}\"\r\n"
                f"Content-Type: {mime}\r\n\r\n"
            ).encode("utf-8")
        )
        parts.append(path.read_bytes())
        parts.append(f"\r\n--{boundary}--\r\n".encode("utf-8"))
        body = b"".join(parts)

        headers = {
            "Accept": "application/json",
            "Content-Type": f"multipart/form-data; boundary={boundary}",
            "Content-Length": str(len(body)),
        }
        if self.token:
            headers["Authorization"] = f"Bearer {self.token}"

        request = urllib.request.Request(
            self._url("screenshots"),
            data=body,
            headers=headers,
            method="POST",
        )
        try:
            with urllib.request.urlopen(request, timeout=max(self.timeout, 45)) as response:
                raw = response.read().decode("utf-8", errors="replace")
                return json.loads(raw) if raw else {}
        except urllib.error.HTTPError as exc:
            raw = exc.read().decode("utf-8", errors="replace")
            try:
                parsed = json.loads(raw) if raw else {}
            except json.JSONDecodeError:
                parsed = {"message": raw[:300]}
            raise ApiError(parsed.get("message", f"Upload failed ({exc.code})."), exc.code, parsed) from exc
        except (urllib.error.URLError, socket.timeout, TimeoutError) as exc:
            raise ApiError(f"Cannot upload screenshot: {exc}", None) from exc

    @staticmethod
    def normalise_url(value: str) -> str:
        value = value.strip().rstrip("/")
        if not value:
            raise ApiError("Server API URL is required.")
        parsed = urllib.parse.urlparse(value)
        if parsed.scheme not in ("http", "https") or not parsed.netloc:
            raise ApiError("Server API URL must start with http:// or https://")
        return value
