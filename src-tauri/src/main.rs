#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

use std::path::PathBuf;
use std::process::{Child, Command, Stdio};
use std::sync::Mutex;
use std::thread;
use std::time::Duration;
use tauri::{Manager, State};

#[cfg(windows)]
use std::os::windows::process::CommandExt;

const LARAVEL_URL: &str = "http://127.0.0.1:8000";
const LARAVEL_HOST_PORT: &str = "127.0.0.1:8000";
#[cfg(windows)]
const CREATE_NO_WINDOW: u32 = 0x08000000;

struct LaravelServer(Mutex<Option<Child>>);

struct LaravelPaths {
    project_dir: PathBuf,
    database_path: PathBuf,
    storage_path: PathBuf,
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

    Ok(LaravelPaths {
        project_dir,
        database_path,
        storage_path,
    })
}

fn ensure_runtime_storage(paths: &LaravelPaths) -> Result<(), String> {
    if let Some(database_dir) = paths.database_path.parent() {
        std::fs::create_dir_all(database_dir)
            .map_err(|error| format!("failed to create database directory: {error}"))?;
    }

    if !paths.database_path.exists() {
        std::fs::File::create(&paths.database_path)
            .map_err(|error| format!("failed to create SQLite database: {error}"))?;
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

fn laravel_command(php_path: PathBuf, artisan_path: PathBuf, paths: &LaravelPaths) -> Command {
    let mut command = Command::new(php_path);

    command
        .arg(artisan_path)
        .current_dir(&paths.project_dir)
        .env("DB_DATABASE", &paths.database_path)
        .env("APP_STORAGE_PATH", &paths.storage_path)
        .env("APP_ENV", "production")
        .env("APP_DEBUG", "false");

    command
}

fn run_migrations(
    paths: &LaravelPaths,
    php_path: PathBuf,
    artisan_path: PathBuf,
) -> Result<(), String> {
    let mut command = laravel_command(php_path, artisan_path, paths);

    command
        .arg("migrate")
        .arg("--force")
        .arg("--no-interaction")
        .stdout(Stdio::piped())
        .stderr(Stdio::piped());

    #[cfg(windows)]
    command.creation_flags(CREATE_NO_WINDOW);

    let output = command
        .output()
        .map_err(|error| format!("failed to run database migrations: {error}"))?;

    if !output.status.success() {
        let stderr = String::from_utf8_lossy(&output.stderr);
        let stdout = String::from_utf8_lossy(&output.stdout);
        let details = if stderr.trim().is_empty() {
            stdout.trim()
        } else {
            stderr.trim()
        };

        return Err(format!("database migrations failed: {details}"));
    }

    Ok(())
}

fn start_laravel(app: &tauri::AppHandle) -> Result<(), String> {
    let paths = laravel_paths(app)?;
    ensure_runtime_storage(&paths)?;

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

    let mut command = laravel_command(php_path, artisan_path, &paths);
    command
        .arg("serve")
        .arg("--host=127.0.0.1")
        .arg("--port=8000")
        .stdout(Stdio::null())
        .stderr(Stdio::null());

    #[cfg(windows)]
    command.creation_flags(CREATE_NO_WINDOW);

    let child = command
        .spawn()
        .map_err(|error| format!("failed to start Laravel server: {error}"))?;

    let state: State<LaravelServer> = app.state();
    *state.0.lock().map_err(|error| error.to_string())? = Some(child);

    Ok(())
}

fn wait_for_laravel() -> bool {
    for _ in 0..80 {
        if std::net::TcpStream::connect(LARAVEL_HOST_PORT).is_ok() {
            return true;
        }

        thread::sleep(Duration::from_millis(250));
    }

    false
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

fn show_startup_error(app: &tauri::AppHandle, message: &str) {
    if let Some(window) = app.get_webview_window("main") {
        let escaped = message.replace('\\', "\\\\").replace('\'', "\\'");
        let html = format!(
            "document.body.innerHTML = '<main style=\"font-family:Segoe UI,sans-serif;padding:24px;color:#e2e8f0;background:#071018;min-height:100vh\"><h1 style=\"font-size:22px\">AITranscriber could not start</h1><p style=\"line-height:1.6\">{}</p></main>';",
            escaped
        );

        let _ = window.eval(&html);
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
    if std::net::TcpStream::connect(LARAVEL_HOST_PORT).is_err() {
        start_laravel(app)?;
    }

    if !wait_for_laravel() {
        return Err("Laravel server did not start in time.".to_string());
    }

    Ok(())
}

fn main() {
    tauri::Builder::default()
        .manage(LaravelServer(Mutex::new(None)))
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
