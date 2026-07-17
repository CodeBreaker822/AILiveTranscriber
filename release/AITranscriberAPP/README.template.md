# ASTRA

## Adaptive Speech Transcription and Recording Assistant

**Windows desktop transcription for live sessions, uploaded recordings, review, and export.**

ASTRA helps turn meetings, interviews, calls, lectures, and saved recordings into organized transcripts. It records or imports audio, prepares speech locally, transcribes through online or offline workflows, keeps transcript sections tied to playback, and exports the result for documentation work.

Solo-Built - Local Processing First - Windows Desktop App

---

## Download

To download ASTRA from GitHub:

1. Open the repository file list.
2. Click the `updates` folder.
3. Open the newest version folder, for example `{{UPDATE_FOLDER}}`.
4. Click the installer file ending in `_x64-setup.exe`.
5. On the file page, click **Download raw file**.
6. Run the downloaded installer.

Do not use GitHub's **Code > Download ZIP** button to install ASTRA. That ZIP is not the app installer. It may only contain repository metadata or Git LFS pointer files, and Windows will not be able to run it as the application.

The file you want looks like this:

```text
{{INSTALLER_FILE}}
```

After installation, ASTRA checks this repository for signed updates automatically.

## What ASTRA Does

- Records live sessions.
- Imports existing audio files.
- Splits long recordings into reviewable sections.
- Uses local audio preparation before transcription.
- Supports online transcription through a configured server.
- Supports offline transcription with a local Whisper model.
- Adds local speaker diarization when the required model is available.
- Keeps transcript sections connected to playback.
- Exports transcripts for documentation and review.

## Online And Offline Modes

**Online mode** uses a hosted transcription server. It is useful when stronger hosted models, provider fallback, polish tools, and summaries are needed.

**Offline mode** keeps transcription on the desktop machine with a local Whisper model. It is useful for sensitive recordings, poor connectivity, or local-only work. Offline processing can be slower because it depends on the user's CPU, memory, and installed model.

## Minimum Requirements

Recommended minimum PC:

- Windows 10 or Windows 11, 64-bit.
- 4 logical CPU processors or more.
- 8 GB RAM for online transcription workflows.
- 16 GB RAM recommended for offline Whisper, long uploads, or speaker diarization.
- Enough free disk space for the app, temporary audio chunks, logs, local database, and optional offline models.
- Internet access for online transcription, polish, summary, license checks, and app updates.

The installer includes the runtime pieces the desktop app expects. Users should not need to install PHP, Node.js, Composer, Laravel, FFmpeg, or queue tools separately.

## First Setup

1. Install ASTRA with the `_x64-setup.exe` installer.
2. Open the app.
3. Go to Settings.
4. Enter the transcription server URL and license key provided for your account.
5. Choose the workflow you need: Live or Upload.
6. Choose Online or Offline mode.

## Updates

ASTRA uses Tauri's signed updater. The installed app checks `latest.json` in this repository and downloads the matching Windows installer artifact when an update is available.

This repository is the public app update channel. The private development source is maintained separately.

Current published version: `{{APP_VERSION}}`

## Project Status

ASTRA is a new project and is currently maintained by one developer. The focus right now is a stable Windows desktop experience for real transcription and documentation work.

Issues and suggestions are useful when they include the app version, Windows version, what workflow failed, and any logs or screenshots that explain the problem.

---

ASTRA is built for the practical work after someone says, "Can we get this meeting written down?"
