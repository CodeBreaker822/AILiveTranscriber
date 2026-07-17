# ASTRA

## Adaptive Speech Transcription and Recording Assistant

**Windows-first meeting transcription for live sessions, long recordings, review, and export.**

ASTRA helps turn meetings, interviews, calls, lectures, and recorded sessions into organized transcripts you can actually work with. It records live audio, imports existing files, prepares speech locally, transcribes through online or offline workflows, adds speaker context, and keeps every section connected to playback so review does not become guesswork.

Solo-Built - Local Processing First - Built for Documentation Work

---

## Table Of Contents

- [Introduction](#introduction)
- [Why ASTRA?](#why-astra)
- [Features](#features)
- [Installation](#installation)
- [Key Features In Action](#key-features-in-action)
- [System Architecture](#system-architecture)
- [Minimum Requirements](#minimum-requirements)
- [For Developers](#for-developers)
- [Project Status](#project-status)
- [Related Repositories](#related-repositories)
- [License](#license)

---

## Introduction

ASTRA is a desktop transcription app built for people who need more than a wall of generated text. It is meant for real review work: capture the audio, split it into manageable sections, transcribe it, check the result against playback, clean it up when needed, and export something usable.

The app supports two main workflows:

- **Live**: record while a meeting or session is happening.
- **Upload**: process existing audio such as MP3, AAC, WAV, M4A, OGG, or FLAC.

Each workflow can run in two modes:

- **Online**: use the configured transcription server and provider fallback.
- **Offline**: use local Whisper and local diarization models on the desktop machine.

ASTRA does not try to pretend transcription is perfect. It is designed around the part that matters after transcription: checking, recovering, organizing, and exporting the result.

## Why ASTRA?

Most transcription tools optimize for a fast first draft. ASTRA is more interested in what happens after that draft exists.

- **Long recordings stay manageable.** Audio is split into sections so one failure does not ruin the entire job.
- **Review is built in.** Transcript sections stay tied to their source audio.
- **Local preparation comes first.** FFmpeg, speech detection, and speaker diarization run on the desktop app.
- **Online mode can use stronger hosted models.** Useful when speed, accuracy, or provider fallback matters.
- **Offline mode keeps transcription local.** Useful for sensitive files, poor internet, or local-only work.
- **Exports are meant for documentation.** The goal is minutes, reports, archives, and follow-up work, not just raw text.

ASTRA is not a replacement for human judgment. It is a way to spend less time typing from scratch and more time reviewing what was actually said.

## Features

- **Live recording** for meetings and sessions.
- **Audio upload** for existing recordings.
- **Online transcription** through a hosted transcription server.
- **Offline transcription** with a local Whisper model.
- **Local speaker diarization** with Sherpa-ONNX.
- **Speech-focused processing** with Silero VAD.
- **Section playback** so text can be checked against the source audio.
- **Retry, continue, cancel, and logs** for long-running processing.
- **Polish and summary tools** for online transcripts.
- **TXT, Excel-compatible, and Word-compatible exports** for review and reporting.
- **Signed desktop updates** through the Tauri updater.

## Installation

### Windows

1. Download the latest installer from the public update repository:
   <https://github.com/CodeBreaker822/AITranscriberAPP>
2. Run the installer.
3. Open ASTRA.
4. Go to Settings and enter the server URL and license key issued for your hosted transcription server.

The packaged app includes the runtime pieces it needs. Users should not need to install PHP, Node.js, Composer, Laravel, FFmpeg, queue workers, or developer tools separately.

### Other Platforms

ASTRA is currently Windows-first. Linux and macOS are not supported as packaged desktop targets yet.

## Key Features In Action

### Live Transcription

Live mode records microphone audio while the session is happening. ASTRA prepares the audio, processes speech sections, and stores transcript entries as they become available.

Use it when you want meeting notes to build up during the session instead of starting from an empty page afterward.

### Upload Long Recordings

Upload mode is for existing audio files. ASTRA splits the recording into sections, prepares each section locally, detects speech, and sends only the prepared speech audio to the selected transcription path.

Use it for interviews, saved meetings, lectures, call recordings, or any file that is too long to treat as one fragile job.

### Online Mode

Online mode uses the transcription server. The server can route work to configured providers and fall back when a provider fails, which helps keep long jobs from dying on the first service problem.

Online mode is also where polish and summary tools currently live.

### Offline Mode

Offline mode uses local models on the desktop machine. It is useful when privacy, weak internet, or local-only processing matters.

Offline transcription and diarization can be CPU and memory heavy, so slower machines may take longer.

### Speaker Diarization

ASTRA uses local Sherpa-ONNX diarization to add speaker context when available. It is not magic speaker naming, but it gives the transcript more structure and makes review easier.

### Review And Export

Transcript entries keep useful metadata:

- Project or category name
- Time range
- Raw transcript text
- Optional cleaned transcript text
- Speaker labels when available
- Audio playback reference
- Processing status

That structure is what makes section review, retry, playback, polish, summary, and export possible.

## System Architecture

ASTRA is a local desktop app wrapped with Tauri. Laravel handles the application workflow and local backend logic, while Rust/Tauri owns the desktop shell, startup, packaged runtime supervision, updates, and native offline processing.

```text
Desktop UI
    |
    v
Local Laravel backend
    |
    v
Audio preparation
    |-- FFmpeg conversion and section extraction
    `-- Silero VAD speech detection
    |
    v
Transcription
    |-- Online: Transcription Server -> provider fallback
    `-- Offline: local Whisper model
    |
    v
Speaker diarization
    `-- local Sherpa-ONNX worker
    |
    v
Saved transcript sections
    |
    v
Review, playback, polish, summarize, export
```

The important boundary is simple: audio preparation and speaker diarization are local. Online mode uses the hosted server for transcription and AI features. Offline mode keeps transcription local too.

## Minimum Requirements

Recommended minimum PC:

- Windows 10 or Windows 11, 64-bit.
- 4 logical CPU processors or more.
- 8 GB RAM for online transcription workflows.
- 16 GB RAM recommended for offline Whisper, long uploads, or speaker diarization.
- Enough free disk space for the app, temporary audio chunks, logs, local database, and optional offline models.
- Internet access for online transcription, polish, summary, license checks, and updates.

Offline and local model notes:

- Offline transcription requires a supported installed Whisper model.
- Speaker diarization requires the Sherpa diarization model.
- Offline transcription and diarization are heavier than online mode.
- Polish and summarize are currently online-only.

## Background Workers

The desktop app starts a local Laravel backend and three queue workers automatically when the packaged app opens:

| Queue | Purpose |
| --- | --- |
| `audio` | Upload preparation, section processing, transcription storage, and audio-heavy jobs. |
| `transcripts` | Transcript polish and summary jobs. |
| `default` | General Laravel queue work and fallback jobs. |

These workers are local child processes owned by the desktop app. They stop when the app closes. Users do not need to start them manually in the installed app.

ASTRA uses separate workers so long audio jobs do not block transcript polish, summaries, export dialogs, or other backend actions.

### Can The App Run With Only One Worker?

Only partly.

If there is only one worker and it listens only to `default`, then `audio` and `transcripts` jobs will not run. Upload processing, polishing, and summarizing can appear stuck because their queue jobs are never consumed.

If there is one worker configured to listen to all queues, for example `audio,transcripts,default`, the jobs can run, but only one job runs at a time. A long audio or diarization job can delay polish, summary, retry, cancel feedback, and other backend work.

For the packaged app, the supported setup is three workers: one for `audio`, one for `transcripts`, and one for `default`.

## For Developers

This repository is the desktop application. It includes the Laravel app, Tauri shell, frontend assets, local runtime scripts, packaging scripts, and update publishing workflow.

Useful commands:

```powershell
.\node\npm.cmd run setup:local
.\node\npm.cmd run dev:local
.\node\npm.cmd run tauri:build
.\node\npm.cmd run tauri:update-test
```

Developer workflow notes are kept short for now because ASTRA is still a young solo-maintained project. Client update artifacts are published separately from source work as signed installer assets and `latest.json`.

## Project Status

ASTRA is a new project and is currently maintained by one developer. Contributions are not the focus right now; the priority is making the Windows desktop workflow stable, understandable, and useful for real transcription work.

Issues and suggestions are still welcome, especially when they include clear reproduction steps, logs, or a specific workflow that failed.

## Related Repositories

- ASTRA Desktop Application: <https://github.com/CodeBreaker822/AILiveTranscriber>
- ASTRA Client Updater Repository: <https://github.com/CodeBreaker822/AITranscriberAPP>
- ASTRA Transcription Server: <https://github.com/CodeBreaker822/ASTRA_Manager>
- Serverless Transcription Worker: <https://github.com/CodeBreaker822/ServerlessRunpodTranscript>

## License

MIT License.

---

ASTRA is built for the practical work after someone says, "Can we get this meeting written down?"
