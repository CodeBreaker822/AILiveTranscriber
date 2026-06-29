# AITranscriber Project Documentation

AITranscriber is a Laravel app for recording or uploading audio, splitting it into manageable sections, filtering each section through local Silero VAD, sending speech-positive audio to a hosted transcription API, storing raw transcript data locally, and optionally producing polished transcript text through the same hosted API.

The local app is responsible for the desktop/web user interface, audio preparation, local voice-activity detection, local database persistence, playback, memory cleanup, and progress tracking. Provider credentials and direct AI-provider calls live behind the hosted API. The app talks to that server with a single license key.

The app has four user-facing screens:

- Live transcription at `/`
- Uploaded audio transcription at `/upload`
- Settings at `/settings`
- License key help at `/settings/api-key-help`

Most workflow orchestration lives in `resources/js/app.js`. The Laravel backend provides Blade pages, JSON endpoints, audio playback, database persistence, FFmpeg-based audio preparation, hosted API integration, and cleanup actions.

## Routes

Defined in `routes/web.php`.

| Method | URI | Name | Handler | Purpose |
| --- | --- | --- | --- | --- |
| GET | `/` | `transcription.live` | Closure returning `welcome` | Live microphone recording screen. |
| GET | `/upload` | `transcription.upload` | Closure returning `pages.upload` | Long audio upload screen. |
| GET | `/settings` | `settings.edit` | `SettingsController@edit` | Settings page for server URL, license key, provider/model selection, and memory cleanup. |
| POST | `/settings` | `settings.update` | `SettingsController@update` | Saves server URL/license key, checks license capabilities, and saves the selected speech-to-text provider/model. |
| GET | `/settings/api-key-help` | `settings.api-key-help` | `SettingsController@help` | Explains how to use the AITranscriber license key. |
| GET | `/app-update/connectivity` | `app-update.connectivity` | `AppUpdateController@connectivity` | Quietly checks hosted-server reachability before any update status request. |
| GET | `/app-update/status` | `app-update.status` | `AppUpdateController@status` | Compares the bundled local version text with the version returned by the hosted license status endpoint. |
| GET | `/app-update/download` | `app-update.download` | `AppUpdateController@download` | Authenticates, streams, and stages the hosted update ZIP for desktop installation. |
| POST | `/settings/audio-memory/temporary` | `settings.audio-memory.temporary.clear` | `AudioMemoryController@clearTemporary` | Deletes temporary uploaded source files and generated section files from private storage. |
| POST | `/settings/audio-memory/stored` | `settings.audio-memory.stored.clear` | `AudioMemoryController@clearStored` | Clears stored audio bytes from existing audio chunk rows. |
| POST | `/settings/audio-memory/all` | `settings.audio-memory.all.clear` | `AudioMemoryController@clearAll` | Clears temporary upload cache and stored audio bytes in one action. |
| POST | `/settings/transcript-memory` | `settings.transcript-memory.clear` | `TranscriptMemoryController@clear` | Clears raw transcript text, timestamps, and polished transcript rows. |
| GET | `/audio-chunks` | `audio-chunks.index` | `AudioChunkController@index` | Returns stored audio chunks and transcript metadata as JSON. |
| POST | `/audio-chunks` | `audio-chunks.store` | `AudioChunkController@store` | Stores and transcribes a live clip, or transcribes a prepared upload section when `upload_session_id` is present. |
| GET | `/audio-chunks/{audioChunk}/audio` | `audio-chunks.audio` | `AudioChunkController@audio` | Streams the stored audio blob for playback. |
| DELETE | `/audio-chunks/{audioChunk}` | `audio-chunks.destroy` | `AudioChunkController@destroy` | Deletes one stored audio chunk. |
| GET | `/audio-vad-logs` | `audio-vad-logs.index` | `AudioVadLogController@index` | Returns local VAD processing logs filtered by project name and source type. |
| POST | `/audio-uploads` | `audio-uploads.store` | `UploadedAudioTranscriptionController@store` | Accepts a long audio file or local Tauri file path, creates a temporary upload session, probes duration, and returns planned sections. |
| POST | `/transcripts/furnish` | `transcripts.furnish` | `TranscriptFurnishController@store` | Polishes raw transcript chunks through the hosted API using the user's instructions and stores cleaned rows. |

Laravel also exposes framework routes such as `/up` and local storage routes.

## Controllers

### `AudioChunkController`

Main persistence and playback controller for individual audio sections.

- `index()`: deletes old empty/no-speech transcript rows, reads `audio_chunks` newest first, and returns JSON rows with clip timing, category, source type, status, playback URL, delete URL, raw transcript text, and word timestamps.
- `store()`: handles two modes:
  - Live recording mode, when an uploaded `audio` file is posted directly.
  - Uploaded-section mode, when `upload_session_id` is present and the request should extract a section from an uploaded long file.
- `storeUploadedSection()`: extracts the requested range from a prepared upload session using `AudioFileChunkerService`, runs local Silero VAD, creates a speech-only WAV through FFmpeg when speech is present, sends the filtered WAV to the hosted transcription API through `SpeechToTextService`, then inserts the result into `audio_chunks`.
- `audio()`: returns the stored binary audio blob with the saved MIME type.
- `destroy()`: deletes one chunk by id.

Important request fields for live mode:

- `audio`
- `user_id`
- `category_name`
- `clip_index`
- `clip_start_ms`
- `clip_end_ms`
- `range_label`
- `duration_ms`
- `language_code`

Important request fields for upload-section mode:

- `upload_session_id`
- `user_id`
- `category_name`
- `clip_index`
- `clip_start_ms`
- `clip_end_ms`
- `range_label`
- `duration_ms`
- `language_code`

No-speech audio chunks are skipped before hosted transcription when local VAD finds no speech. Those skips are tracked in `audio_vad_logs`. Hosted API empty/no-speech transcript responses are still treated as a fallback skip.

### `AudioVadLogController`

Returns local VAD processing logs for the selected project.

- `index()`: validates `category_name` and optional `source_type`.
- Filters logs by exact project name and, when supplied, by `live` or `upload`.
- Returns original clip timing plus decoded speech segments.
- Adds absolute segment start/end milliseconds by offsetting each VAD segment with the original `clip_start_ms`.

The frontend Log buttons use this endpoint to show which exact speech ranges were merged and sent for transcription inside each original timestamp range.

### `UploadedAudioTranscriptionController`

Prepares a long uploaded audio file before per-section transcription.

- `store()`: validates either `audio_file` or `local_path` plus optional `chunk_seconds`, creates a temporary session through `AudioFileChunkerService`, builds section metadata, and returns the session id plus section list.

Accepted chunk sizes are 60, 120, or 300 seconds. The upload flow does not enforce an app-level audio file size limit. In Tauri, selected audio is referenced by local path instead of copied through HTTP; if the user deletes or moves the source file before processing finishes, later sections fail.

### `TranscriptFurnishController`

Cleans stored raw transcripts through the hosted polishing API.

- `store()`: validates `category_name`, optional `user_id`, optional `window_index`, and required `instructions`.
- When `window_index` is present, only cleans one five-minute transcript window.
- When `window_index` is absent, walks all chunks for the category and cleans them by five-minute windows.
- Existing cleaned rows for the window are deleted before re-polishing, so changed instructions produce fresh output.
- Applies a 4-second pause between populated hosted polishing requests when cleaning multiple windows.

Key behavior:

- Raw rows come from `audio_chunks`.
- Clean rows are stored in `clean_transcript_chunks`.
- Polishing is grouped by `clip_start_ms` in five-minute windows.
- `instruction_hash` stores the SHA-256 hash of the instructions used for the cleaned output.
- The hosted server selects the active polishing provider. The local app stores the returned `provider` and `model` with each cleaned row.
- If no raw transcript rows exist for the category, the endpoint returns 404.
- If a requested single window has no chunks, the endpoint returns a successful empty result.

### `SettingsController`

Manages hosted API settings and local provider/model selection.

- `edit()`: refreshes license capabilities when needed, prepares display data, renders server URL/license status, provider/model options loaded from the hosted API, and memory snapshots.
- `help()`: renders the license-key help page.
- `update()`: validates and saves the API base URL and license key, checks `/license/status` on the hosted API, stores the returned capabilities, and persists an available provider/model selection.

The settings page no longer stores direct ElevenLabs, Deepgram, Speechmatics, or Gemini API keys. It stores the hosted API base URL, a single license key, and the selected transcription provider/model exposed by the server.

### `AppUpdateController`

Provides the local endpoints used by the shared header update checker.

- `status()`: refreshes hosted license status and compares its `version` text exactly with the bundled local `version.json`.
- `connectivity()`: performs a short, unauthenticated reachability probe. Offline failures return only `online: false` and do not display errors.
- `download()`: streams `/transcribe/update/zipfile` through the authenticated hosted API client, writes the ZIP to private app storage, and returns download progress to the browser.
- The shared `modals.app-update` dialog starts automatically on Live, Upload, and Settings when the version text differs.
- On Windows, the Tauri `install_update` command closes the local PHP server and app, extracts the ZIP through an external PowerShell process, and restarts AITranscriber.

### `AudioMemoryController`

Handles cleanup actions from the Settings page.

- `clearTemporary()`: removes temporary upload-session directories under private storage, including uploaded source files and generated WAV sections left by cancelled or completed upload processing.
- `clearStored()`: clears audio blob bytes from `audio_chunks`, keeping transcript text and record metadata.
- `clearAll()`: clears both temporary audio cache and stored audio bytes without touching transcript text cleanup.

Both actions redirect back to Settings with a status message showing the amount removed.

### `TranscriptMemoryController`

Handles transcript text cleanup from the Settings page.

- `clear()`: clears `translated_text` and `transcription_timestamps` from `audio_chunks`, and deletes polished rows from `clean_transcript_chunks`.

The action keeps stored audio records in place.

## Views

### `resources/views/components/app-layout.blade.php`

Shared HTML shell for app screens.

- Loads CSRF meta, jQuery, shared helpers, modal scripts from `public/js/modals`, Vite CSS, and `resources/js/app.js`.
- Sets `data-page` to either `live` or `upload`.
- Injects route URLs and default values into `body` data attributes so JavaScript can call Laravel endpoints without hard-coded URLs.
- Renders the shared header, page slot, and footer.
- Keeps the desktop window fixed to the viewport; page-level scrolling is disabled.
- Includes modal partials from `resources/views/modals` for transcript, pending audio, and polish instructions.

Live page data attributes:

- `data-upload-url`
- `data-stored-url`
- `data-furnish-url`
- `data-default-user-id`
- `data-default-category-name`
- `data-play-url-base`
- `data-delete-url-base`

Upload page data attributes:

- `data-upload-audio-url`
- `data-audio-chunk-url`
- `data-furnish-url`
- `data-default-user-id`

### `resources/views/components/app-header.blade.php`

Shared navigation header.

- Links to Live and Upload pages.
- Includes a settings icon link.
- Highlights the active page using the `activePage` prop.
- Displays the app identity and short purpose text.

### `resources/views/components/app-footer.blade.php`

Small shared footer describing the workspace.

### `resources/views/welcome.blade.php`

Live transcription interface.

Major UI areas:

- Category input with suggestions.
- Language selector.
- Large record/stop toggle.
- Progress-only live processing panel.
- Transcript button opening a right-side transcript drawer.
- Pending Audio button opening a separate right-side queue drawer.
- Transcript controls for Polish, Raw/Cleaned mode, playback, delete, export, and VAD Log.

The JavaScript records microphone audio through `MediaRecorder`, splits it into one-minute clips, posts each clip to `POST /audio-chunks`, and refreshes stored transcript rows from `GET /audio-chunks`.

### `resources/views/pages/upload.blade.php`

Long audio upload interface.

Major UI areas:

- File picker and upload metadata.
- Category, language, and chunk length controls.
- Start, Continue, Retry, and Cancel processing controls.
- Upload and Polish progress panels.
- Transcript button opening a right-side transcript drawer.
- Pending Audio button opening a separate right-side queue drawer.
- Transcript controls for Raw/Cleaned mode, export, and VAD Log.

The JavaScript sends either the original browser-selected file or the Tauri-selected local file path to `POST /audio-uploads`, receives a session id and planned sections, then posts each section to `POST /audio-chunks` with `upload_session_id`.

### `resources/views/pages/settings.blade.php`

Hosted API settings and cleanup interface.

Major UI areas:

- API server URL input.
- License key input.
- License status message.
- Speech provider selector loaded from the hosted API.
- Model selector loaded from the hosted API.
- Audio memory usage summary.
- Total audio cleanup.
- Temporary upload cache cleanup.
- Stored audio record cleanup.
- Transcript memory usage summary.
- Transcript text cleanup.

Saved license keys are not rendered back into the input after save. The page only shows the saved key suffix.

### `resources/views/pages/api-key-help.blade.php`

Explains that users should paste the AITranscriber license key issued by the API Manager into Settings. This page is license-only and does not instruct users to manage direct provider API keys.

### `resources/views/modals`

All modal and drawer markup belongs in this folder.

- `transcript-sidebar.blade.php`: transcript drawer and Polish/export controls.
- `pending-clips-sidebar.blade.php`: pending live or upload clips.
- `polish-instructions.blade.php`: polish instruction presets and custom instructions.

Related modal behavior belongs in `public/js/modals`, not in page templates or `resources/js/app.js`.

## Client-Side Workflow

Processing and transcript rendering logic is in `resources/js/app.js`. Modal presentation behavior is kept separately in `public/js/modals`.

### Live Page Flow

1. User chooses or types a category.
2. User chooses a language from the current server-provided options.
3. User starts recording.
4. Browser captures microphone audio with `MediaRecorder`.
5. The script creates one-minute clip blobs.
6. Each clip is queued and posted to `POST /audio-chunks`.
7. The backend prepares the clip as mono 16kHz WAV, runs local Silero VAD, merges speech-positive ranges into a filtered WAV with FFmpeg, sends only that filtered audio to the hosted transcription API, and stores the filtered audio blob, transcript text, timestamps, and original clip metadata.
8. The frontend refreshes stored rows from `GET /audio-chunks`.
9. User can play/delete clips, polish the transcript with their own instructions, export raw or cleaned text, and open the Log view for the current project's local VAD decisions.

### Upload Page Flow

1. User selects a long audio file, category, language, and chunk length.
2. The frontend estimates duration locally for display.
3. User starts processing.
4. `POST /audio-uploads` stores the browser-uploaded source file or records the Tauri-selected local source path in a temporary session, then returns section ranges.
5. The frontend posts each section to `POST /audio-chunks` with `upload_session_id`.
6. The backend extracts the requested segment using FFmpeg, runs local Silero VAD, merges speech-positive ranges into a filtered WAV with FFmpeg, transcribes the filtered audio through the hosted API, and stores it in `audio_chunks`.
7. The frontend tracks completion, supports continue/retry/cancel, and stores resumable upload state in `localStorage` under `ai-transcriber-upload-session`.
8. User can request hosted transcript polishing, export raw or cleaned transcript text, and open the Log view for the current project's local VAD decisions.

## Services

### `HostedTranscriptionApiService`

Central client for the hosted AITranscriber API.

Configuration comes from SQL and `config/services.php`:

- `transcription_api.base_url` from `app_settings`, defaulting to `TRANSCRIPTION_API_BASE_URL` or `https://dilgaims.site/api`.
- `transcription_api.license_key` from `app_settings`.
- `TRANSCRIPTION_API_TIMEOUT`, defaulting to 120 seconds.

Responsibilities:

- Check license status through `GET /license/status`.
- Send audio to `POST /transcribe` with provider, model, language code, and clip timing metadata.
- Send transcript polishing requests to `POST /polish`.
- Normalize transcription responses into text, timestamps, provider, and model.
- Normalize polishing responses into cleaned text/chunks, timestamps, provider, and model.
- Use the saved license key as a bearer token.
- Convert hosted API failures into user-facing `SpeechToTextException` or `TranscriptPolisherException` messages.
- Log failed transcription request context without exposing secrets.

The normalized transcription return shape is:

```php
[
    'text' => 'Transcript text',
    'timestamps' => [
        [
            'text' => 'word',
            'start' => 0.0,
            'end' => 0.5,
            'type' => 'word',
            'speaker_id' => 'speaker_1',
        ],
    ],
    'provider' => 'provider-id',
    'model' => 'model-id',
]
```

### `SileroVadService`

Calls the standalone Rust `vad-cli` executable.

Configuration comes from `config/services.php`:

- `SILERO_VAD_BINARY`
- `SILERO_VAD_THRESHOLD`, defaulting to `0.5`
- `SILERO_VAD_MIN_SPEECH_MS`, defaulting to `250`
- `SILERO_VAD_MIN_SILENCE_MS`, defaulting to `500`
- `SILERO_VAD_SPEECH_PAD_MS`, defaulting to `80`
- `SILERO_VAD_TIMEOUT`, defaulting to `30`

The service searches these locations when no explicit binary path is configured:

- `vad/vad-cli.exe` in packaged Tauri resources.
- `build/vad/vad-cli.exe` after local release packaging.
- `vad-cli/target/release/vad-cli.exe`.
- `vad-cli/target/debug/vad-cli.exe`.

The CLI uses the Rust `silero` crate, which wraps the Silero VAD ONNX model through ONNX Runtime and bundles the model into the executable.

### `SpeechAudioFilterService`

Runs the local speech gate before hosted transcription.

Responsibilities:

- Call `SileroVadService` for each prepared mono 16kHz WAV.
- Skip hosted transcription when no speech is detected.
- Record VAD results in `audio_vad_logs`.
- Use FFmpeg `atrim` and `concat` filters to merge speech-positive ranges into one filtered WAV.
- Keep the original clip timing metadata, such as `01:00-02:00`, even when silence is removed from the audio sent to the hosted API.
- Return filtered audio metadata to the controller for transcription and storage.

### `SpeechToTextService`

Thin local wrapper around `HostedTranscriptionApiService`.

- `transcribe()`: forwards an uploaded file, file path, or `SplFileInfo` plus options to the hosted API client.

### `TranscriptPolisherService`

Thin local wrapper around `HostedTranscriptionApiService` polishing.

- `polish()`: forwards one transcript string plus timestamps and instructions to the hosted API client.
- `polishChunks()`: forwards transcript chunks plus instructions to the hosted API client.

`GeminiTranscriptCleanerService` remains as a compatibility wrapper, but new code should use `TranscriptPolisherService`. The local app no longer calls a vendor directly; the hosted server chooses the current polisher and returns provider/model metadata.

### `AppSettingsService`

Database-backed settings helper.

Responsibilities:

- Store and retrieve global app settings from `app_settings`.
- Store values through the `AppSetting` model, whose `value` attribute uses Laravel's encrypted cast.
- Store the hosted API base URL:
  - `transcription_api.base_url`
- Store the hosted API license key:
  - `transcription_api.license_key`
- Store license status/capabilities returned by the hosted API:
  - `transcription_api.license_status`
- Store the selected speech-to-text provider and model:
  - `speech_to_text.provider`
  - `speech_to_text.model`
- Derive provider, model, and language options from the saved license status payload.
- Validate requested language codes against the selected model's available languages.
- Return safe defaults when the settings table is not migrated yet.

### `AudioFileChunkerService`

Uses bundled FFmpeg binaries:

- `ffmpeg/bin/ffmpeg.exe`
- `ffmpeg/bin/ffprobe.exe`

Responsibilities:

- Create upload sessions in `storage/app/private/audio-upload-sessions/{uuid}`.
- Move browser-uploaded source audio into the session directory.
- Reference Tauri-selected local source files by path without copying them into app storage.
- Probe duration with FFprobe.
- Build section metadata for 60, 120, or 300 second chunks.
- Extract a specific upload section as mono 16kHz WAV.
- Prepare live clips as mono 16kHz WAV in a temporary live directory.
- Keep legacy `split()` and `cleanup()` helpers for full-file splitting.

### `AudioMemoryService`

Tracks and clears audio storage used by the app.

Responsibilities:

- Count stored audio records in `audio_chunks`.
- Sum stored audio bytes using `file_size_bytes`.
- Count and size temporary files under `storage/app/private/audio-upload-sessions`.
- Count and size legacy temporary files under `storage/app/private/audio-upload-chunks`.
- Delete temporary upload cache directories safely and recreate the roots.
- Clear stored audio blob bytes while keeping transcript text and record metadata.
- Clear all audio data by running temporary cache cleanup and stored audio cleanup together.

### `TranscriptMemoryService`

Tracks and clears transcript text storage used by the app.

Responsibilities:

- Count raw transcript records and text/timestamp bytes in `audio_chunks`.
- Count polished transcript records and text/timestamp bytes in `clean_transcript_chunks`.
- Clear `translated_text` and `transcription_timestamps` from stored audio records.
- Delete polished transcript rows from `clean_transcript_chunks`.
- Keep stored audio records in place when transcript text is cleared.

### `ServiceUserMessage`

Centralizes short user-facing error messages for common audio and provider failures.

## Database Tables

### `audio_chunks`

Created by `2026_06_08_000003_create_audio_chunks_table.php`.

Stores each raw audio section and raw transcript.

Important columns:

- `id`
- `user_id`
- `category_name`
- `clip_index`
- `clip_start_ms`
- `clip_end_ms`
- `range_label`
- `duration_ms`
- `mime_type`
- `original_name`
- `file_size_bytes`
- `audio_blob`
- `translated_text`
- `transcription_timestamps`
- `status`
- timestamps

Indexes:

- `category_name`, `clip_index`
- `user_id`, `category_name`

### `clean_transcript_chunks`

Created by `2026_06_11_000002_create_clean_transcript_chunks_table.php`.

Stores cleaned transcript output keyed one-to-one with `audio_chunks`.

Important columns:

- `audio_chunk_id`
- `user_id`
- `category_name`
- `clip_index`
- `clip_start_ms`
- `clip_end_ms`
- `range_label`
- `raw_text`
- `clean_text`
- `clean_timestamps`
- `provider`
- `model`
- `instruction_hash`
- `status`
- timestamps

`audio_chunk_id` is unique and cascades on delete. `instruction_hash` is indexed.

### `audio_vad_logs`

Created by `2026_06_25_000001_create_audio_vad_logs_table.php`.

Stores local VAD decisions before hosted transcription.

Important columns:

- `user_id`
- `category_name`
- `source_type`
- `clip_index`
- `clip_start_ms`
- `clip_end_ms`
- `range_label`
- `duration_ms`
- `speech_detected`
- `speech_duration_ms`
- `segment_count`
- `speech_segments`
- `input_name`
- `input_size_bytes`
- `filtered_name`
- `filtered_size_bytes`
- `status`
- `message`
- timestamps

No-speech rows are stored here for tracking instead of being inserted into `audio_chunks`.

### `app_settings`

Created by `2026_06_16_000001_create_app_settings_table.php`.

Stores hosted API, license, and provider/model settings.

Values are written through the `AppSetting` model and encrypted by its `value` cast.

Important columns:

- `key`
- `value`
- `is_encrypted`
- timestamps

Important keys:

- `transcription_api.base_url`
- `transcription_api.license_key`
- `transcription_api.license_status`
- `speech_to_text.provider`
- `speech_to_text.model`

## Environment And Runtime Notes

This project includes local Windows runtimes:

- `php/php.exe`
- `node/node.exe`
- `node/npm.cmd`
- `composer.phar`
- `ffmpeg/bin/ffmpeg.exe`
- `ffmpeg/bin/ffprobe.exe`
- `vad-cli/target/release/vad-cli.exe` during local development after `build:vad`
- `vad/vad-cli.exe` inside packaged Tauri resources

### Linux Development

Linux Bash must use the system `node`, `npm`, `php`, `ffmpeg`, and `ffprobe`
commands. Windows paths such as `.\node\npm.cmd` do not run in Bash.

Fedora development prerequisites:

```bash
sudo dnf install gcc gcc-c++ make webkit2gtk4.1-devel librsvg2-devel \
  libappindicator-gtk3-devel openssl-devel patchelf

curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh
source "$HOME/.cargo/env"
```

Install JavaScript dependencies and launch the local desktop application:

```bash
npm install
npm run tauri:dev
```

For browser-only local development, run:

```bash
npm run dev:local
```

The local supervisor starts Laravel at `http://127.0.0.1:8010`, the queue worker,
and Vite. The Linux Tauri binary is named `aitranscriber`; this avoids a filesystem
collision with the bundled Laravel `app/` resource directory.

Command meanings:

- `npm run build` builds frontend assets only. It does not create a desktop installer.
- `npm run tauri:dev` compiles and launches the local desktop development app.
- `npm run tauri:build:linux` creates Linux AppImage and deb packages.
- `npm run release:windows` creates the standalone Windows NSIS installer when run on Windows.

Linux package output is written below `src-tauri/target/release/bundle/`. Linux
packages currently use PHP, FFmpeg, and FFprobe from the target system's `PATH`;
the standalone bundled-runtime guarantee described below applies to Windows.

### Windows Development And Release

Windows local development commands:

```powershell
# Browser development: Laravel at http://127.0.0.1:8010 plus Vite and queue worker.
.\node\npm.cmd run dev:local

# Tauri desktop development with the same local services.
.\node\npm.cmd run tauri:dev
```

Build the standalone Windows NSIS `.exe` on Windows:

```powershell
.\node\npm.cmd install
.\node\npm.cmd run release:windows
```

The installer is written under:

```text
src-tauri\target\release\bundle\nsis\
```

After every desktop build, the builder asks the developer where to save a ZIP
for server-delivered in-place updates. Press Enter to use
`src-tauri/target/release/bundle/updates`, or set
`AITRANSCRIBER_UPDATE_OUTPUT_DIR` for non-interactive builds. The ZIP contains
the desktop executable, Laravel application code, compiled frontend assets,
Composer dependencies, VAD, and the platform runtimes needed by that build.
The builder also asks for release notes and creates or replaces `version.json`
beside the ZIP with the build version and notes. Automated builds can provide
the notes through `AITRANSCRIBER_UPDATE_NOTES`.

Update ZIPs never contain `.env`, `database/database.sqlite`, or `storage`.
The updater must stop AITranscriber before extracting the ZIP over the Windows
installation directory, then restart it. On startup, Laravel runs included
database migrations against the existing database stored in the user's app-data
directory. Linux AppImage releases should update by replacing the AppImage rather
than extracting the resource ZIP into the read-only mounted application.

Use `.\node\npm.cmd run release:windows:empty` when the installer must contain a
fresh migrated database without default license settings or user data. The normal
release command preserves configured default license settings while removing
transcript and audio rows from the bundled database snapshot.

The Windows package includes PHP, FFmpeg, FFprobe, the Windows VAD executable,
Laravel application files, Composer dependencies, frontend assets, and the prepared
SQLite database. End users do not need global PHP, Node, Rust, or FFmpeg installs.

Equivalent direct Windows commands remain available:

```powershell
.\php\php.exe artisan route:list
.\php\php.exe artisan migrate
.\php\php.exe artisan test
.\node\npm.cmd run build
.\node\npm.cmd run build:vad
.\php\php.exe artisan serve --host=127.0.0.1 --port=8010
.\node\npm.cmd run release:windows
.\node\npm.cmd run release:windows:empty
```

`release:windows` packages the prepared default database and built VAD CLI.
`release:windows:empty` creates and packages a fresh migrated database with no
license settings, transcript rows, audio rows, or other user data.

Required hosted API configuration:

- API base URL is configured from Settings and stored encrypted in SQL.
- License key is configured from Settings and stored encrypted in SQL.
- Saving Settings calls the hosted API license status endpoint and stores the latest capabilities.
- Available providers, models, and languages are derived from the hosted API response.
- The selected speech-to-text provider and model are stored locally.

Optional service settings:

```env
TRANSCRIPTION_API_BASE_URL=https://dilgaims.site/api
TRANSCRIPTION_API_TIMEOUT=120
SILERO_VAD_THRESHOLD=0.5
SILERO_VAD_MIN_SPEECH_MS=250
SILERO_VAD_MIN_SILENCE_MS=500
SILERO_VAD_SPEECH_PAD_MS=80
SILERO_VAD_TIMEOUT=30
```

## Tests

Current focused tests cover:

- `AudioFileChunkerService` upload sessions, local-path sessions, and segment extraction with bundled FFmpeg.
- `AudioChunkController` local-VAD no-speech skip/delete behavior.
- `AudioVadLogController` project-scoped VAD logs and absolute segment timestamp mapping.
- `AppSettingsService` license suffix display and provider/model/language selection from license status.
- `HostedTranscriptionApiService` license status checks, bearer-token requests, transcription request payloads, and polishing request payloads.
- Settings license auto-refresh and blank-license replacement behavior.
- License key help page copy.
- Transcript polishing UI availability.
- `TranscriptFurnishController` instruction-specific polishing storage.
- Bundled default settings sync for packaged builds.

Run with:

```powershell
.\php\php.exe artisan test
```

## Future Modification Notes

- Route URLs are passed to JavaScript through `app-layout.blade.php` body attributes. Update those attributes when adding frontend endpoints.
- Direct vendor API keys should stay on the hosted API server. Do not add ElevenLabs, Deepgram, Speechmatics, or Gemini API keys back to the local app.
- Provider/model/language choices should continue to come from the hosted license status payload.
- Local Silero VAD should run before hosted transcription. No-speech clips should be tracked in `audio_vad_logs` and skipped before the hosted API call.
- The speech-only filtered WAV is what gets sent to the hosted API and stored in `audio_chunks`; the clip range metadata remains the original timeline range.
- The frontend Log buttons call `/audio-vad-logs` with the current project name and page source type, then show absolute speech start/end ranges inside each original clip range.
- Tauri packaging depends on `build/vad`, which is prepared by `app:build-vad-cli` through the `build:tauri` and `build:tauri:empty` scripts.
- Audio memory controls are server-rendered forms on the Settings page, not JavaScript-driven controls.
- Temporary upload cache cleanup removes private storage files only.
- Stored audio cleanup clears audio bytes in `audio_chunks`; transcript text cleanup clears transcript fields and removes `clean_transcript_chunks` rows.
- The live page assumes one-minute recording segments in `resources/js/app.js`.
- Transcript polishing uses five-minute windows in `TranscriptFurnishController`.
- Polishing instructions are required and are tracked through `instruction_hash`.
- Raw and cleaned export behavior is frontend-only; exported files are generated in the browser.
- Audio blobs are stored directly in the database. Large files are handled by storing only extracted sections in `audio_chunks`, while the original upload lives temporarily under private storage.
- There is no dedicated `AudioChunk` Eloquent model; controllers currently use the query builder directly.
