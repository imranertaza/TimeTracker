param(
    [switch]$IncludeWebServer
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
$desktop = Join-Path $root "desktop"
$mainScript = Join-Path $desktop "main.py"
$startup = [Environment]::GetFolderPath("Startup")
$shortcutPath = Join-Path $startup "Office Time Tracker.lnk"

if (-not (Test-Path $mainScript)) {
    throw "main.py not found at $mainScript"
}

$pythonw = (Get-Command pythonw.exe -ErrorAction SilentlyContinue).Source
if (-not $pythonw) {
    $pythonw = (Get-Command python.exe -ErrorAction SilentlyContinue).Source
}
if (-not $pythonw) {
    throw "Python was not found on PATH. Install Python 3.11+ and try again."
}

$shell = New-Object -ComObject WScript.Shell
$shortcut = $shell.CreateShortcut($shortcutPath)

if ($IncludeWebServer) {
    $launcher = Join-Path $PSScriptRoot "start_demo.vbs"
    $shortcut.TargetPath = "wscript.exe"
    $shortcut.Arguments = "`"$launcher`""
    $shortcut.WorkingDirectory = $PSScriptRoot
    $shortcut.Description = "Office Time Tracker + local Laravel server"
} else {
    $shortcut.TargetPath = $pythonw
    $shortcut.Arguments = "`"$mainScript`""
    $shortcut.WorkingDirectory = $desktop
    $shortcut.Description = "Office Time Tracker - Dhaka office"
}

$shortcut.WindowStyle = 1
$shortcut.Save()

Write-Host "Installed Windows auto-start shortcut:"
Write-Host "  $shortcutPath"
Write-Host "  Target: $($shortcut.TargetPath) $($shortcut.Arguments)"
if ($IncludeWebServer) {
    Write-Host ""
    Write-Host "This shortcut also starts the local Laravel API on http://127.0.0.1:8000."
}
