param(
    [ValidateSet("", "DILG", "JERVA")]
    [string] $Edition = "",
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]] $TauriArgs = @()
)

$ErrorActionPreference = "Stop"

$repoRoot = Resolve-Path (Join-Path $PSScriptRoot "..")
Set-Location $repoRoot

$editionConfigs = @{
    DILG = @{
        Key = "dilg"
        Label = "DILG / ASTRA AI Transcriber"
        Config = "tauri.dilg.conf.json"
    }
    JERVA = @{
        Key = "jerva"
        Label = "JERVA Transcriber"
        Config = "tauri.jerva.conf.json"
    }
}

function Get-DevEdition {
    param([string] $RequestedEdition)

    if ($RequestedEdition) {
        return $RequestedEdition.ToUpperInvariant()
    }

    Write-Host ""
    Write-Host "Which app edition do you want to launch?"
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

$selectedEditionKey = Get-DevEdition -RequestedEdition $Edition
$selectedEdition = $editionConfigs[$selectedEditionKey]
$configPath = Join-Path $repoRoot $selectedEdition.Config

if (-not (Test-Path -LiteralPath $configPath -PathType Leaf)) {
    throw "Missing Tauri edition config: $configPath"
}

$env:AI_TRANSCRIBER_EDITION = $selectedEdition.Key
$env:CARGO_TARGET_DIR = Join-Path $repoRoot "src-tauri\target-dev-$($selectedEdition.Key)"

Write-Host ""
Write-Host "Launching edition: $($selectedEdition.Label)"
Write-Host "Dev target directory: $env:CARGO_TARGET_DIR"

& .\node\node.exe node_modules\@tauri-apps\cli\tauri.js dev --config $selectedEdition.Config @TauriArgs
exit $LASTEXITCODE
