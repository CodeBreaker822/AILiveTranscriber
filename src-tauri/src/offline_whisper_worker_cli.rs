mod offline_whisper;
mod offline_whisper_worker;

use serde::Serialize;
use std::fs::OpenOptions;
use std::io::Write;
use std::path::PathBuf;
use std::time::Duration;

#[derive(Serialize)]
struct ProgressLine<'a> {
    progress_id: &'a str,
    percent: i32,
}

fn value_after(arguments: &[String], flag: &str) -> Option<String> {
    arguments
        .iter()
        .position(|argument| argument == flag)
        .and_then(|index| arguments.get(index + 1))
        .cloned()
}

fn main() {
    let arguments = std::env::args().skip(1).collect::<Vec<_>>();

    if !arguments.iter().any(|argument| argument == "--offline-whisper-worker") {
        eprintln!("offline Whisper worker requires --offline-whisper-worker");
        std::process::exit(2);
    }

    let storage_path = value_after(&arguments, "--storage")
        .map(PathBuf::from)
        .unwrap_or_else(|| {
            eprintln!("offline Whisper worker requires --storage");
            std::process::exit(2);
        });
    let threads = value_after(&arguments, "--threads")
        .and_then(|value| value.parse::<usize>().ok())
        .unwrap_or(2)
        .max(1);
    let progress_path = value_after(&arguments, "--progress").map(PathBuf::from);

    if let Err(error) = offline_whisper_worker::start(&storage_path, threads, move |progress_id, percent| {
        let Some(progress_path) = progress_path.as_ref() else {
            return;
        };

        if let Some(parent) = progress_path.parent() {
            let _ = std::fs::create_dir_all(parent);
        }

        if let Ok(mut file) = OpenOptions::new().create(true).append(true).open(progress_path) {
            if let Ok(payload) = serde_json::to_string(&ProgressLine {
                progress_id: &progress_id,
                percent,
            }) {
                let _ = writeln!(file, "{payload}");
            }
        }
    }) {
        eprintln!("{error}");
        std::process::exit(1);
    }

    loop {
        std::thread::sleep(Duration::from_secs(60));
    }
}
