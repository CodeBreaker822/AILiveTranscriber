<div align="center">

# ASTRA / AITranscriber

### Live and uploaded audio transcription for meetings, hearings, interviews, reports, and public-service documentation.

<p>
  <img src="https://img.shields.io/badge/Live%20Recording-22d3ee?style=for-the-badge" alt="Live Recording">
  <img src="https://img.shields.io/badge/Audio%20Upload-34d399?style=for-the-badge" alt="Audio Upload">
  <img src="https://img.shields.io/badge/Online%20%2B%20Offline-818cf8?style=for-the-badge" alt="Online and Offline">
  <img src="https://img.shields.io/badge/Speaker%20Diarization-fbbf24?style=for-the-badge" alt="Speaker Diarization">
</p>

**Agusan del Sur Transcription and Recording Assistant Empowering Smarter Governance Through Digital Documentation.**

</div>

---

## What ASTRA Does

ASTRA, also called AITranscriber in the codebase, is a desktop transcription application that turns spoken audio into organized written text.

It is designed for people who need usable documentation from real conversations, not just a plain text dump. Users can record live audio, upload long recordings, replay sections, review raw transcripts, identify speakers, and export results for reports or archives.

ASTRA is especially useful for:

- Government meetings and public consultations
- Interviews and investigations
- Lectures, trainings, and seminars
- Committee sessions and planning discussions
- Field recordings and voice notes
- Long audio files that need to be reviewed in sections

The goal is simple: record once, review clearly, and produce documentation faster.

---

## Why Use ASTRA Instead Of Generic Transcription Tools?

Many transcription tools only accept an audio file, wait for a server, and return one large transcript. ASTRA is built around the actual workflow of reviewing and documenting long conversations.

### Benefits for normal users

- **Live and upload modes in one app**  
  Use the microphone for live sessions or upload existing recordings.

- **Works online or offline**  
  Online mode can use stronger hosted transcription providers. Offline mode can transcribe locally when internet access is poor or privacy is more important.

- **Long recordings are easier to manage**  
  Audio is split into sections, so users can see progress, retry failed parts, and review by time range.

- **Speaker labels help with meeting review**  
  Local speaker diarization can mark who spoke in different parts of the transcript.

- **Audio playback stays connected to transcript sections**  
  Users can replay the original audio for a section instead of searching through one long recording.

- **Better for government and office documentation**  
  Project names, section ranges, export tools, logs, and retries are built for record keeping.

- **Privacy flexibility**  
  Sensitive recordings can be handled locally with offline transcription. Online mode remains available when users need stronger hosted models or faster processing.

- **Less manual transcription work**  
  ASTRA does not replace human review, but it reduces the time spent typing and organizing notes from scratch.

---

## Main App Modes

ASTRA has two main workflows:

```text
Live
Upload
```

Each workflow can use:

```text
Online transcription
Offline transcription
```

That gives the app four practical modes:

| Mode | Best for | How it works |
| --- | --- | --- |
| Live + Online | Meetings with internet access | Captures microphone audio and sends speech sections to the hosted transcription server. |
| Live + Offline | Live sessions without reliable internet | Captures microphone audio and transcribes locally with an installed Whisper model. |
| Upload + Online | Long recordings that need stronger hosted transcription | Splits the file, prepares speech sections locally, then sends prepared audio to the transcription server. |
| Upload + Offline | Private or low-connectivity recordings | Splits and processes the file locally with offline Whisper. |

---

## Live Transcription Architecture

Live mode is for recording while the session is happening.

```text
Microphone audio
    |
    v
Small live audio chunks
    |
    v
Local audio preparation
    |
    v
Silero VAD checks for speech
    |
    v
Transcription engine
    |-- Online: hosted transcription server
    `-- Offline: local Whisper model
    |
    v
Sherpa speaker diarization
    |
    v
Transcript section appears in the app
```

### What the user sees

- A live progress panel
- Transcript sections appearing as audio is processed
- Speaker labels when diarization is available
- Audio playback per saved section
- Export controls for saved transcript entries

### Why this helps

Live mode lets users document meetings while they happen. If one section fails, the whole session is not lost.

---

## Upload Transcription Architecture

Upload mode is for existing recordings such as MP3, AAC, WAV, M4A, OGG, or FLAC files.

```text
Uploaded or selected local audio file
    |
    v
Audio is divided into time sections
    |
    v
Each section is prepared with FFmpeg
    |
    v
Silero VAD removes or skips non-speech sections
    |
    v
Prepared speech audio is processed
    |-- Online: sent to transcription server
    `-- Offline: sent to local Whisper
    |
    v
Sherpa diarization can identify speakers
    |
    v
Transcript sections are saved and shown
    |
    v
User can polish, summarize, replay, or export
```

### Online upload flow

Online upload is designed to reduce unnecessary server work. The app first prepares audio locally before sending it out.

```text
Original recording
    |
    v
Local section extraction
    |
    v
Silero VAD speech detection
    |
    v
Only speech audio is prepared for transcription
    |
    v
Prepared audio is sent to the transcription server
    |
    v
Server result returns with timestamps/text
    |
    v
Speaker diarization result is merged when available
```

### Offline upload flow

Offline upload keeps transcription on the local machine.

```text
Original recording
    |
    v
Local section extraction
    |
    v
Silero VAD speech detection
    |
    v
Local Whisper transcription
    |
    v
Sherpa speaker diarization
    |
    v
Local transcript result
```

### Why this helps

Long recordings are easier to review because ASTRA processes and displays them as sections. Users can continue, retry, cancel, or inspect logs without losing the whole recording.

---

## Online vs Offline Mode

### Online mode

Online mode uses a hosted transcription server. It is best when:

- Internet is available
- Accuracy from stronger hosted providers is needed
- Users want online polish or summary features
- The local computer is slower or has limited resources

Online mode can use provider fallback through the transcription server. If one configured provider fails, the server can try another provider instead of immediately failing the whole transcript.

### Offline mode

Offline mode uses local models installed with the desktop app. It is best when:

- Internet is unavailable or unreliable
- The recording is sensitive
- The user wants local-only transcription
- The machine has enough CPU or compatible acceleration for local models

Offline mode currently focuses on transcription and local speaker diarization. Online-only polish and summarize controls are hidden when offline mode is selected because offline polish/summarize support has not been added yet.

---

## Model and Processing Components

ASTRA combines several tools so users do not need to manually prepare audio or run command-line transcription.

| Component | Purpose | Runs locally? |
| --- | --- | --- |
| FFmpeg | Converts, extracts, and prepares audio sections | Yes |
| Silero VAD | Detects speech and skips non-speech audio | Yes |
| Whisper | Converts speech to text | Online or offline, depending on mode |
| Sherpa-ONNX | Separates speakers and adds speaker labels | Yes |
| Transcription Server | Handles hosted providers and fallback | Online mode |
| Laravel | Local app backend, state, settings, processing routes | Yes |
| Tauri | Windows desktop shell for the app | Yes |

### FFmpeg

FFmpeg prepares audio into formats that the transcription and diarization engines can understand. This helps ASTRA handle many common recording formats.

### Silero VAD

Silero VAD detects whether a section contains speech. This avoids wasting time on silence or non-speech sections.

### Whisper

Whisper is the speech-to-text engine. ASTRA can use hosted Whisper-compatible providers online or local Whisper models offline.

### Sherpa-ONNX

Sherpa-ONNX performs speaker diarization. In simple terms, it helps answer: "which speaker said this part?"

### Transcription Server

The online server can connect to external transcription providers and apply fallback rules. This keeps the desktop app simpler and allows provider changes without rewriting the desktop workflow.

---

## Transcript Output

ASTRA stores transcript sections with useful metadata:

- Project or category name
- Time range
- Raw transcript text
- Optional cleaned transcript text
- Timestamps
- Speaker labels when available
- Audio playback link
- Processing status

This structure makes transcripts easier to review than one large wall of text.

---

## Raw, Polished, and Summary Views

### Raw transcript

The raw transcript is the direct transcription result. This is useful for accuracy checking.

### Polished transcript

Polishing is intended to clean up readability while preserving meaning. It is useful for reports, minutes, and documentation drafts.

### Summary

Summaries help users quickly understand long sessions without reading the full transcript first.

> Note: polish and summarize are currently online features. They are hidden in offline mode until offline support is added.

---

## Privacy and Reliability Design

ASTRA is designed for environments where internet availability, privacy, and long recordings matter.

- Online mode is available for stronger hosted processing.
- Offline mode is available for local processing.
- Long files are split into manageable sections.
- Failed sections can be retried without restarting everything.
- Logs help diagnose technical problems without exposing confusing details to normal users.
- Speaker diarization runs locally so speaker processing does not need to be sent to a third-party diarization service.

---

## High-Level System Architecture

```text
Desktop UI
    |
    v
Laravel local backend
    |
    v
Audio preparation services
    |-- FFmpeg
    `-- Silero VAD
    |
    v
Transcription path
    |-- Online: Transcription Server -> Provider fallback
    `-- Offline: Local Whisper model
    |
    v
Speaker diarization
    `-- Sherpa-ONNX local worker
    |
    v
Stored transcript sections
    |
    v
Review, replay, polish, summarize, export
```

---

## Typical User Workflow

1. Open ASTRA.
2. Choose Live or Upload.
3. Enter a project name.
4. Select Online or Offline mode.
5. Start recording or choose an audio file.
6. Watch progress as sections are processed.
7. Review transcript sections.
8. Replay audio sections when needed.
9. Export the transcript when ready.
10. Use polish or summarize in online mode if a cleaner output is needed.

---

## Current Notes

- Offline transcription requires an installed supported Whisper model.
- Speaker diarization requires the Sherpa diarization model.
- Online transcription requires the configured transcription server and provider access.
- Polish and summarize are currently online-only.
- Upload processing is designed around sections so long files are easier to recover, retry, and review.

---

<div align="center">

**ASTRA helps turn spoken work into searchable, reviewable, and exportable documentation.**

</div>
