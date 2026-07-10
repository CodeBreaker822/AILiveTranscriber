use crate::speaker_diarization::{DiarizationResult, SpeakerDiarizationEngine};
use serde::{Deserialize, Serialize};
use std::io::{BufRead, BufReader, ErrorKind, Write};
use std::net::{TcpListener, TcpStream};
use std::path::{Path, PathBuf};
use std::thread;
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

const IDLE_MODEL_TIMEOUT: Duration = Duration::from_secs(120);
const IDLE_SESSION_TIMEOUT: Duration = Duration::from_secs(30 * 60);

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
    segmentation_model_path: Option<String>,
    embedding_model_path: Option<String>,
    audio_path: Option<String>,
    threads: Option<usize>,
    threshold: Option<f32>,
    match_threshold: Option<f32>,
    max_speakers: Option<usize>,
    speaker_session_id: Option<String>,
    release: Option<bool>,
}

pub fn start(storage_path: &Path, default_threads: usize) -> Result<(), String> {
    let listener = TcpListener::bind("127.0.0.1:0")
        .map_err(|error| format!("failed to bind speaker diarization worker: {error}"))?;
    listener
        .set_nonblocking(true)
        .map_err(|error| format!("failed to configure speaker diarization worker: {error}"))?;
    let address = listener
        .local_addr()
        .map_err(|error| format!("failed to resolve speaker diarization worker address: {error}"))?
        .to_string();
    let token = format!(
        "{}-{}",
        std::process::id(),
        SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .unwrap_or_default()
            .as_nanos()
    );
    let endpoint_path = storage_path
        .join("app")
        .join("private")
        .join("speaker-diarization-worker.json");

    if let Some(parent) = endpoint_path.parent() {
        std::fs::create_dir_all(parent)
            .map_err(|error| format!("failed to prepare diarization worker directory: {error}"))?;
    }

    let temporary_path = endpoint_path.with_extension("json.tmp");
    let endpoint = WorkerEndpoint {
        address,
        token: &token,
        process_id: std::process::id(),
    };
    std::fs::write(
        &temporary_path,
        serde_json::to_vec(&endpoint)
            .map_err(|error| format!("failed to encode diarization worker endpoint: {error}"))?,
    )
    .map_err(|error| format!("failed to write diarization worker endpoint: {error}"))?;
    let _ = std::fs::remove_file(&endpoint_path);
    std::fs::rename(&temporary_path, &endpoint_path)
        .map_err(|error| format!("failed to publish diarization worker endpoint: {error}"))?;

    thread::Builder::new()
        .name("speaker-diarization-worker".to_string())
        .spawn(move || run(listener, token, default_threads))
        .map_err(|error| format!("failed to start speaker diarization worker: {error}"))?;

    Ok(())
}

fn run(listener: TcpListener, token: String, default_threads: usize) {
    let mut engine: Option<SpeakerDiarizationEngine> = None;
    let mut last_used = Instant::now();

    loop {
        match listener.accept() {
            Ok((stream, _)) => {
                handle(stream, &token, default_threads, &mut engine);
                last_used = Instant::now();
            }
            Err(error) if error.kind() == ErrorKind::WouldBlock => {
                if let Some(loaded) = engine.as_mut() {
                    loaded.purge_expired_sessions(IDLE_SESSION_TIMEOUT);
                    if !loaded.has_active_sessions() && last_used.elapsed() >= IDLE_MODEL_TIMEOUT {
                        engine = None;
                    }
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
    engine: &mut Option<SpeakerDiarizationEngine>,
) {
    let _ = stream.set_read_timeout(Some(Duration::from_secs(10)));
    let mut request_line = String::new();
    let request = BufReader::new(&stream)
        .read_line(&mut request_line)
        .map_err(|error| format!("failed to read diarization worker request: {error}"))
        .and_then(|_| {
            serde_json::from_str::<WorkerRequest>(&request_line)
                .map_err(|error| format!("invalid diarization worker request: {error}"))
        });

    let response = match request {
        Ok(request) if request.token != token => {
            serde_json::json!({ "error": "Speaker diarization worker authentication failed." })
        }
        Ok(request) if request.action.as_deref() == Some("release") => {
            *engine = None;
            serde_json::json!({ "released": true })
        }
        Ok(request) if request.action.as_deref() == Some("release_session") => {
            let session_id = request.speaker_session_id.as_deref().unwrap_or("").trim();
            let released = !session_id.is_empty()
                && engine
                    .as_mut()
                    .map(|loaded| loaded.release_session(session_id))
                    .unwrap_or(false);
            serde_json::json!({ "released": released, "speaker_session_id": session_id })
        }
        Ok(request) => match diarize(request, default_threads, engine) {
            Ok(value) => serde_json::to_value(value)
                .unwrap_or_else(|_| serde_json::json!({ "error": "failed to encode diarization" })),
            Err(error) => serde_json::json!({ "error": error }),
        },
        Err(error) => serde_json::json!({ "error": error }),
    };

    let _ = stream.set_write_timeout(Some(Duration::from_secs(10)));
    if let Ok(mut payload) = serde_json::to_vec(&response) {
        payload.push(b'\n');
        let _ = stream.write_all(&payload);
        let _ = stream.flush();
    }
}

fn diarize(
    request: WorkerRequest,
    default_threads: usize,
    engine: &mut Option<SpeakerDiarizationEngine>,
) -> Result<DiarizationResult, String> {
    let segmentation_model = PathBuf::from(
        request
            .segmentation_model_path
            .as_deref()
            .ok_or_else(|| "diarization worker requires a segmentation model".to_string())?,
    );
    let embedding_model = PathBuf::from(
        request
            .embedding_model_path
            .as_deref()
            .ok_or_else(|| "diarization worker requires an embedding model".to_string())?,
    );
    let audio_path = PathBuf::from(
        request
            .audio_path
            .as_deref()
            .ok_or_else(|| "diarization worker requires an audio path".to_string())?,
    );
    let threads = request.threads.unwrap_or(default_threads).max(1);
    let threshold = request.threshold.unwrap_or(0.9);
    let match_threshold = request.match_threshold.unwrap_or(0.6);
    let max_speakers = request.max_speakers.unwrap_or(16);
    let speaker_session_id = request
        .speaker_session_id
        .as_deref()
        .map(str::trim)
        .filter(|value| !value.is_empty())
        .map(str::to_string);
    let release_session = request.release.unwrap_or(false);

    if engine
        .as_ref()
        .map(|loaded| {
            !loaded.uses_models(&segmentation_model, &embedding_model, threads, threshold)
        })
        .unwrap_or(true)
    {
        *engine = Some(SpeakerDiarizationEngine::load(
            &segmentation_model,
            &embedding_model,
            threads,
            threshold,
        )?);
    }

    let result = engine
        .as_mut()
        .ok_or_else(|| "speaker diarization model is not loaded".to_string())?
        .diarize(
            &audio_path,
            speaker_session_id.as_deref(),
            match_threshold,
            max_speakers,
        );

    if release_session {
        if let (Some(loaded), Some(session_id)) = (engine.as_mut(), speaker_session_id.as_deref()) {
            loaded.release_session(session_id);
        }
    }

    result
}
