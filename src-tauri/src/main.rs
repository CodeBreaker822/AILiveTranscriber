#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

use serde::Serialize;
use std::fs::{File, OpenOptions};
use std::io::{Read, Write};
use std::net::TcpStream;
use std::path::PathBuf;
use std::process::{Child, Command, Stdio};
use std::sync::Mutex;
use std::thread;
use std::time::Duration;
use tauri::{Manager, State};
use tauri_plugin_dialog::DialogExt;

#[cfg(windows)]
use std::os::windows::process::CommandExt;

const LARAVEL_PORT: &str = "8010";
const LARAVEL_URL: &str = "http://127.0.0.1:8010";
const LARAVEL_HOST_PORT: &str = "127.0.0.1:8010";
const STARTUP_ATTEMPTS: usize = 80;
const STARTUP_RETRY_DELAY: Duration = Duration::from_millis(250);
#[cfg(windows)]
const CREATE_NO_WINDOW: u32 = 0x08000000;

struct LaravelServer(Mutex<Option<Child>>);

struct LaravelPaths {
    project_dir: PathBuf,
    database_path: PathBuf,
    storage_path: PathBuf,
    startup_log_path: PathBuf,
}

#[derive(Serialize)]
struct AudioFileSelection {
    path: String,
    name: String,
    size: u64,
}

fn bundled_project_dir(app: &tauri::AppHandle) -> Result<std::path::PathBuf, String> {
    if cfg!(debug_assertions) {
        let current_dir = std::env::current_dir().map_err(|error| error.to_string())?;

        if current_dir.ends_with("src-tauri") {
            return current_dir
                .parent()
                .map(std::path::Path::to_path_buf)
                .ok_or_else(|| "failed to resolve project directory".to_string());
        }

        return Ok(current_dir);
    }

    app.path()
        .resource_dir()
        .map_err(|error| format!("failed to resolve bundled resources: {error}"))
}

fn writable_paths(app: &tauri::AppHandle) -> Result<(PathBuf, PathBuf), String> {
    if cfg!(debug_assertions) {
        let project_dir = bundled_project_dir(app)?;

        return Ok((
            project_dir.join("database").join("database.sqlite"),
            project_dir.join("storage"),
        ));
    }

    let app_data_dir = app
        .path()
        .app_data_dir()
        .map_err(|error| format!("failed to resolve app data directory: {error}"))?;

    Ok((
        app_data_dir.join("database.sqlite"),
        app_data_dir.join("storage"),
    ))
}

fn laravel_paths(app: &tauri::AppHandle) -> Result<LaravelPaths, String> {
    let project_dir = bundled_project_dir(app)?;
    let (database_path, storage_path) = writable_paths(app)?;
    let startup_log_path = storage_path.join("logs").join("tauri-startup.log");

    Ok(LaravelPaths {
        project_dir,
        database_path,
        storage_path,
        startup_log_path,
    })
}

fn ensure_runtime_storage(paths: &LaravelPaths) -> Result<(), String> {
    if let Some(database_dir) = paths.database_path.parent() {
        std::fs::create_dir_all(database_dir)
            .map_err(|error| format!("failed to create database directory: {error}"))?;
    }

    if !paths.database_path.exists() {
        let bundled_database_path = paths.project_dir.join("database").join("database.sqlite");

        if bundled_database_path.is_file() {
            std::fs::copy(&bundled_database_path, &paths.database_path)
                .map_err(|error| format!("failed to copy bundled SQLite database: {error}"))?;
        } else {
            std::fs::File::create(&paths.database_path)
                .map_err(|error| format!("failed to create SQLite database: {error}"))?;
        }
    }

    for directory in [
        paths.storage_path.join("app").join("private"),
        paths.storage_path.join("app").join("public"),
        paths
            .storage_path
            .join("framework")
            .join("cache")
            .join("data"),
        paths.storage_path.join("framework").join("sessions"),
        paths.storage_path.join("framework").join("testing"),
        paths.storage_path.join("framework").join("views"),
        paths.storage_path.join("logs"),
    ] {
        std::fs::create_dir_all(&directory)
            .map_err(|error| format!("failed to create storage directory: {error}"))?;
    }

    Ok(())
}

fn reset_startup_log(paths: &LaravelPaths) -> Result<File, String> {
    if let Some(log_dir) = paths.startup_log_path.parent() {
        std::fs::create_dir_all(log_dir)
            .map_err(|error| format!("failed to create startup log directory: {error}"))?;
    }

    let mut file = File::create(&paths.startup_log_path)
        .map_err(|error| format!("failed to create startup log: {error}"))?;

    writeln!(
        file,
        "AITranscriber startup log\nProject: {}\nDatabase: {}\nStorage: {}\n",
        paths.project_dir.display(),
        paths.database_path.display(),
        paths.storage_path.display()
    )
    .map_err(|error| format!("failed to write startup log header: {error}"))?;

    Ok(file)
}

fn startup_log_handle(paths: &LaravelPaths) -> Result<File, String> {
    OpenOptions::new()
        .create(true)
        .append(true)
        .open(&paths.startup_log_path)
        .map_err(|error| format!("failed to open startup log: {error}"))
}

fn append_startup_log(paths: &LaravelPaths, message: &str) {
    if let Ok(mut file) = OpenOptions::new()
        .create(true)
        .append(true)
        .open(&paths.startup_log_path)
    {
        let _ = writeln!(file, "{message}");
    }
}

fn laravel_command(php_path: PathBuf, artisan_path: PathBuf, paths: &LaravelPaths) -> Command {
    let mut command = Command::new(php_path);
    let php_dir = paths.project_dir.join("php");
    let ca_bundle_path = php_dir.join("extras").join("ssl").join("cacert.pem");
    let php_dir_env = env_path(&php_dir);
    let ca_bundle_env = env_path(&ca_bundle_path);
    let database_env = env_path(&paths.database_path);
    let storage_env = env_path(&paths.storage_path);

    command
        .arg(artisan_path)
        .current_dir(&paths.project_dir)
        .env("PHPRC", php_dir_env)
        .env("PHP_INI_SCAN_DIR", "")
        .env("CURL_CA_BUNDLE", ca_bundle_env.clone())
        .env("SSL_CERT_FILE", ca_bundle_env)
        .env("DB_DATABASE", database_env)
        .env("APP_STORAGE_PATH", storage_env)
        .env("APP_ENV", "production")
        .env("APP_DEBUG", "false");

    command
}

fn env_path(path: &std::path::Path) -> String {
    let path = path.display().to_string();

    #[cfg(windows)]
    {
        path.strip_prefix(r"\\?\").unwrap_or(&path).to_string()
    }

    #[cfg(not(windows))]
    {
        path
    }
}

fn run_migrations(
    paths: &LaravelPaths,
    php_path: PathBuf,
    artisan_path: PathBuf,
) -> Result<(), String> {
    run_artisan(
        paths,
        php_path,
        artisan_path,
        &["migrate", "--force", "--no-interaction"],
        "database migrations",
    )
}

fn sync_bundled_default_settings(
    paths: &LaravelPaths,
    php_path: PathBuf,
    artisan_path: PathBuf,
) -> Result<(), String> {
    run_artisan(
        paths,
        php_path,
        artisan_path,
        &["app:sync-bundled-default-settings"],
        "bundled default settings sync",
    )
}

fn run_artisan(
    paths: &LaravelPaths,
    php_path: PathBuf,
    artisan_path: PathBuf,
    args: &[&str],
    label: &str,
) -> Result<(), String> {
    let mut command = laravel_command(php_path, artisan_path, paths);

    command
        .args(args)
        .stdout(Stdio::piped())
        .stderr(Stdio::piped());

    #[cfg(windows)]
    command.creation_flags(CREATE_NO_WINDOW);

    let output = command
        .output()
        .map_err(|error| format!("failed to run {label}: {error}"))?;

    if !output.status.success() {
        let stderr = String::from_utf8_lossy(&output.stderr);
        let stdout = String::from_utf8_lossy(&output.stdout);
        let details = if stderr.trim().is_empty() {
            stdout.trim()
        } else {
            stderr.trim()
        };

        return Err(format!("{label} failed: {details}"));
    }

    Ok(())
}

#[cfg(windows)]
fn listening_processes_on_laravel_port() -> Result<Vec<u32>, String> {
    let mut command = Command::new("netstat");
    command.args(["-ano", "-p", "tcp"]);
    command.creation_flags(CREATE_NO_WINDOW);

    let output = command
        .output()
        .map_err(|error| format!("failed to inspect port {LARAVEL_PORT}: {error}"))?;

    if !output.status.success() {
        return Err(format!(
            "netstat failed while checking port {LARAVEL_PORT}: {}",
            String::from_utf8_lossy(&output.stderr).trim()
        ));
    }

    let stdout = String::from_utf8_lossy(&output.stdout);
    let mut process_ids = Vec::new();

    for line in stdout.lines() {
        if !line.contains("LISTENING") || !line.contains(&format!(":{LARAVEL_PORT}")) {
            continue;
        }

        let Some(pid_text) = line.split_whitespace().last() else {
            continue;
        };

        let Ok(process_id) = pid_text.parse::<u32>() else {
            continue;
        };

        if process_id != 0 && !process_ids.contains(&process_id) {
            process_ids.push(process_id);
        }
    }

    Ok(process_ids)
}

#[cfg(windows)]
fn kill_process_tree(process_id: u32) -> Result<(), String> {
    let mut command = Command::new("taskkill");
    command.args(["/PID", &process_id.to_string(), "/F", "/T"]);
    command.creation_flags(CREATE_NO_WINDOW);

    let output = command
        .output()
        .map_err(|error| format!("failed to stop process {process_id}: {error}"))?;

    if output.status.success() {
        return Ok(());
    }

    let stderr = String::from_utf8_lossy(&output.stderr);
    let stdout = String::from_utf8_lossy(&output.stdout);
    let details = if stderr.trim().is_empty() {
        stdout.trim()
    } else {
        stderr.trim()
    };

    Err(format!("failed to stop process {process_id}: {details}"))
}

#[cfg(windows)]
fn force_clear_laravel_port(paths: &LaravelPaths) -> Result<(), String> {
    for attempt in 1..=5 {
        let process_ids = listening_processes_on_laravel_port()?;

        if process_ids.is_empty() {
            append_startup_log(paths, &format!("Port {LARAVEL_PORT} is free."));
            return Ok(());
        }

        append_startup_log(
            paths,
            &format!(
                "Port {LARAVEL_PORT} is occupied by PID(s): {}. Stopping them before launching bundled PHP.",
                process_ids
                    .iter()
                    .map(u32::to_string)
                    .collect::<Vec<String>>()
                    .join(", ")
            ),
        );

        for process_id in process_ids {
            kill_process_tree(process_id)?;
            append_startup_log(paths, &format!("Stopped process tree {process_id}."));
        }

        thread::sleep(Duration::from_millis(350));

        if attempt == 5 {
            break;
        }
    }

    if listening_processes_on_laravel_port()?.is_empty() {
        Ok(())
    } else {
        Err(format!(
            "port {LARAVEL_PORT} is still in use after stopping existing PHP servers."
        ))
    }
}

#[cfg(not(windows))]
fn force_clear_laravel_port(_paths: &LaravelPaths) -> Result<(), String> {
    Ok(())
}

fn start_laravel(app: &tauri::AppHandle) -> Result<(), String> {
    let paths = laravel_paths(app)?;
    ensure_runtime_storage(&paths)?;
    let startup_log = startup_log_handle(&paths)?;

    let php_path = paths.project_dir.join("php").join("php.exe");
    let artisan_path = paths.project_dir.join("artisan");

    if !php_path.is_file() {
        return Err(format!("missing PHP runtime: {}", php_path.display()));
    }

    if !artisan_path.is_file() {
        return Err(format!(
            "missing Laravel artisan file: {}",
            artisan_path.display()
        ));
    }

    run_migrations(&paths, php_path.clone(), artisan_path.clone())?;
    append_startup_log(&paths, "Database migrations completed.");
    sync_bundled_default_settings(&paths, php_path.clone(), artisan_path.clone())?;
    append_startup_log(&paths, "Bundled default settings sync completed.");

    let mut command = laravel_command(php_path, artisan_path, &paths);
    let stderr_log = startup_log
        .try_clone()
        .map_err(|error| format!("failed to clone startup log handle: {error}"))?;

    command
        .arg("serve")
        .arg("--host=127.0.0.1")
        .arg(format!("--port={LARAVEL_PORT}"))
        .stdout(Stdio::from(startup_log))
        .stderr(Stdio::from(stderr_log));

    #[cfg(windows)]
    command.creation_flags(CREATE_NO_WINDOW);

    let child = command
        .spawn()
        .map_err(|error| format!("failed to start Laravel server: {error}"))?;

    let state: State<LaravelServer> = app.state();
    *state.0.lock().map_err(|error| error.to_string())? = Some(child);

    Ok(())
}

fn laravel_http_response() -> Result<(u16, String), String> {
    let mut stream = TcpStream::connect(LARAVEL_HOST_PORT)
        .map_err(|error| format!("Laravel port is not ready: {error}"))?;

    let timeout = Some(Duration::from_secs(3));
    stream
        .set_read_timeout(timeout)
        .map_err(|error| format!("failed to set Laravel read timeout: {error}"))?;
    stream
        .set_write_timeout(timeout)
        .map_err(|error| format!("failed to set Laravel write timeout: {error}"))?;

    stream
        .write_all(
            format!(
                "GET / HTTP/1.1\r\nHost: 127.0.0.1:{LARAVEL_PORT}\r\nConnection: close\r\n\r\n"
            )
            .as_bytes(),
        )
        .map_err(|error| format!("failed to send Laravel readiness request: {error}"))?;

    let mut response = String::new();
    stream
        .read_to_string(&mut response)
        .map_err(|error| format!("failed to read Laravel readiness response: {error}"))?;

    let status = response
        .lines()
        .next()
        .and_then(|line| line.split_whitespace().nth(1))
        .and_then(|status| status.parse::<u16>().ok())
        .ok_or_else(|| "Laravel returned an invalid HTTP response.".to_string())?;

    Ok((status, response))
}

fn wait_for_laravel(paths: &LaravelPaths) -> Result<(), String> {
    let mut last_error = "Laravel server did not respond yet.".to_string();

    for _ in 0..STARTUP_ATTEMPTS {
        match laravel_http_response() {
            Ok((status, _)) if (200..400).contains(&status) => return Ok(()),
            Ok((status, response)) => {
                last_error = format!(
                    "Laravel returned HTTP {status} during startup.{}",
                    recent_startup_log(paths),
                );

                if status >= 500 && response.trim().is_empty() {
                    last_error.push_str("\nThe server response was empty.");
                }
            }
            Err(error) => {
                last_error = error;
            }
        }

        thread::sleep(STARTUP_RETRY_DELAY);
    }

    Err(last_error)
}

fn stop_laravel(app: &tauri::AppHandle) {
    let state: State<LaravelServer> = app.state();

    let child_process = {
        let Ok(mut guard) = state.0.lock() else {
            return;
        };

        guard.take()
    };

    if let Some(mut child) = child_process {
        let _ = child.kill();
    }
}

fn recent_startup_log(paths: &LaravelPaths) -> String {
    let Ok(contents) = std::fs::read_to_string(&paths.startup_log_path) else {
        return format!(
            "\nNo Tauri startup log was found at {}.",
            paths.startup_log_path.display()
        );
    };

    let lines: Vec<&str> = contents.lines().rev().take(24).collect();

    if lines.is_empty() {
        return format!(
            "\nTauri startup log is empty at {}.",
            paths.startup_log_path.display()
        );
    }

    let recent_lines = lines.into_iter().rev().collect::<Vec<&str>>().join("\n");

    format!(
        "\nRecent PHP startup output from {}:\n{}",
        paths.startup_log_path.display(),
        recent_lines
    )
}

fn escape_html(value: &str) -> String {
    value
        .replace('&', "&amp;")
        .replace('<', "&lt;")
        .replace('>', "&gt;")
        .replace('"', "&quot;")
        .replace('\'', "&#39;")
}

fn show_startup_error(app: &tauri::AppHandle, message: &str) {
    if let Some(window) = app.get_webview_window("main") {
        let escaped = escape_html(message);
        let html = format!(
            "<main style=\"font-family:Segoe UI,sans-serif;padding:24px;color:#e2e8f0;background:#071018;min-height:100vh\"><h1 style=\"font-size:22px;margin:0 0 12px\">AITranscriber could not start</h1><p style=\"line-height:1.6;margin:0 0 16px;color:#94a3b8\">The desktop app waits for its own PHP server to return a successful page before opening the workspace.</p><pre style=\"white-space:pre-wrap;line-height:1.5;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.04);border-radius:8px;padding:14px;color:#e2e8f0\">{}</pre></main>",
            escaped
        );
        let script = format!(
            "document.body.innerHTML = {};",
            serde_json::to_string(&html).unwrap_or_else(|_| "\"Startup error\"".to_string())
        );

        let _ = window.eval(&script);
        let _ = window.show();
    }
}

fn show_laravel_window(app: &tauri::AppHandle) {
    if let Some(window) = app.get_webview_window("main") {
        let _ = window.eval(&format!("window.location.replace('{LARAVEL_URL}')"));
        let _ = window.show();
        let _ = window.set_focus();
    }
}

fn bootstrap_laravel(app: &tauri::AppHandle) -> Result<(), String> {
    let paths = laravel_paths(app)?;
    ensure_runtime_storage(&paths)?;
    reset_startup_log(&paths)?;

    if cfg!(debug_assertions) && TcpStream::connect(LARAVEL_HOST_PORT).is_ok() {
        append_startup_log(
            &paths,
            &format!("Debug mode detected an existing dev server on port {LARAVEL_PORT}."),
        );
        return wait_for_laravel(&paths);
    }

    force_clear_laravel_port(&paths)?;
    start_laravel(app)?;
    wait_for_laravel(&paths)
}

#[tauri::command]
fn open_external_url(url: String) -> Result<(), String> {
    let url = url.trim().to_string();

    if !(url.starts_with("https://") || url.starts_with("http://")) {
        return Err("Only http and https links can be opened externally.".to_string());
    }

    if url.chars().any(char::is_control) {
        return Err("The external link is not valid.".to_string());
    }

    #[cfg(windows)]
    {
        let mut command = Command::new("rundll32");
        command.args(["url.dll,FileProtocolHandler", &url]);
        command.creation_flags(CREATE_NO_WINDOW);
        command
            .spawn()
            .map_err(|error| format!("failed to open external link: {error}"))?;
    }

    #[cfg(target_os = "macos")]
    {
        Command::new("open")
            .arg(&url)
            .spawn()
            .map_err(|error| format!("failed to open external link: {error}"))?;
    }

    #[cfg(all(unix, not(target_os = "macos")))]
    {
        Command::new("xdg-open")
            .arg(&url)
            .spawn()
            .map_err(|error| format!("failed to open external link: {error}"))?;
    }

    Ok(())
}

#[tauri::command]
fn save_text_export(
    app: tauri::AppHandle,
    _filename: Option<String>,
    content: String,
) -> Result<Option<String>, String> {
    save_text_export_content(&app, content)
}

#[tauri::command]
fn save_text_export_with_dialog(
    app: tauri::AppHandle,
    content: String,
) -> Result<Option<String>, String> {
    save_text_export_content(&app, content)
}

fn save_text_export_content(
    app: &tauri::AppHandle,
    content: String,
) -> Result<Option<String>, String> {
    let Some(export_path) = choose_text_export_path(app)? else {
        return Ok(None);
    };

    std::fs::write(&export_path, content.as_bytes())
        .map_err(|error| format!("failed to save transcript export: {error}"))?;

    Ok(Some(export_path.display().to_string()))
}

fn choose_text_export_path(app: &tauri::AppHandle) -> Result<Option<PathBuf>, String> {
    let Some(file_path) = app
        .dialog()
        .file()
        .set_title("Save transcript export")
        .add_filter("Text files", &["txt"])
        .blocking_save_file()
    else {
        return Ok(None);
    };

    let mut path = file_path
        .into_path()
        .map_err(|error| format!("failed to read selected export path: {error}"))?;

    if path.extension().is_none() {
        path.set_extension("txt");
    }

    Ok(Some(path))
}

#[tauri::command]
fn choose_audio_file(app: tauri::AppHandle) -> Result<Option<AudioFileSelection>, String> {
    let Some(file_path) = app
        .dialog()
        .file()
        .set_title("Choose audio file")
        .add_filter(
            "Audio files",
            &[
                "wav", "mp3", "m4a", "aac", "ogg", "oga", "flac", "webm", "wma", "mp4",
            ],
        )
        .blocking_pick_file()
    else {
        return Ok(None);
    };

    let path = file_path
        .into_path()
        .map_err(|error| format!("failed to read selected audio path: {error}"))?;
    let metadata = std::fs::metadata(&path)
        .map_err(|error| format!("failed to inspect selected audio file: {error}"))?;

    if !metadata.is_file() {
        return Err("Choose a valid audio file.".to_string());
    }

    let name = path
        .file_name()
        .and_then(|value| value.to_str())
        .unwrap_or("audio")
        .to_string();

    Ok(Some(AudioFileSelection {
        path: env_path(&path),
        name,
        size: metadata.len(),
    }))
}

fn main() {
    tauri::Builder::default()
        .plugin(tauri_plugin_dialog::init())
        .manage(LaravelServer(Mutex::new(None)))
        .invoke_handler(tauri::generate_handler![
            open_external_url,
            save_text_export,
            save_text_export_with_dialog,
            choose_audio_file
        ])
        .setup(|app| {
            let handle = app.handle().clone();

            if let Err(error) = bootstrap_laravel(&handle) {
                show_startup_error(&handle, &error);
            } else {
                show_laravel_window(&handle);
            }

            Ok(())
        })
        .on_window_event(|window, event| {
            if let tauri::WindowEvent::CloseRequested { .. } = event {
                stop_laravel(&window.app_handle());
            }
        })
        .run(tauri::generate_context!())
        .expect("error while running tauri application");
}
