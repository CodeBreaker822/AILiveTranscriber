# Tauri Features Roadmap for AILiveTranscriber

## Purpose

This document summarizes the Tauri features that will provide the
greatest improvements to **AILiveTranscriber** in terms of security,
maintainability, performance, and development speed.

------------------------------------------------------------------------

# 1. Highest Priority

## Official Tauri Updater ⭐⭐⭐⭐⭐

Replace the custom updater with Tauri's signed updater.

**Benefits** - Signed updates - Automatic version checking - Download
progress - Secure installation - Automatic relaunch - GitHub Releases
support

------------------------------------------------------------------------

## Single Instance ⭐⭐⭐⭐⭐

Prevent multiple copies of the application from running.

**Benefits** - Prevent duplicate PHP servers - Prevent SQLite
conflicts - Prevent duplicate background jobs

------------------------------------------------------------------------

## Sidecars ⭐⭐⭐⭐⭐

Bundle these as official Tauri sidecars:

-   PHP
-   FFmpeg
-   Whisper
-   Sherpa

**Benefits** - Better process management - Reliable executable paths -
Proper process cancellation - Easier logging

**Current scope decision**

Do not convert binaries to sidecars unless the sidecar improves process
ownership or fixes a real packaged-path problem.

-   PHP is the only realistic sidecar candidate because Tauri starts and
    stops the Laravel server and queue workers. It must not be split from
    its adjacent DLLs, `php.ini`, extensions, and CA bundle.
-   FFmpeg stays as a bundled resource for now. Laravel already invokes it
    as a short-lived CLI process, so sidecaring it would not make audio
    processing faster.
-   Whisper stays native inside the Tauri executable. The offline worker is
    already a supervised child process of the app executable.
-   Sherpa stays as bundled DLLs and models. The executable surface is the
    VAD CLI, which Laravel already runs directly from bundled resources.

------------------------------------------------------------------------

## Capabilities & Permissions ⭐⭐⭐⭐⭐

Restrict:

-   Filesystem access
-   Shell execution
-   Network access
-   Plugin permissions

This greatly reduces the impact of any frontend vulnerability.

------------------------------------------------------------------------

## Separate User Data ⭐⭐⭐⭐⭐

Move user data outside the installation directory.

Suggested layout:

``` text
%LOCALAPPDATA%/
    AILiveTranscriber/
        data/
            database.sqlite
            recordings/
            transcripts/
            logs/
            models/
```

------------------------------------------------------------------------

# 2. Security

## Stronghold

Store API tokens and sensitive credentials securely.

## Content Security Policy

Harden the desktop application against script injection.

## Rust Commands

Move privileged desktop operations into Rust instead of Laravel.

## Random Local Port

Launch the embedded Laravel server on a random localhost port with a
session token.

------------------------------------------------------------------------

# 3. Developer Experience

-   Unified logging
-   Store plugin for preferences
-   Native notifications
-   Window state persistence
-   GitHub Actions release automation

------------------------------------------------------------------------

# 4. Long-Term Improvements

-   Independent model manager
-   System tray support
-   Diagnostic export package

------------------------------------------------------------------------

# Recommended Implementation Order

1.  Single Instance
2.  Official Updater
3.  Sidecars
4.  Capabilities & Permissions
5.  Separate User Data
6.  Unified Logging
7.  Stronghold
8.  Notifications
9.  System Tray
10. GitHub Actions Release Pipeline

------------------------------------------------------------------------

# Final Recommendation

Treat **Tauri as the desktop supervisor** responsible for:

-   Updates
-   Process management
-   Security
-   Packaging
-   Native desktop integration

Continue using **Laravel** for:

-   Business logic
-   Database operations
-   Transcript workflow
-   Existing application architecture

This separation gives the best balance between maintainability,
security, and development speed.
