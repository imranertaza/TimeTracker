param(
    [switch]$NoAutoStart,
    [switch]$NoSeed
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
$web = Join-Path $root "web"
$desktop = Join-Path $root "desktop"

Write-Host ""
Write-Host "Office Time Tracker - Windows setup" -ForegroundColor Green
Write-Host "===================================" -ForegroundColor Green

if (-not (Get-Command python -ErrorAction SilentlyContinue)) {
    throw "Python 3.11+ is required. Install it from python.org and tick 'Add Python to PATH'."
}
if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    throw "PHP 8.2+ is required. XAMPP's PHP folder must be on PATH."
}
if (-not (Get-Command composer -ErrorAction SilentlyContinue)) {
    throw "Composer is required. See https://getcomposer.org/download/"
}

Write-Host "`n[1/5] Installing Python packages..." -ForegroundColor Cyan
python -m pip install --disable-pip-version-check -r (Join-Path $desktop "requirements.txt")

Write-Host "`n[2/5] Installing Laravel dependencies..." -ForegroundColor Cyan
Push-Location $web
try {
    if (-not (Test-Path ".env")) {
        Copy-Item ".env.example" ".env"
        php artisan key:generate
    }
    composer install --no-interaction --prefer-dist

    Write-Host "`n[3/5] Preparing database..." -ForegroundColor Cyan
    php artisan migrate --force
    if (-not $NoSeed) {
        php artisan db:seed --force
    }

    Write-Host "`n[4/5] Preparing storage..." -ForegroundColor Cyan
    if (-not (Test-Path "public\storage")) {
        php artisan storage:link
    }
    php artisan optimize:clear
} finally {
    Pop-Location
}

Write-Host "`n[5/5] Windows auto-start..." -ForegroundColor Cyan
if ($NoAutoStart) {
    Write-Host "Skipped (-NoAutoStart)."
} else {
    & (Join-Path $PSScriptRoot "install_autostart.ps1") -IncludeWebServer
}

Write-Host ""
Write-Host "Setup complete." -ForegroundColor Green
Write-Host "Web panel:  http://127.0.0.1:8000"
Write-Host "Admin:      admin@officetracker.test / Admin@12345"
Write-Host "Staff:      staff@officetracker.test / Staff@12345"
Write-Host ""
Write-Host "Start now with: scripts\start_demo.bat"
