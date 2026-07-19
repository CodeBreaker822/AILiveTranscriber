use crate::offline_whisper::OfflineWhisperEngine;
use serde::{Deserialize, Serialize};
use std::collections::HashMap;
use std::io::{BufRead, BufReader, ErrorKind, Write};
use std::net::{TcpListener, TcpStream};
use std::panic::{catch_unwind, AssertUnwindSafe};
use std::path::{Path, PathBuf};
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, Mutex, OnceLock};
use std::thread;
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

const IDLE_MODEL_TIMEOUT: Duration = Duration::from_secs(120);

#[derive(Serialize)]
struct WorkerEndpoint<'a> {
    address: String,
    token: &'a str,
    process_id: u32,
}

#[derive(Deserialize)]
struct WorkerRequest {
    token: String,
    action: Option<String>,
    model_path: Option<String>,
    audio_path: Option<String>,
    language: Option<String>,
    threads: Option<usize>,
    use_gpu: Option<bool>,
    gpu_vram_budget_mb: Option<u64>,
    progress_id: Option<String>,
    release: Option<bool>,
}

type ProgressEmitter = Arc<dyn Fn(String, i32) + Send + Sync>;
type CancellationFlag = Arc<AtomicBool>;

fn cancellation_flags() -> &'static Mutex<HashMap<String, CancellationFlag>> {
    static FLAGS: OnceLock<Mutex<HashMap<String, CancellationFlag>>> = OnceLock::new();

    FLAGS.get_or_init(|| Mutex::new(HashMap::new()))
}

pub fn cancel(progress_id: &str) -> bool {
    let progress_id = progress_id.trim();

    if progress_id.is_empty() {
        return false;
    }

    let mut flags = cancellation_flags()
        .lock()
        .unwrap_or_else(|poisoned| poisoned.into_inner());
    let flag = flags
        .entry(progress_id.to_string())
        .or_insert_with(|| Arc::new(AtomicBool::new(false)));
    flag.store(true, Ordering::Release);

    true
}

fn cancellation_flag(progress_id: &str) -> CancellationFlag {
    let mut flags = cancellation_flags()
        .lock()
        .unwrap_or_else(|poisoned| poisoned.into_inner());

    Arc::clone(
        flags
            .entry(progress_id.to_string())
            .or_insert_with(|| Arc::new(AtomicBool::new(false))),
    )
}

fn clear_cancellation(progress_id: &str) {
    cancellation_flags()
        .lock()
        .unwrap_or_else(|poisoned| poisoned.into_inner())
        .remove(progress_id);
}

pub fn start<F>(storage_path: &Path, default_threads: usize, progress: F) -> Result<(), String>
where
    F: Fn(String, i32) + Send + Sync + 'static,
{
    let listener = TcpListener::bind("127.0.0.1:0")
        .map_err(|error| format!("failed to bind offline Whisper worker: {error}"))?;
    listener
        .set_nonblocking(true)
        .map_err(|error| format!("failed to configure offline Whisper worker: {error}"))?;
    let address = listener
        .local_addr()
        .map_err(|error| format!("failed to resolve offline Whisper worker address: {error}"))?
        .to_string();
    let token = format!(
        "{}-{}",
        std::process::id(),
        SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .unwrap_or_default()
            .as_nanos()
    );
    let endpoint_path = endpoint_path(storage_path);

    if let Some(parent) = endpoint_path.parent() {
        std::fs::create_dir_all(parent).map_err(|error| {
            format!("failed to prepare offline Whisper worker directory: {error}")
        })?;
    }

    let temporary_path = endpoint_path.with_extension("json.tmp");
    let endpoint = WorkerEndpoint {
        address,
        token: &token,
        process_id: std::process::id(),
    };
    std::fs::write(
        &temporary_path,
        serde_json::to_vec(&endpoint).map_err(|error| {
            format!("failed to encode offline Whisper worker endpoint: {error}")
        })?,
    )
    .map_err(|error| format!("failed to write offline Whisper worker endpoint: {error}"))?;
    let _ = std::fs::remove_file(&endpoint_path);
    std::fs::rename(&temporary_path, &endpoint_path)
        .map_err(|error| format!("failed to publish offline Whisper worker endpoint: {error}"))?;

    let progress: ProgressEmitter = Arc::new(progress);
    thread::Builder::new()
        .name("offline-whisper-worker".to_string())
        .spawn(move || run(listener, token, default_threads, progress))
        .map_err(|error| format!("failed to start offline Whisper worker: {error}"))?;

    Ok(())
}

fn endpoint_path(storage_path: &Path) -> PathBuf {
    storage_path
        .join("app")
        .join("private")
        .join("offline-whisper-worker.json")
}

fn run(listener: TcpListener, token: String, default_threads: usize, progress: ProgressEmitter) {
    let mut engine: Option<OfflineWhisperEngine> = None;
    let mut last_used = Instant::now();

    loop {
        match listener.accept() {
            Ok((stream, _)) => {
                handle(stream, &token, default_threads, &mut engine, &progress);
                last_used = Instant::now();
            }
            Err(error) if error.kind() == ErrorKind::WouldBlock => {
                if engine.is_some() && last_used.elapsed() >= IDLE_MODEL_TIMEOUT {
                    engine = None;
                }
                thread::sleep(Duration::from_millis(40));
            }
            Err(_) => thread::sleep(Duration::from_millis(100)),
        }
    }
}

fn handle(
    mut stream: TcpStream,
    token: &str,
    default_threads: usize,
    engine: &mut Option<OfflineWhisperEngine>,
    progress: &ProgressEmitter,
) {
    let _ = stream.set_read_timeout(Some(Duration::from_secs(10)));
    let mut request_line = String::new();
    let request = BufReader::new(&stream)
        .read_line(&mut request_line)
        .map_err(|error| format!("failed to read worker request: {error}"))
        .and_then(|_| {
            serde_json::from_str::<WorkerRequest>(&request_line)
                .map_err(|error| format!("invalid worker request: {error}"))
        });

    let response = match request {
        Ok(request) if request.token != token => {
            serde_json::json!({ "error": "Offline Whisper worker authentication failed." })
        }
        Ok(request) if request.action.as_deref() == Some("release") => {
            *engine = None;
            serde_json::json!({ "released": true })
        }
        Ok(request) if request.action.as_deref() == Some("cancel") => {
            let cancelled = request
                .progress_id
                .as_deref()
                .map(cancel)
                .unwrap_or(false);
            serde_json::json!({ "cancelled": cancelled })
        }
        Ok(request) => {
            let result = catch_unwind(AssertUnwindSafe(|| {
                transcribe(request, default_threads, engine, progress)
            }));

            match result {
                Ok(Ok(value)) => serde_json::to_value(value).unwrap_or_else(
                    |_| serde_json::json!({ "error": "failed to encode transcription" }),
                ),
                Ok(Err(error)) => serde_json::json!({ "error": error }),
                Err(panic) => {
                    *engine = None;
                    serde_json::json!({
                        "error": format!(
                            "Offline Whisper worker recovered from an internal failure: {}",
                            panic_message(panic)
                        ),
                        "retryable": true
                    })
                }
            }
        }
        Err(error) => serde_json::json!({ "error": error }),
    };

    let _ = stream.set_write_timeout(Some(Duration::from_secs(10)));
    if let Ok(mut payload) = serde_json::to_vec(&response) {
        payload.push(b'\n');
        let _ = stream.write_all(&payload);
        let _ = stream.flush();
    }
}

fn panic_message(panic: Box<dyn std::any::Any + Send>) -> String {
    if let Some(message) = panic.downcast_ref::<String>() {
        return message.clone();
    }

    if let Some(message) = panic.downcast_ref::<&str>() {
        return (*message).to_string();
    }

    "unknown native worker error".to_string()
}

fn transcribe(
    request: WorkerRequest,
    default_threads: usize,
    engine: &mut Option<OfflineWhisperEngine>,
    progress: &ProgressEmitter,
) -> Result<crate::offline_whisper::OfflineTranscription, String> {
    let model_path = PathBuf::from(
        request
            .model_path
            .as_deref()
            .ok_or_else(|| "offline worker requires a model path".to_string())?,
    );
    let audio_path = PathBuf::from(
        request
            .audio_path
            .as_deref()
            .ok_or_else(|| "offline worker requires an audio path".to_string())?,
    );
    let use_gpu = request.use_gpu.unwrap_or(false) && request.gpu_vram_budget_mb.unwrap_or(0) > 0;

    if engine
        .as_ref()
        .map(|loaded| !loaded.uses_configuration(&model_path, use_gpu))
        .unwrap_or(true)
    {
        *engine = Some(OfflineWhisperEngine::load(&model_path, use_gpu)?);
    }

    let progress_id = request
        .progress_id
        .as_deref()
        .map(str::trim)
        .filter(|value| !value.is_empty())
        .map(str::to_string);
    let cancellation = progress_id.as_deref().map(cancellation_flag);
    let mut result = run_transcription(
        engine
            .as_ref()
            .ok_or_else(|| "offline Whisper model is not loaded".to_string())?,
        &audio_path,
        request.language.as_deref(),
        request.threads.unwrap_or(default_threads),
        progress_id.as_deref(),
        cancellation.as_ref(),
        progress,
    );
    let gpu_failed = result.is_err()
        && engine
            .as_ref()
            .map(OfflineWhisperEngine::gpu_enabled)
            .unwrap_or(false)
        && !cancellation
            .as_ref()
            .map(|flag| flag.load(Ordering::Acquire))
            .unwrap_or(false);

    if gpu_failed {
        *engine = Some(OfflineWhisperEngine::load_cpu_fallback(&model_path)?);
        result = run_transcription(
            engine
                .as_ref()
                .ok_or_else(|| "offline Whisper CPU fallback is not loaded".to_string())?,
            &audio_path,
            request.language.as_deref(),
            request.threads.unwrap_or(default_threads),
            progress_id.as_deref(),
            cancellation.as_ref(),
            progress,
        );
    }
    let cancelled = cancellation
        .as_ref()
        .map(|flag| flag.load(Ordering::Acquire))
        .unwrap_or(false);

    if let Some(progress_id) = progress_id.as_deref() {
        clear_cancellation(progress_id);
    }

    if cancelled || (request.release.unwrap_or(false) && result.is_ok()) {
        *engine = None;
    }

    if cancelled {
        Err("Offline Whisper transcription was cancelled.".to_string())
    } else {
        result
    }
}

fn run_transcription(
    engine: &OfflineWhisperEngine,
    audio_path: &Path,
    language: Option<&str>,
    threads: usize,
    progress_id: Option<&str>,
    cancellation: Option<&CancellationFlag>,
    progress: &ProgressEmitter,
) -> Result<crate::offline_whisper::OfflineTranscription, String> {
    let progress_callback = progress_id.map(|progress_id| {
        let progress_id = progress_id.to_string();
        let progress = Arc::clone(progress);

        Box::new(move |percent: i32| {
            progress(progress_id.clone(), percent.clamp(0, 100));
        }) as Box<dyn FnMut(i32)>
    });
    let abort_callback = cancellation.map(|flag| {
        let flag = Arc::clone(flag);

        Box::new(move || flag.load(Ordering::Acquire)) as Box<dyn FnMut() -> bool>
    });

    engine.transcribe(
        audio_path,
        language,
        threads,
        progress_callback,
        abort_callback,
    )
}
