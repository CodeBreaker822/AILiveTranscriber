# Transcription API Integration Guide

## Overview
The Transcription API lets standalone systems send audio clips to this web app and receive transcription or polished transcript output through a license-key protected REST API.

Supported operations:
- Check license and provider capability status.
- Transcribe an uploaded audio clip using Deepgram, ElevenLabs, or Speechmatics.
- Polish raw transcript text or transcript chunks using Gemini.

Base URL:
```text
https://your-domain.com/api
```

Replace `https://your-domain.com` with the production domain of this system.

## Authentication
All endpoints require a license key in the `Authorization` header.

```http
Authorization: Bearer LICENSE_KEY
```

The license key is generated in the system API Manager. The consuming app should store this key securely and send it with every request.

## Provider Summary

| Provider | Purpose | Model |
| --- | --- | --- |
| Deepgram | Speech to text | `nova-3` |
| ElevenLabs | Speech to text | `scribe_v2` |
| Speechmatics | Speech to text | `melia-1` or `enhanced` |
| Gemini | Transcript polishing only | `gemini-3.1-flash-lite` |

Do not hardcode provider language options in consuming apps. Call `GET /api/license/status` and use the returned `providers.transcription[].models[].languages` list. Gemini does not return a transcription language list because it is only used for polishing.

## Rate Limit
The API allows up to `120` transcription API requests per minute per license key.

When the license is rate-limited, the API returns:

```json
{
  "message": "License key is rate-limited.",
  "retry_after": 30
}
```

HTTP status: `429`

## Check License and Capabilities

```http
GET /api/license/status
Authorization: Bearer LICENSE_KEY
```

Use this endpoint before starting a transcription session. It tells the client whether the key is valid, whether the key can use the API, which providers are connected, and which language codes are available per provider model.

Example response:

```json
{
  "valid": true,
  "active": true,
  "expired": false,
  "rate_limited": false,
  "app_name": "Standalone Transcriber",
  "version": "1.0.4",
  "notes": "Fixed transcription upload and added Groq fallback.",
  "rate_limit": {
    "limit_per_minute": 120,
    "retry_after": 0
  },
  "allowed_methods": {
    "post": true,
    "get": true,
    "put": false,
    "patch": false,
    "delete": false
  },
  "apis": {
    "license_status": {
      "method": "GET",
      "path": "/api/license/status",
      "allowed": true
    },
    "transcribe": {
      "method": "POST",
      "path": "/api/transcribe",
      "allowed": true,
      "providers": ["deepgram", "elevenlabs", "speechmatics"],
      "fields": ["audio", "provider", "language_code", "clip_index", "clip_start_ms", "clip_end_ms"]
    },
    "polish": {
      "method": "POST",
      "path": "/api/polish",
      "allowed": true,
      "provider": "gemini",
      "model": "gemini-3.1-flash-lite",
      "fields": ["text", "timestamps", "chunks", "instruction"]
    }
  },
  "providers": {
    "transcription": [
      {
        "provider": "deepgram",
        "name": "Deepgram",
        "purpose": "Speech to text",
        "configured": true,
        "enabled": true,
        "connected": true,
        "models": [
          {
            "id": "nova-3",
            "label": "Nova-3",
            "language_code_parameter": "language_code",
            "default_language_code": "multi",
            "language_code_required": false,
            "accepts_custom_language_code": false,
            "languages": [
              { "code": "multi", "label": "Multilingual" },
              { "code": "en", "label": "English" },
              { "code": "tl", "label": "Tagalog" }
            ]
          }
        ]
      }
    ],
    "polishing": [
      {
        "provider": "gemini",
        "name": "Gemini",
        "purpose": "Transcript polishing",
        "configured": true,
        "enabled": true,
        "connected": true,
        "models": [
          {
            "id": "gemini-3.1-flash-lite",
            "label": "gemini-3.1-flash-lite"
          }
        ]
      }
    ]
  }
}
```

Notes:
- `version` and `notes` describe the current downloadable desktop update.
- `providers.transcription[].connected` should be `true` before the consuming app offers that provider.
- `apis.transcribe.allowed` must be `true` before sending audio clips.
- `apis.polish.allowed` must be `true` before sending transcript text to Gemini.
- The `languages` array in the response is the complete selectable language list for that provider model.

## Download Desktop Update

```http
GET /api/transcribe/update/zipfile
Authorization: Bearer LICENSE_KEY
Accept: application/zip
```

The response is the ZIP advertised by `version` and `notes` in the license status
response. The desktop app compares the version text exactly with its bundled
`version.json`, downloads the ZIP when they differ, and installs it after stopping
the running application. The ZIP must not contain `.env`, `storage`, or
`database/database.sqlite`.

## Transcribe Audio

```http
POST /api/transcribe
Authorization: Bearer LICENSE_KEY
Content-Type: multipart/form-data
```

Fields:

| Field | Required | Type | Description |
| --- | --- | --- | --- |
| `audio` | Yes | File | Audio clip file. Maximum upload size is 500 MB. |
| `provider` | Yes | String | `deepgram`, `elevenlabs`, or `speechmatics`. |
| `language_code` | No | String | Language code selected from `GET /api/license/status`. Required for Speechmatics `melia-1`, where it must be `multi`. |
| `clip_index` | No | Integer | Zero-based or one-based clip number from the client app. |
| `clip_start_ms` | No | Integer | Clip start time in milliseconds. |
| `clip_end_ms` | No | Integer | Clip end time in milliseconds. |

Example request:

```bash
curl -X POST "https://your-domain.com/api/transcribe" \
  -H "Authorization: Bearer LICENSE_KEY" \
  -F "audio=@chunk_00007.wav" \
  -F "provider=deepgram" \
  -F "language_code=multi" \
  -F "clip_index=7" \
  -F "clip_start_ms=360000" \
  -F "clip_end_ms=420000"
```

Example response:

```json
{
  "text": "transcribed text here",
  "timestamps": [],
  "provider": "deepgram",
  "model": "nova-3",
  "clip_index": 7,
  "clip_start_ms": 360000,
  "clip_end_ms": 420000
}
```

### Provider Language Rules

Deepgram `nova-3`:
- Recommended default language code: `multi`.
- Use the language options returned by `GET /api/license/status`.

ElevenLabs `scribe_v2`:
- Language code is optional.
- `multi` and `multilingual` are treated as auto-detect and are not sent to ElevenLabs.
- Custom language codes may be accepted, but the client should prefer the returned language list.

Speechmatics `melia-1`:
- Language code must be `multi`.
- The server forces `multi` for this model.

Speechmatics `enhanced`:
- Recommended default language code: `auto`.
- Use the language options returned by `GET /api/license/status`.

## Polish Transcript

```http
POST /api/polish
Authorization: Bearer LICENSE_KEY
Content-Type: application/json
```

Send either a single transcript in `text` or multiple transcript clips in `chunks`.

### Polish Single Transcript

Request:

```json
{
  "text": "raw transcript text here",
  "timestamps": [],
  "instruction": "Clean the transcript, fix punctuation, and preserve the original meaning."
}
```

Response:

```json
{
  "text": "polished transcript text here",
  "timestamps": [],
  "provider": "gemini",
  "model": "gemini-3.1-flash-lite"
}
```

### Polish Transcript Chunks

Request:

```json
{
  "chunks": [
    {
      "audio_chunk_id": 7,
      "clip_index": 7,
      "range_label": "06:00 - 07:00",
      "text": "raw transcript text here",
      "timestamps": []
    }
  ],
  "instruction": "Clean punctuation and grammar while keeping names and numbers unchanged."
}
```

Response:

```json
{
  "chunks": [
    {
      "audio_chunk_id": 7,
      "text": "polished transcript text here",
      "timestamps": []
    }
  ],
  "provider": "gemini",
  "model": "gemini-3.1-flash-lite"
}
```

Rules:
- Send `text` or `chunks`.
- If both are empty, the API returns `422`.
- Gemini is only used for polishing, not direct audio transcription.

## Error Responses

Invalid or missing license:

```json
{
  "message": "Missing Bearer license key."
}
```

HTTP status: `401`

Inactive license:

```json
{
  "message": "License key is inactive."
}
```

HTTP status: `403`

Method not allowed for license:

```json
{
  "message": "License key cannot use POST requests."
}
```

HTTP status: `403`

Validation error:

```json
{
  "message": "The audio field is required.",
  "errors": {
    "audio": ["The audio field is required."]
  }
}
```

HTTP status: `422`

Provider error:

```json
{
  "message": "Deepgram rejected the configured API key. Please check the API key in settings."
}
```

HTTP status depends on the provider response.

## Recommended Client Flow

1. Call `GET /api/license/status`.
2. If `valid`, `active`, and `apis.transcribe.allowed` are true, load available providers from `providers.transcription`.
3. Show only providers where `connected` is true.
4. Show the language list from the selected provider model.
5. Upload audio clips one at a time to `POST /api/transcribe`.
6. Store the returned `text`, `timestamps`, provider, model, and clip metadata.
7. Send the raw text or chunks to `POST /api/polish` if cleaned transcript output is needed.
8. If a request returns `429`, wait for `retry_after` seconds before retrying.

## JavaScript Example

```js
const baseUrl = "https://your-domain.com/api";
const licenseKey = "LICENSE_KEY";

async function getStatus() {
  const response = await fetch(`${baseUrl}/license/status`, {
    headers: {
      Authorization: `Bearer ${licenseKey}`,
      Accept: "application/json",
    },
  });

  return response.json();
}

async function transcribeClip(file, provider, languageCode, clip) {
  const form = new FormData();
  form.append("audio", file);
  form.append("provider", provider);
  form.append("language_code", languageCode);
  form.append("clip_index", clip.index);
  form.append("clip_start_ms", clip.startMs);
  form.append("clip_end_ms", clip.endMs);

  const response = await fetch(`${baseUrl}/transcribe`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${licenseKey}`,
      Accept: "application/json",
    },
    body: form,
  });

  return response.json();
}

async function polishTranscript(text, timestamps = []) {
  const response = await fetch(`${baseUrl}/polish`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${licenseKey}`,
      Accept: "application/json",
      "Content-Type": "application/json",
    },
    body: JSON.stringify({
      text,
      timestamps,
      instruction: "Clean punctuation and grammar while preserving the meaning.",
    }),
  });

  return response.json();
}
```

## Production Setup Notes

Before other systems can connect:

1. Run production migrations.
2. Generate a license key in API Manager.
3. Enable `POST` access for that license key.
4. Add provider API keys in API Manager.
5. Keep only the providers that should be usable enabled.
6. Share only the license key and API base URL with consuming systems.

Never expose Deepgram, ElevenLabs, Speechmatics, or Gemini provider API keys to client apps. Client apps should only know this system's license key.
