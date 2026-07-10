use serde::Serialize;
use std::path::{Path, PathBuf};
use whisper_rs::{FullParams, SamplingStrategy, WhisperContext, WhisperContextParameters};

#[derive(Serialize)]
pub struct OfflineTimestamp {
    pub text: String,
    pub start: f64,
    pub end: f64,
}

#[derive(Serialize)]
pub struct OfflineTranscription {
    pub text: String,
    pub timestamps: Vec<OfflineTimestamp>,
    pub provider: &'static str,
    pub model: String,
}

pub struct OfflineWhisperEngine {
    model_path: PathBuf,
    context: WhisperContext,
    gpu_requested: bool,
    gpu_enabled: bool,
}

impl OfflineWhisperEngine {
    pub fn load(model_path: &Path, use_gpu: bool) -> Result<Self, String> {
        if !model_path.is_file() {
            return Err(format!(
                "Whisper model is missing: {}",
                model_path.display()
            ));
        }

        let (context, gpu_enabled) = if use_gpu {
            match Self::load_context(model_path, true) {
                Ok(context) => (context, true),
                Err(gpu_error) => (
                    Self::load_context(model_path, false).map_err(|cpu_error| {
                        format!(
                            "GPU initialization failed ({gpu_error}); CPU fallback failed ({cpu_error})"
                        )
                    })?,
                    false,
                ),
            }
        } else {
            (Self::load_context(model_path, false)?, false)
        };

        Ok(Self {
            model_path: model_path.to_path_buf(),
            context,
            gpu_requested: use_gpu,
            gpu_enabled,
        })
    }

    pub fn load_cpu_fallback(model_path: &Path) -> Result<Self, String> {
        Ok(Self {
            model_path: model_path.to_path_buf(),
            context: Self::load_context(model_path, false)?,
            gpu_requested: true,
            gpu_enabled: false,
        })
    }

    fn load_context(model_path: &Path, use_gpu: bool) -> Result<WhisperContext, String> {
        let mut parameters = WhisperContextParameters::default();
        parameters.use_gpu(use_gpu);

        WhisperContext::new_with_params(model_path, parameters)
            .map_err(|error| format!("failed to load the offline Whisper model: {error}"))
    }

    pub fn uses_configuration(&self, model_path: &Path, use_gpu: bool) -> bool {
        self.model_path == model_path && self.gpu_requested == use_gpu
    }

    pub fn gpu_enabled(&self) -> bool {
        self.gpu_enabled
    }

    pub fn transcribe(
        &self,
        audio_path: &Path,
        language: Option<&str>,
        thread_budget: usize,
        progress_callback: Option<Box<dyn FnMut(i32)>>,
        abort_callback: Option<Box<dyn FnMut() -> bool>>,
    ) -> Result<OfflineTranscription, String> {
        if !audio_path.is_file() {
            return Err(format!(
                "Prepared audio is missing: {}",
                audio_path.display()
            ));
        }

        let samples = read_pcm_wav(audio_path)?;
        let mut state = self
            .context
            .create_state()
            .map_err(|error| format!("failed to create Whisper state: {error}"))?;
        let mut params = FullParams::new(SamplingStrategy::Greedy { best_of: 1 });
        let threads = thread_budget.max(1).min(i32::MAX as usize) as i32;

        params.set_n_threads(threads);
        params.set_translate(false);
        params.set_no_context(true);
        params.set_print_progress(false);
        params.set_print_realtime(false);
        params.set_print_special(false);
        params.set_print_timestamps(false);
        params.set_token_timestamps(true);
        params.set_progress_callback_safe::<Option<Box<dyn FnMut(i32)>>, Box<dyn FnMut(i32)>>(
            progress_callback,
        );
        params
            .set_abort_callback_safe::<Option<Box<dyn FnMut() -> bool>>, Box<dyn FnMut() -> bool>>(
                abort_callback,
            );

        if let Some(language) =
            language.filter(|value| !value.is_empty() && *value != "auto" && *value != "multi")
        {
            params.set_language(Some(language));
        } else {
            params.set_language(None);
        }

        state
            .full(params, &samples)
            .map_err(|error| format!("offline Whisper transcription failed: {error}"))?;

        let mut timestamps = Vec::new();
        let mut text = String::new();
        for segment in state.as_iter() {
            let segment_text = segment
                .to_str_lossy()
                .map_err(|error| format!("failed to read Whisper output: {error}"))?;
            let cleaned = segment_text.trim().to_string();

            if cleaned.is_empty() {
                continue;
            }

            if !text.is_empty() {
                text.push(' ');
            }
            text.push_str(&cleaned);
            timestamps.push(OfflineTimestamp {
                text: cleaned,
                start: segment.start_timestamp() as f64 / 100.0,
                end: segment.end_timestamp() as f64 / 100.0,
            });
        }

        Ok(OfflineTranscription {
            text,
            timestamps,
            provider: "whisper.cpp",
            model: self
                .model_path
                .file_stem()
                .and_then(|value| value.to_str())
                .unwrap_or("whisper")
                .trim_start_matches("ggml-")
                .to_string(),
        })
    }
}

pub fn transcribe(
    model_path: &Path,
    audio_path: &Path,
    language: Option<&str>,
    thread_budget: usize,
    use_gpu: bool,
) -> Result<OfflineTranscription, String> {
    OfflineWhisperEngine::load(model_path, use_gpu)?.transcribe(
        audio_path,
        language,
        thread_budget,
        None,
        None,
    )
}

fn read_pcm_wav(audio_path: &Path) -> Result<Vec<f32>, String> {
    let mut reader = hound::WavReader::open(audio_path)
        .map_err(|error| format!("failed to open prepared WAV: {error}"))?;
    let spec = reader.spec();

    if spec.channels != 1 || spec.sample_rate != 16_000 {
        return Err(format!(
            "offline Whisper requires mono 16 kHz WAV; received {} channel(s) at {} Hz",
            spec.channels, spec.sample_rate
        ));
    }

    match (spec.sample_format, spec.bits_per_sample) {
        (hound::SampleFormat::Int, 16) => reader
            .samples::<i16>()
            .map(|sample| {
                sample
                    .map(|value| value as f32 / i16::MAX as f32)
                    .map_err(|error| format!("failed to decode prepared WAV: {error}"))
            })
            .collect(),
        (hound::SampleFormat::Float, 32) => reader
            .samples::<f32>()
            .map(|sample| sample.map_err(|error| format!("failed to decode prepared WAV: {error}")))
            .collect(),
        _ => Err(format!(
            "offline Whisper requires 16-bit PCM or 32-bit float WAV; received {}-bit {:?}",
            spec.bits_per_sample, spec.sample_format
        )),
    }
}
