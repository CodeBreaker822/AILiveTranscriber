param(
    [switch] $Quiet
)

<#
Prepend this repository root to the current PowerShell session PATH so the
repo-local shims can be called without `.\`.

For one session:
    .\enable-local-tools.ps1

For every new PowerShell session, dot-source this file from $PROFILE:
    . "D:\Transcriber Project\AITranscriber\enable-local-tools.ps1" -Quiet
#>
$scriptDir = if ($PSScriptRoot) { $PSScriptRoot } else { Split-Path -Parent $MyInvocation.MyCommand.Definition }
if (-not $scriptDir) { $scriptDir = (Get-Location).Path }

if (-not (Test-Path $scriptDir)) {
    Write-Error "Repository directory not found: $scriptDir"
    return
}

$currentPath = @($env:PATH -split ';' | Where-Object { $_ })
if ($currentPath -notcontains $scriptDir) {
    $env:PATH = "$scriptDir;$env:PATH"
}

if (-not $Quiet) {
    Write-Host "Local tools enabled for this session. Repo root is on PATH: $scriptDir"
    Write-Host "You can now run: npm.local run tauri:build (no .\ required)"
}
