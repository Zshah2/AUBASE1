<#!
  AuBase — start the PHP built-in web server (Windows PowerShell 5+ or PowerShell 7+ "pwsh").

  Usage (from repo root):
    .\dev.ps1
    .\dev.ps1 -Port 3000
    .\dev.ps1 -UseRouter   # uses router.php (serve from repo root)

  macOS / Linux: install PowerShell Core, then: pwsh ./dev.ps1
  Requires: PHP on PATH, .env configured for MySQL.
#>
param(
    [int] $Port = 8080,
    [switch] $UseRouter
)

$ErrorActionPreference = 'Stop'

function Test-Php {
    $php = Get-Command php -ErrorAction SilentlyContinue
    if (-not $php) {
        Write-Error "PHP was not found on PATH. Install PHP 8+ and ensure 'php' is available in the terminal."
    }
    & php -v
}

$repoRoot = $PSScriptRoot
$publicDir = Join-Path $repoRoot 'public'
$router = Join-Path $repoRoot 'router.php'

if (-not (Test-Path $publicDir)) {
    Write-Error "Expected folder not found: $publicDir (run this script from the AuBase repository root)."
}

Test-Php

$listen = "localhost:$Port"
Write-Host ""
Write-Host "Starting AuBase at http://$listen/ (Ctrl+C to stop)" -ForegroundColor Cyan
Write-Host ""

if ($UseRouter) {
    if (-not (Test-Path $router)) {
        Write-Error "router.php not found at $router"
    }
    Set-Location $repoRoot
    & php -S $listen $router
} else {
    & php -S $listen -t $publicDir
}
