$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$modelDirectory = Join-Path $projectRoot 'storage\app\private\whisper\models'
$modelPath = Join-Path $modelDirectory 'ggml-large-v3-turbo-q8_0.bin'
$downloadPath = "$modelPath.download"
$modelUrl = 'https://huggingface.co/ggerganov/whisper.cpp/resolve/main/ggml-large-v3-turbo-q8_0.bin?download=true'
$expectedSha1 = '01bf15bedffe9f39d65c1b6ff9b687ea91f59e0e'

New-Item -ItemType Directory -Force -Path $modelDirectory | Out-Null

try {
    Write-Host 'Downloading Whisper large-v3-turbo Q8_0 (about 874 MB)...'
    Invoke-WebRequest -Uri $modelUrl -OutFile $downloadPath -UseBasicParsing
    $actualSha1 = (Get-FileHash -LiteralPath $downloadPath -Algorithm SHA1).Hash.ToLowerInvariant()

    if ($actualSha1 -ne $expectedSha1) {
        throw "Whisper model checksum mismatch. Expected $expectedSha1, received $actualSha1."
    }

    Move-Item -LiteralPath $downloadPath -Destination $modelPath -Force
    Write-Host "Whisper model is ready: $modelPath"
} finally {
    if (Test-Path -LiteralPath $downloadPath) {
        Remove-Item -LiteralPath $downloadPath -Force
    }
}
