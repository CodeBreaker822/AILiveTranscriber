param(
    [string] $RemoteUrl = "git@github.com:CodeBreaker822/AITranscriberAPP.git",
    [string] $Branch = "main"
)

$ErrorActionPreference = "Stop"

$repoRoot = Resolve-Path (Join-Path $PSScriptRoot "..")
Set-Location $repoRoot

$status = & git status --porcelain
if ($status) {
    throw @"
Your working tree has uncommitted changes.

First commit your developer repo changes normally:
  git add .
  git commit -m "Your message"
  git push origin main

Then publish the client updater:
  .\node\npm.cmd run tauri:update
"@
}

$config = Get-Content -Raw "src-tauri\tauri.conf.json" | ConvertFrom-Json
$version = $config.version

if ($version -notmatch "^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$") {
    throw "src-tauri\tauri.conf.json version must be SemVer, for example 1.0.1. Got: $version"
}

$endpoint = $config.plugins.updater.endpoints[0]
if ($endpoint -ne "https://github.com/CodeBreaker822/AITranscriberAPP/releases/latest/download/latest.json") {
    throw "Updater endpoint must point to CodeBreaker822/AITranscriberAPP. Got: $endpoint"
}

Write-Host "Running updater checks..."
& .\node\npm.cmd run tauri:update-test
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

Write-Host "Pushing committed app update v$version to $RemoteUrl ($Branch)..."
& git push $RemoteUrl "HEAD:refs/heads/$Branch"

Write-Host "Client updater push complete. GitHub Actions in AITranscriberAPP will publish v$version."
