$ErrorActionPreference = "Stop"
$startup = [Environment]::GetFolderPath("Startup")
$shortcutPath = Join-Path $startup "Office Time Tracker.lnk"

if (Test-Path $shortcutPath) {
    Remove-Item $shortcutPath -Force
    Write-Host "Removed auto-start shortcut: $shortcutPath"
} else {
    Write-Host "Auto-start shortcut was not installed."
}
