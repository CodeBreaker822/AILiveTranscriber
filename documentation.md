# AITranscriber Project Documentation

AITranscriber is a Laravel app for recording or uploading audio, transcribing it through the selected speech-to-text provider, storing each audio section in the database, and optionally producing a cleaned transcript through Gemini. Supported speech-to-text providers are ElevenLabs and Deepgram.

The app has three user-facing screens:

- Live transcription at `/`
- Uploaded audio transcription at `/upload`
- Settings at `/settings`

Most workflow orchestration lives in `resources/js/app.js`. The Laravel backend provides Blade pages, JSON endpoints, audio playback, database persistence, FFmpeg-based audio preparation, and AI service integrations.

## Routes

Defined in `routes/web.php`.

| Method | URI | Name | Handler | Purpose |
| --- | --- | --- | --- | --- |
| GET | `/` | `transcription.live` | Closure returning `welcome` | Live microphone recording screen. |
| GET | `/upload` | `transcription.upload` | Closure returning `pages.upload` | Long audio upload screen. |
| GET | `/settings` | `settings.edit` | `SettingsController@edit` | Settings page for SQL-backed provider configuration. |
| POST | `/settings` | `settings.update` | `SettingsController@update` | Saves provider API keys and the selected main speech-to-text provider into SQL. |
| POST | `/settings/audio-memory/temporary` | `settings.audio-memory.temporary.clear` | `AudioMemoryController@clearTemporary` | Deletes temporary uploaded source files and generated section files from private storage. |
| POST | `/settings/audio-memory/stored` | `settings.audio-memory.stored.clear` | `AudioMemoryController@clearStored` | Clears stored audio bytes from existing audio chunk rows. |
| POST | `/settings/audio-memory/all` | `settings.audio-memory.all.clear` | `AudioMemoryController@clearAll` | Clears temporary upload cache and stored audio bytes in one action. |
| POST | `/settings/transcript-memory` | `settings.transcript-memory.clear` | `TranscriptMemoryController@clear` | Clears raw transcript text, timestamps, and polished transcript rows. |
| GET | `/audio-chunks` | `audio-chunks.index` | `AudioChunkController@index` | Returns stored audio chunks and transcript metadata as JSON. |
| POST | `/audio-chunks` | `audio-chunks.store` | `AudioChunkController@store` | Stores and transcribes a live clip, or transcribes a prepared upload section when `upload_session_id` is present. |
| GET | `/audio-chunks/{audioChunk}/audio` | `audio-chunks.audio` | `AudioChunkController@audio` | Streams the stored audio blob for playback. |
| DELETE | `/audio-chunks/{audioChunk}` | `audio-chunks.destroy` | `AudioChunkController@destroy` | Deletes one stored audio chunk. |
| POST | `/audio-uploads` | `audio-uploads.store` | `UploadedAudioTranscriptionController@store` | Accepts a long audio file, creates a temporary upload session, probes duration, and returns planned sections. |
| POST | `/transcripts/furnish` | `transcripts.furnish` | `TranscriptFurnishController@store` | Polishes raw transcript chunks through Gemini using the user's instructions and stores cleaned rows. |

Laravel also exposes framework routes such as `/up` and local storage routes.

## Controllers

### `AudioChunkController`

Main persistence and playback controller for individual audio sections.

- `index()`: reads `audio_chunks` newest first and returns JSON rows with clip timing, category, status, audio playback URL, delete URL, raw transcript text, and word timestamps.
- `store()`: handles two modes:
  - Live recording mode, when an uploaded `audio` file is posted directly.
  - Uploaded-section mode, when `upload_session_id` is present and the request should extract a section from an uploaded long file.
- `storeUploadedSection()`: extracts the requested range from a prepared upload session using `AudioFileChunkerService`, sends the generated WAV to the selected speech-to-text provider, then inserts the result into `audio_chunks`.
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

Important request fields for upload-section mode:

- `upload_session_id`
- `user_id`
- `category_name`
- `clip_index`
- `clip_start_ms`
- `clip_end_ms`
- `range_label`
- `duration_ms`

### `UploadedAudioTranscriptionController`

Prepares a long uploaded audio file before per-section transcription.

- `store()`: validates either `audio_file` or `local_path` plus optional `chunk_seconds`, creates a temporary session through `AudioFileChunkerService`, builds section metadata, and returns the session id plus section list.

Accepted chunk sizes are 60, 120, or 300 seconds. The upload flow does not enforce an app-level audio file size limit. In Tauri, selected audio is referenced by local path instead of copied through HTTP; if the user deletes or moves the source file before processing finishes, later sections fail.

### `TranscriptFurnishController`

Cleans stored raw transcripts with Gemini.

- `store()`: validates `category_name`, optional `user_id`, and optional `window_index`.
- When `window_index` is present, only cleans one five-minute transcript window.
- When `window_index` is absent, walks all chunks for the category and cleans them by five-minute windows.
- Uses `clean_transcript_chunks` as a cache. Existing cleaned rows are returned without re-calling Gemini.
- Applies a 4-second pause between Gemini requests when cleaning multiple populated windows.

Key behavior:

- Raw rows come from `audio_chunks`.
- Clean rows are stored in `clean_transcript_chunks`.
- Polishing is grouped by `clip_start_ms` in five-minute windows.
- If no raw transcript rows exist for the category/window, the endpoint returns 404.

### `SettingsController`

Manages app-level provider settings.

- `edit()`: prepares all settings page display data, renders provider cards, shows whether ElevenLabs, Deepgram, and Gemini API keys are configured, displays fixed model values, and includes the current audio memory snapshot.
- `update()`: validates and saves provider API keys and the selected main speech-to-text provider through `AppSettingsService`.

The settings page allows users to replace the ElevenLabs, Deepgram, and Gemini API keys. Either ElevenLabs or Deepgram can be selected as the main speech-to-text provider. Gemini is optional and is only needed when users want to polish raw transcript text. The Deepgram and Gemini models are stored in SQL but shown as read-only values.

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

Shared HTML shell for both screens.

- Loads CSRF meta, jQuery, shared helpers, modal scripts from `public/js/modals`, Vite CSS, and `resources/js/app.js`.
- Sets `data-page` to either `live` or `upload`.
- Injects route URLs into `body` data attributes so the JavaScript can call Laravel endpoints without hard-coded URLs.
- Renders the shared header, page slot, and footer.
- Keeps the desktop window fixed to the viewport; page-level scrolling is disabled.
- Includes modal partials from `resources/views/modals` for transcript, pending audio, and Polish instructions.

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
- Large record/stop toggle.
- Progress-only live processing panel.
- Transcript button opening a right-side transcript drawer.
- Pending Audio button opening a separate right-side queue drawer.
- Transcript drawer controls for Polish, Raw/Cleaned mode, playback, delete, and export.

The JavaScript records microphone audio through `MediaRecorder`, splits it into 10-second clips, posts each clip to `POST /audio-chunks`, and refreshes stored transcript rows from `GET /audio-chunks`.

### `resources/views/pages/upload.blade.php`

Long audio upload interface.

Major UI areas:

- File picker and upload metadata.
- Category and chunk length controls.
- Start, Continue, Retry, and Cancel processing controls.
- Upload and Polish progress panels.
- Transcript button opening a right-side transcript drawer.
- Pending Audio button opening a separate right-side queue drawer.

### `resources/views/modals`

All modal and drawer markup belongs in this folder.

- `transcript-sidebar.blade.php`: transcript drawer and Polish/export controls.
- `pending-clips-sidebar.blade.php`: pending live or upload clips.
- `polish-instructions.blade.php`: Gemini instruction presets and custom instructions.

Related modal behavior belongs in `public/js/modals`, not in page templates or `resources/js/app.js`.

The JavaScript sends either the original browser-selected file or the Tauri-selected local file path to `POST /audio-uploads`, receives a session id and planned sections, then posts each section to `POST /audio-chunks` with `upload_session_id`.

### `resources/views/pages/settings.blade.php`

Provider settings interface.

Major UI areas:

- Main speech-to-text provider selector.
- ElevenLabs API key input.
- Deepgram API key input.
- Gemini API key input.
- Audio memory usage summary.
- Total audio cleanup.
- Temporary upload cache cleanup.
- Stored audio record cleanup.
- Transcript memory usage summary.
- Transcript text cleanup.
- Saved API keys are shown in the inputs after save; they are still encrypted at rest through the model cast.
- Purpose text for each provider: ElevenLabs and Deepgram handle audio transcription, Gemini handles optional transcript cleanup.
- Provider health cards with Connected, Not connected, or Invalid states.
- Read-only Gemini model value.
- Save and test button.

Saving settings tests configured API keys immediately and stores the latest provider health result.

Provider card labels, colors, details, and status formatting are prepared in `SettingsController`; the Blade view should stay presentation-only.

Audio memory values come from `AudioMemoryService`; transcript memory values come from `TranscriptMemoryService`; the Blade view should only render the prepared totals and forms.

## Client-Side Workflow

Processing and transcript rendering logic is in `resources/js/app.js`. Modal presentation behavior is kept separately in `public/js/modals`.

### Live Page Flow

1. User chooses or types a category.
2. User starts recording.
3. Browser captures microphone audio with `MediaRecorder`.
4. The script creates 10-second clip blobs.
5. Each clip is queued and posted to `POST /audio-chunks`.
6. The backend sends the clip to the selected speech-to-text provider and stores the audio blob, transcript text, timestamps, and metadata.
7. The frontend refreshes stored rows from `GET /audio-chunks`.
8. User can play/delete clips, polish the transcript with their own Gemini instructions, and export raw or cleaned text.

### Upload Page Flow

1. User selects a long audio file, category, and chunk length.
2. The frontend estimates duration locally for display.
3. User starts processing.
4. `POST /audio-uploads` stores the browser-uploaded source file or records the Tauri-selected local source path in a temporary session, then returns section ranges.
5. The frontend posts each section to `POST /audio-chunks` with `upload_session_id`.
6. The backend extracts the requested segment using FFmpeg, transcribes it through the selected speech-to-text provider, and stores it in `audio_chunks`.
7. The frontend tracks completion, supports continue/retry/cancel, and stores resumable upload state in `localStorage` under `ai-transcriber-upload-session`.
8. User can request Gemini cleanup and export raw or cleaned transcript text.

## Services

### `AudioFileChunkerService`

Uses bundled FFmpeg binaries:

- `ffmpeg/bin/ffmpeg.exe`
- `ffmpeg/bin/ffprobe.exe`

Responsibilities:

- Create upload sessions in `storage/app/private/audio-upload-sessions/{uuid}`.
- Move uploaded source audio into the session directory.
- Reference Tauri-selected local source files by path without copying them into app storage.
- Probe duration with FFprobe.
- Build section metadata for 60, 120, or 300 second chunks.
- Extract a specific section as mono 16kHz WAV.
- Keep legacy `split()` and `cleanup()` helpers for full-file splitting.

### `ElevenLabsSpeechToTextService`

Wraps ElevenLabs Speech-to-Text.

Configuration comes from SQL and `config/services.php`:

- `elevenlabs.api_key` from `app_settings`
- `ELEVENLABS_SPEECH_TO_TEXT_URL`
- `ELEVENLABS_SPEECH_TO_TEXT_MODEL`
- `ELEVENLABS_TIMEOUT`

The normalized return shape is:

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
]
```

### `DeepgramSpeechToTextService`

Wraps Deepgram's pre-recorded Listen API.

Configuration comes from SQL and `config/services.php`:

- `deepgram.api_key` from `app_settings`
- `deepgram.model` from `app_settings`
- `DEEPGRAM_LISTEN_URL`
- `DEEPGRAM_LANGUAGE`
- `DEEPGRAM_TIMEOUT`

The fixed Deepgram model is `nova-3`.

The normalized return shape matches `ElevenLabsSpeechToTextService`:

```php
[
    'text' => 'Transcript text',
    'timestamps' => [
        [
            'text' => 'word',
            'start' => 0.0,
            'end' => 0.5,
            'type' => 'word',
            'speaker_id' => 'speaker_0',
        ],
    ],
]
```

### `SpeechToTextService`

Routes transcription requests to either ElevenLabs or Deepgram based on `speech_to_text.provider` in `app_settings`.

### `GeminiTranscriptCleanerService`

Wraps Gemini `generateContent` for transcript cleanup.

Configuration comes from SQL and `config/services.php`:

- `gemini.api_key` from `app_settings`
- `gemini.model` from `app_settings`
- `GEMINI_BASE_URL`
- `gemini.timeout` from `app_settings`
- `gemini.max_retries` from `app_settings`
- `GEMINI_RPM_LIMIT`
- `GEMINI_RATE_LIMIT_KEY`

Responsibilities:

- Remove filler words and false starts.
- Fix grammar and punctuation without changing meaning.
- Preserve chunk ids and timestamp structure.
- Return strict JSON from model responses.
- Cache a global per-minute rate counter through Laravel cache.
- Log request context and redacted endpoint information.

### `AppSettingsService`

Database-backed settings helper.

Responsibilities:

- Store and retrieve global app settings from `app_settings`.
- Store values through the `AppSetting` model, whose `value` attribute uses Laravel's encrypted cast.
- Store API keys in SQL:
  - `elevenlabs.api_key`
  - `deepgram.api_key`
  - `gemini.api_key`
- Store provider health results in SQL:
  - `elevenlabs.status`
  - `deepgram.status`
  - `gemini.status`
- Store the selected speech-to-text provider in SQL:
  - `speech_to_text.provider`: `elevenlabs` or `deepgram`
- Keep fixed Deepgram settings in SQL:
  - `deepgram.model`: `nova-3`
- Keep fixed Gemini settings in SQL:
  - `gemini.model`: `gemini-3.1-flash-lite`
  - `gemini.timeout`: `30`
  - `gemini.max_retries`: `3`
- Return safe defaults when the settings table is not migrated yet.

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

### `ProviderApiTestService`

Tests saved provider API keys and returns normalized health data for the settings cards.

Responsibilities:

- Test ElevenLabs with the speech-to-text endpoint using an intentionally incomplete request, so the key can authenticate without transcribing audio or spending credits.
- Fetch ElevenLabs subscription details separately when the key has access, including tier, character usage, character limit, characters left, and billing period.
- Treat missing ElevenLabs subscription access as connected when the speech-to-text authentication probe succeeds.
- Test Gemini with the model list endpoint.
- Store Gemini model availability.
- Test Deepgram with the pre-recorded Listen endpoint using an intentionally incomplete request, so the key can authenticate without transcribing audio.
- Return one of three statuses: `connected`, `not_connected`, or `invalid`.
- Provider connection tests use a short timeout so the settings save does not feel stuck when a provider is unreachable.

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
- `model`
- `status`
- timestamps

`audio_chunk_id` is unique and cascades on delete.

### `app_settings`

Created by `2026_06_16_000001_create_app_settings_table.php`.

Stores provider and model settings.

Values are written through the `AppSetting` model and encrypted by its `value` cast.

Important columns:

- `key`
- `value`
- `is_encrypted`
- timestamps

Important keys:

- `elevenlabs.api_key`
- `elevenlabs.status`
- `deepgram.api_key`
- `deepgram.status`
- `deepgram.model`
- `speech_to_text.provider`
- `gemini.api_key`
- `gemini.status`
- `gemini.model`
- `gemini.timeout`
- `gemini.max_retries`

## Environment And Runtime Notes

This project includes local Windows runtimes:

- `php/php.exe`
- `node/node.exe`
- `node/npm.cmd`
- `composer.phar`
- `ffmpeg/bin/ffmpeg.exe`
- `ffmpeg/bin/ffprobe.exe`

Useful commands:

```powershell
.\php\php.exe artisan route:list
.\php\php.exe artisan migrate
.\php\php.exe artisan test
.\node\npm.cmd run build
.\php\php.exe artisan serve --host=127.0.0.1 --port=8010
.\node\npm.cmd run tauri:build
.\node\npm.cmd run tauri:build:empty
```

`tauri:build` packages the prepared default database. `tauri:build:empty` creates and packages a fresh migrated database with no API keys, provider settings, transcript rows, audio rows, or other user data.

Required AI service configuration:

- ElevenLabs and Deepgram API keys are configured from the Settings page and stored encrypted in SQL.
- The main speech-to-text provider is configured from the Settings page.
- Gemini API key is configured from the Settings page and stored encrypted in SQL when transcript cleanup is needed.
- Deepgram model is stored in SQL and fixed to `nova-3`.
- Gemini model, timeout, and retry values are stored in SQL and fixed to `gemini-3.1-flash-lite`, `30`, and `3`.
- Saving settings tests configured providers and stores the latest connection status.

Optional service settings:

```env
ELEVENLABS_SPEECH_TO_TEXT_MODEL=scribe_v2
ELEVENLABS_TIMEOUT=120
DEEPGRAM_TIMEOUT=120
DEEPGRAM_LANGUAGE=multi
GEMINI_RPM_LIMIT=15
```

## Tests

Current focused tests cover:

- `AudioFileChunkerService` segment extraction with bundled FFmpeg.
- `ElevenLabsSpeechToTextService` request/response normalization and model validation.
- `DeepgramSpeechToTextService` request/response normalization and model validation.
- `GeminiTranscriptCleanerService` single transcript and chunked transcript cleanup normalization.

Run with:

```powershell
.\php\php.exe artisan test
```

## Future Modification Notes

- Route URLs are passed to JavaScript through `app-layout.blade.php` body attributes. Update those attributes when adding frontend endpoints.
- Provider settings are stored in `app_settings`; do not add the ElevenLabs, Deepgram, or Gemini API keys back to `.env`.
- Audio memory controls are server-rendered forms on the Settings page, not JavaScript-driven controls.
- Temporary upload cache cleanup removes private storage files only.
- Stored audio cleanup clears audio bytes in `audio_chunks`; transcript text cleanup clears transcript fields and removes `clean_transcript_chunks` rows.
- The live page assumes 10-second recording segments in `resources/js/app.js`.
- Transcript polishing uses five-minute windows in `TranscriptFurnishController`.
- Raw and cleaned export behavior is frontend-only; exported files are generated in the browser.
- Audio blobs are stored directly in the database. Large files are handled by storing only extracted sections in `audio_chunks`, while the original upload lives temporarily under private storage.
- There is no dedicated `AudioChunk` Eloquent model; controllers currently use the query builder directly.
