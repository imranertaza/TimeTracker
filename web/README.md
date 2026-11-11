# Office Time Tracker — Laravel web panel

This is the Laravel 11 admin/staff panel and REST API for the Office Time Tracker.

See the project root [`README.md`](../README.md) for setup, credentials and deployment.

Quick local commands:

```bash
composer install
php artisan migrate --seed
php artisan serve --host=127.0.0.1 --port=8000
```

Demo accounts:

- Admin: `admin@officetracker.test` / `Admin@12345`
- Staff: `staff@officetracker.test` / `Staff@12345`

API base URL: `http://127.0.0.1:8000/api/v1`

API documentation: [`../docs/API.md`](../docs/API.md)

Tests:

```bash
php artisan test
```
