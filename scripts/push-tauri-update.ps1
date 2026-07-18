param(
    [ValidateSet("", "DILG", "JERVA")]
    [string] $Edition = "",
    [string] $RemoteUrl = "git@github.com:CodeBreaker822/AITranscriberAPP.git",
    [string] $Branch = "main",
    [ValidateSet("", "Minor", "Medium", "Major")]
    [string] $Kind = "",
    [switch] $UseCurrentVersion,
    [switch] $SkipBuild
)

$ErrorActionPreference = "Stop"

$repoRoot = Resolve-Path (Join-Path $PSScriptRoot "..")
Set-Location $repoRoot

$editionConfigs = @{
    DILG = @{
        Key = "dilg"
        Label = "DILG / ASTRA AI Transcriber"
        ProductName = "ASTRA AI Transcriber"
        RemoteUrl = "git@github.com:CodeBreaker822/AITranscriberAPP.git"
        RepoDirectoryName = "AITranscriberAPP"
        RepoOwner = "CodeBreaker822"
        RepoName = "AITranscriberAPP"
        OverlayConfig = "tauri.dilg.conf.json"
        ReadmeTemplate = "release\AITranscriberAPP\README.template.md"
        SigningKeyPaths = @(
            (Join-Path $env:USERPROFILE ".tauri\astra-dilg-updater.key"),
            (Join-Path $env:USERPROFILE ".tauri\aitranscriber-updater.key")
        )
    }
    JERVA = @{
        Key = "jerva"
        Label = "JERVA Transcriber"
        ProductName = "JERVA Transcriber"
        RemoteUrl = "git@github.com:CodeBreaker822/JervaTranscriber.git"
        RepoDirectoryName = "JervaTranscriber"
        RepoOwner = "CodeBreaker822"
        RepoName = "JervaTranscriber"
        OverlayConfig = "tauri.jerva.conf.json"
        ReadmeTemplate = "release\JervaTranscriber\README.template.md"
        SigningKeyPaths = @(
            (Join-Path $env:USERPROFILE ".tauri\jerva-transcriber-updater.key")
        )
    }
}

function Get-UpdateEdition {
    param([string] $RequestedEdition)

    if ($RequestedEdition) {
        return $RequestedEdition.ToUpperInvariant()
    }

    Write-Host ""
    Write-Host "Which app edition are you updating?"
    Write-Host "  1) DILG  - ASTRA AI Transcriber"
    Write-Host "  2) JERVA - JERVA Transcriber"

    while ($true) {
        $answer = (Read-Host "Choose DILG or JERVA").Trim().ToLowerInvariant()

        switch ($answer) {
            "1" { return "DILG" }
            "dilg" { return "DILG" }
            "astra" { return "DILG" }
            "2" { return "JERVA" }
            "jerva" { return "JERVA" }
        }

        Write-Host "Please type DILG or JERVA."
    }
}

function Get-UpdateKind {
    param([string] $RequestedKind)

    if ($RequestedKind) {
        return $RequestedKind
    }

    Write-Host ""
    Write-Host "What kind of client update is this?"
    Write-Host "  1) Minor  - +0.0.1  (example 1.0.0 -> 1.0.1)"
    Write-Host "  2) Medium - +0.1.0  (example 1.0.0 -> 1.1.0)"
    Write-Host "  3) Major  - +1.0.0  (example 1.0.0 -> 2.0.0)"

    while ($true) {
        $answer = (Read-Host "Choose Minor, Medium, or Major").Trim().ToLowerInvariant()

        switch ($answer) {
            "1" { return "Minor" }
            "minor" { return "Minor" }
            "m" { return "Minor" }
            "2" { return "Medium" }
            "medium" { return "Medium" }
            "med" { return "Medium" }
            "3" { return "Major" }
            "major" { return "Major" }
        }

        Write-Host "Please type Minor, Medium, or Major."
    }
}

function Get-NextVersion {
    param(
        [string] $CurrentVersion,
        [string] $UpdateKind
    )

    if ($CurrentVersion -notmatch "^(\d+)\.(\d+)\.(\d+)(-[0-9A-Za-z.-]+)?$") {
        throw "src-tauri\tauri.conf.json version must be SemVer, for example 1.0.1. Got: $CurrentVersion"
    }

    $major = [int] $Matches[1]
    $minor = [int] $Matches[2]
    $patch = [int] $Matches[3]

    switch ($UpdateKind) {
        "Minor" {
            $patch += 1
        }
        "Medium" {
            $minor += 1
            $patch = 0
        }
        "Major" {
            $major += 1
            $minor = 0
            $patch = 0
        }
        default {
            throw "Unknown update kind: $UpdateKind"
        }
    }

    return "$major.$minor.$patch"
}

function Set-ProjectVersion {
    param(
        [object] $Config,
        [string] $Version
    )

    $Config.version = $Version
    $json = $Config | ConvertTo-Json -Depth 50
    [System.IO.File]::WriteAllText(
        (Join-Path $repoRoot "src-tauri\tauri.conf.json"),
        $json + [Environment]::NewLine,
        [System.Text.UTF8Encoding]::new($false)
    )

    $cargoPath = Join-Path $repoRoot "src-tauri\Cargo.toml"
    $cargo = Get-Content -Raw -LiteralPath $cargoPath
    $packageVersionRegex = [System.Text.RegularExpressions.Regex]::new('(?m)^(version\s*=\s*)"[0-9A-Za-z.+-]+"')
    $replacement = [System.Text.RegularExpressions.MatchEvaluator]{
        param($match)
        $match.Groups[1].Value + '"' + $Version + '"'
    }
    $cargo = $packageVersionRegex.Replace($cargo, $replacement, 1)
    [System.IO.File]::WriteAllText(
        $cargoPath,
        $cargo,
        [System.Text.UTF8Encoding]::new($false)
    )
}

function Render-Template {
    param(
        [string] $TemplatePath,
        [hashtable] $Variables
    )

    $content = Get-Content -Raw -LiteralPath $TemplatePath
    foreach ($key in $Variables.Keys) {
        $content = $content.Replace("{{$key}}", [string] $Variables[$key])
    }

    $unresolvedTokens = [regex]::Matches($content, "\{\{[A-Z0-9_]+\}\}") |
        ForEach-Object { $_.Value } |
        Sort-Object -Unique

    if ($unresolvedTokens) {
        throw "Unresolved template variables in ${TemplatePath}: $($unresolvedTokens -join ', ')"
    }

    return $content
}

function Assert-SshAgentHasIdentity {
    $gitSshDirectory = Join-Path $env:ProgramFiles "Git\usr\bin"
    $gitSshAgent = Join-Path $gitSshDirectory "ssh-agent.exe"
    $gitSshAdd = Join-Path $gitSshDirectory "ssh-add.exe"
    $gitSsh = Join-Path $gitSshDirectory "ssh.exe"
    $sshAdd = if (Test-Path -LiteralPath $gitSshAdd -PathType Leaf) { $gitSshAdd } else { "ssh-add" }

    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = "Continue"
    try {
        $identityOutput = & $sshAdd -l 2>&1
        $identityExitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }

    if ($identityExitCode -eq 0) {
        if (Test-Path -LiteralPath $gitSsh -PathType Leaf) {
            $env:GIT_SSH = $gitSsh
        }
        return
    }

    if (Test-Path -LiteralPath $gitSshAgent -PathType Leaf) {
        $agentOutput = & $gitSshAgent -s
        $socketMatch = [regex]::Match(($agentOutput -join "`n"), "SSH_AUTH_SOCK=([^;]+);")
        $pidMatch = [regex]::Match(($agentOutput -join "`n"), "SSH_AGENT_PID=([^;]+);")

        if ($socketMatch.Success) {
            $env:SSH_AUTH_SOCK = $socketMatch.Groups[1].Value
        }

        if ($pidMatch.Success) {
            $env:SSH_AGENT_PID = $pidMatch.Groups[1].Value
        }

        if (Test-Path -LiteralPath $gitSsh -PathType Leaf) {
            $env:GIT_SSH = $gitSsh
        }

        Write-Host "Loading SSH key for Git LFS. Enter your SSH key passphrase if prompted."
        & $sshAdd "$env:USERPROFILE\.ssh\id_ed25519"

        $previousErrorActionPreference = $ErrorActionPreference
        $ErrorActionPreference = "Continue"
        try {
            $identityOutput = & $sshAdd -l 2>&1
            $identityExitCode = $LASTEXITCODE
        } finally {
            $ErrorActionPreference = $previousErrorActionPreference
        }
    }

    if ($identityExitCode -ne 0) {
        throw @"
SSH agent is not ready for Git LFS uploads.

Run these, then retry tauri:update:
  & "C:\Program Files\Git\usr\bin\ssh-agent.exe" -s
  & "C:\Program Files\Git\usr\bin\ssh-add.exe" `$env:USERPROFILE\.ssh\id_ed25519
  ssh -T git@github.com

Then continue without bumping or rebuilding:
  .\node\npm.cmd run tauri:update -- -Edition $selectedEditionKey -UseCurrentVersion -SkipBuild

ssh-add output:
$identityOutput
"@
    }
}

$selectedEditionKey = Get-UpdateEdition -RequestedEdition $Edition
$selectedEdition = $editionConfigs[$selectedEditionKey]
$publicUpdaterEndpoint = "https://raw.githubusercontent.com/$($selectedEdition.RepoOwner)/$($selectedEdition.RepoName)/$Branch/latest.json"
$publicRawBaseUrl = "https://github.com/$($selectedEdition.RepoOwner)/$($selectedEdition.RepoName)/raw/$Branch"

if ($RemoteUrl -eq "git@github.com:CodeBreaker822/AITranscriberAPP.git" -and $selectedEditionKey -ne "DILG") {
    $RemoteUrl = $selectedEdition.RemoteUrl
}

Write-Host ""
Write-Host "Selected edition: $($selectedEdition.Label)"
Write-Host "Updater repository: $RemoteUrl"

$config = Get-Content -Raw "src-tauri\tauri.conf.json" | ConvertFrom-Json
$currentVersion = $config.version

$overlayPath = Join-Path $repoRoot $selectedEdition.OverlayConfig
if (-not (Test-Path -LiteralPath $overlayPath -PathType Leaf)) {
    throw "Missing Tauri edition config: $overlayPath"
}

$overlayConfig = Get-Content -Raw -LiteralPath $overlayPath | ConvertFrom-Json
$endpoint = $overlayConfig.plugins.updater.endpoints[0]
if ($endpoint -ne $publicUpdaterEndpoint) {
    throw "Updater endpoint for $($selectedEdition.Label) must point to $publicUpdaterEndpoint. Got: $endpoint"
}

if ($UseCurrentVersion) {
    $nextVersion = $currentVersion
    Write-Host ""
    Write-Host "Using current app version: $nextVersion"
} else {
    $updateKind = Get-UpdateKind -RequestedKind $Kind
    $nextVersion = Get-NextVersion -CurrentVersion $currentVersion -UpdateKind $updateKind

    Write-Host ""
    Write-Host "Current app version: $currentVersion"
    Write-Host "$updateKind update version: $nextVersion"
}

if ($selectedEditionKey -eq "JERVA" -and $overlayConfig.plugins.updater.pubkey -eq "JERVA_UPDATER_PUBLIC_KEY_NOT_CONFIGURED") {
    throw "JERVA updater public key is not configured. Generate a separate JERVA Tauri signing key, then place its public key in tauri.jerva.conf.json."
}

if ($env:TAURI_SIGNING_PRIVATE_KEY -and (Test-Path -LiteralPath $env:TAURI_SIGNING_PRIVATE_KEY -PathType Leaf)) {
    $env:TAURI_SIGNING_PRIVATE_KEY = Get-Content -Raw -LiteralPath $env:TAURI_SIGNING_PRIVATE_KEY
} elseif (-not $env:TAURI_SIGNING_PRIVATE_KEY) {
    $defaultKeyPath = $selectedEdition.SigningKeyPaths |
        Where-Object { Test-Path -LiteralPath $_ -PathType Leaf } |
        Select-Object -First 1

    if ($defaultKeyPath) {
        $env:TAURI_SIGNING_PRIVATE_KEY = Get-Content -Raw -LiteralPath $defaultKeyPath
    }
}

if (-not $env:TAURI_SIGNING_PRIVATE_KEY) {
    throw "Missing TAURI_SIGNING_PRIVATE_KEY for $($selectedEdition.Label). Set it to the updater private key contents or create one of these files: $($selectedEdition.SigningKeyPaths -join ', ')"
}

if ([string]::IsNullOrWhiteSpace($env:TAURI_SIGNING_PRIVATE_KEY_PASSWORD)) {
    $env:TAURI_SIGNING_PRIVATE_KEY_PASSWORD = ""
}

$env:AI_TRANSCRIBER_EDITION = $selectedEdition.Key

Write-Host "Running updater checks..."
& .\node\npm.cmd run tauri:update-test
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

Set-ProjectVersion -Config $config -Version $nextVersion

if ($SkipBuild) {
    Write-Host "Skipping build and using existing Tauri artifacts for $nextVersion..."
} else {
    Write-Host "Building official $($selectedEdition.ProductName) updater artifacts..."
    & .\node\npm.cmd run tauri:build -- $($selectedEdition.Key)
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
}

$nsisDirectory = Join-Path $repoRoot "src-tauri\target\release\bundle\nsis"
$installerNamePrefix = $selectedEdition.ProductName
$installer = Get-ChildItem -LiteralPath $nsisDirectory -Filter "*.exe" |
    Where-Object { $_.Name -like "*$nextVersion*" -and $_.Name -like "$installerNamePrefix*" } |
    Sort-Object LastWriteTimeUtc -Descending |
    Select-Object -First 1

if (-not $installer) {
    throw "Could not find the $($selectedEdition.ProductName) NSIS installer for version $nextVersion in $nsisDirectory."
}

$signaturePath = "$($installer.FullName).sig"
if (-not (Test-Path -LiteralPath $signaturePath)) {
    throw "Could not find the Tauri updater signature: $signaturePath"
}

$publicReadmeTemplatePath = Join-Path $repoRoot $selectedEdition.ReadmeTemplate
if (-not (Test-Path -LiteralPath $publicReadmeTemplatePath -PathType Leaf)) {
    throw "Missing public app repository README template: $publicReadmeTemplatePath"
}

$releaseDirectoryName = "app-v$nextVersion"
$encodedInstallerName = [uri]::EscapeDataString($installer.Name)
$installerUrl = "$publicRawBaseUrl/updates/$releaseDirectoryName/$encodedInstallerName"
$signature = (Get-Content -Raw -LiteralPath $signaturePath).Trim()
$publicReadme = Render-Template -TemplatePath $publicReadmeTemplatePath -Variables @{
    APP_VERSION = $nextVersion
    UPDATE_FOLDER = $releaseDirectoryName
    INSTALLER_FILE = $installer.Name
}

Assert-SshAgentHasIdentity

$tempParent = Join-Path ([System.IO.Path]::GetTempPath()) "aitranscriber-updater-$([System.Guid]::NewGuid().ToString('N'))"
$publishRoot = Join-Path $tempParent $selectedEdition.RepoDirectoryName

try {
    New-Item -ItemType Directory -Path $tempParent | Out-Null
    Write-Host "Cloning public updater repository over SSH..."
    & git clone --depth 1 --branch $Branch $RemoteUrl $publishRoot
    if ($LASTEXITCODE -ne 0) {
        Write-Host "Branch $Branch was not available. Cloning repository default branch and preparing $Branch..."

        if (Test-Path -LiteralPath $publishRoot) {
            Remove-Item -LiteralPath $publishRoot -Recurse -Force
        }

        & git clone $RemoteUrl $publishRoot
        if ($LASTEXITCODE -ne 0) {
            exit $LASTEXITCODE
        }

        & git -C $publishRoot checkout -B $Branch
        if ($LASTEXITCODE -ne 0) {
            exit $LASTEXITCODE
        }
    }

    & git -C $publishRoot lfs install --local
    & git -C $publishRoot lfs track "updates/**"
    & git -C $publishRoot config "lfs.https://github.com/$($selectedEdition.RepoOwner)/$($selectedEdition.RepoName).git/info/lfs.locksverify" false

    $resolvedPublishRoot = [System.IO.Path]::GetFullPath($publishRoot)
    $resolvedTempParent = [System.IO.Path]::GetFullPath($tempParent)
    if (-not $resolvedPublishRoot.StartsWith($resolvedTempParent, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Refusing to clean unexpected path: $resolvedPublishRoot"
    }

    Get-ChildItem -LiteralPath $publishRoot -Force |
        Where-Object { $_.Name -ne ".git" -and $_.Name -ne ".gitattributes" } |
        Remove-Item -Recurse -Force

    [System.IO.File]::WriteAllText(
        (Join-Path $publishRoot "README.md"),
        $publicReadme + [Environment]::NewLine,
        [System.Text.UTF8Encoding]::new($false)
    )

    $updateDirectory = Join-Path $publishRoot "updates\$releaseDirectoryName"
    New-Item -ItemType Directory -Path $updateDirectory -Force | Out-Null
    Copy-Item -LiteralPath $installer.FullName -Destination (Join-Path $updateDirectory $installer.Name) -Force
    Copy-Item -LiteralPath $signaturePath -Destination (Join-Path $updateDirectory ((Split-Path -Leaf $signaturePath))) -Force

    $latestJson = @{
        version = $nextVersion
        notes = "$($selectedEdition.ProductName) $nextVersion update."
        pub_date = (Get-Date).ToUniversalTime().ToString("yyyy-MM-ddTHH:mm:ssZ")
        platforms = @{
            "windows-x86_64" = @{
                signature = $signature
                url = $installerUrl
            }
        }
    } | ConvertTo-Json -Depth 10
    [System.IO.File]::WriteAllText((Join-Path $publishRoot "latest.json"), $latestJson + [Environment]::NewLine, [System.Text.UTF8Encoding]::new($false))

    $hasUserName = & git -C $publishRoot config user.name
    if (-not $hasUserName) {
        & git -C $publishRoot config user.name "CodeBreaker822"
    }

    $hasUserEmail = & git -C $publishRoot config user.email
    if (-not $hasUserEmail) {
        & git -C $publishRoot config user.email "CodeBreaker822@users.noreply.github.com"
    }

    & git -C $publishRoot add -A
    & git -C $publishRoot diff --cached --quiet
    if ($LASTEXITCODE -eq 0) {
        throw "No updater artifact changes were found to publish."
    }

    & git -C $publishRoot commit -m "$($selectedEdition.ProductName) update v$nextVersion"
    Write-Host "Pushing updater artifacts to $RemoteUrl ($Branch) over SSH..."
    & git -C $publishRoot push origin "HEAD:refs/heads/$Branch"
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }

    Write-Host "Client updater upload complete: $publicUpdaterEndpoint"
}
finally {
    if (Test-Path -LiteralPath $tempParent) {
        Remove-Item -LiteralPath $tempParent -Recurse -Force
    }
}
