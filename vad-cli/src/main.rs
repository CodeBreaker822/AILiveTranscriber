use serde::Serialize;
use silero::{detect_speech, SampleRate, Session, SpeechOptions};
use std::env;
use std::path::PathBuf;
use std::time::Duration;

#[derive(Debug)]
struct CliOptions {
    audio_path: PathBuf,
    threshold: f32,
    min_speech_ms: u64,
    min_silence_ms: u64,
    speech_pad_ms: u64,
}

#[derive(Serialize)]
struct VadSegment {
    start_ms: u64,
    end_ms: u64,
    start_seconds: f64,
    end_seconds: f64,
}

#[derive(Serialize)]
struct VadOutput {
    has_speech: bool,
    duration_ms: u64,
    speech_ms: u64,
    segments: Vec<VadSegment>,
}

#[derive(Serialize)]
struct ErrorOutput {
    has_speech: bool,
    error: String,
}

fn main() {
    match run() {
        Ok(output) => {
            println!(
                "{}",
                serde_json::to_string(&output).unwrap_or_else(|_| "{}".to_string())
            );
        }
        Err(error) => {
            println!(
                "{}",
                serde_json::to_string(&ErrorOutput {
                    has_speech: false,
                    error,
                })
                .unwrap_or_else(|_| "{\"has_speech\":false}".to_string())
            );
            std::process::exit(2);
        }
    }
}

fn run() -> Result<VadOutput, String> {
    let options = parse_args()?;
    let samples = read_mono_16k_wav(&options.audio_path)?;
    let duration_ms = ((samples.len() as f64 / 16_000.0) * 1000.0).round() as u64;
    let speech_options = SpeechOptions::default()
        .with_sample_rate(SampleRate::Rate16k)
        .with_start_threshold(options.threshold)
        .with_min_speech_duration(Duration::from_millis(options.min_speech_ms))
        .with_min_silence_duration(Duration::from_millis(options.min_silence_ms))
        .with_speech_pad(Duration::from_millis(options.speech_pad_ms));
    let mut session = Session::bundled().map_err(|error| error.to_string())?;
    let detected = detect_speech(&mut session, &samples, speech_options)
        .map_err(|error| error.to_string())?;
    let segments: Vec<VadSegment> = detected
        .iter()
        .map(|segment| {
            let start_seconds = segment.start_seconds();
            let end_seconds = segment.end_seconds();

            VadSegment {
                start_ms: (start_seconds * 1000.0).round() as u64,
                end_ms: (end_seconds * 1000.0).round() as u64,
                start_seconds,
                end_seconds,
            }
        })
        .collect();
    let speech_ms = segments
        .iter()
        .map(|segment| segment.end_ms.saturating_sub(segment.start_ms))
        .sum();

    Ok(VadOutput {
        has_speech: !segments.is_empty(),
        duration_ms,
        speech_ms,
        segments,
    })
}

fn parse_args() -> Result<CliOptions, String> {
    let mut args = env::args().skip(1);
    let mut audio_path = None;
    let mut threshold = 0.5_f32;
    let mut min_speech_ms = 250_u64;
    let mut min_silence_ms = 500_u64;
    let mut speech_pad_ms = 80_u64;

    while let Some(arg) = args.next() {
        match arg.as_str() {
            "--audio" => audio_path = args.next().map(PathBuf::from),
            "--threshold" => threshold = parse_next(&mut args, "--threshold")?,
            "--min-speech-ms" => min_speech_ms = parse_next(&mut args, "--min-speech-ms")?,
            "--min-silence-ms" => min_silence_ms = parse_next(&mut args, "--min-silence-ms")?,
            "--speech-pad-ms" => speech_pad_ms = parse_next(&mut args, "--speech-pad-ms")?,
            "--help" | "-h" => {
                return Err(
                    "usage: vad-cli --audio <mono-16khz-wav> [--threshold 0.5] [--min-speech-ms 250] [--min-silence-ms 500] [--speech-pad-ms 80]"
                        .to_string(),
                );
            }
            value => return Err(format!("unknown argument: {value}")),
        }
    }

    let audio_path = audio_path.ok_or_else(|| "missing --audio path".to_string())?;

    if !(0.0..=1.0).contains(&threshold) {
        return Err("--threshold must be between 0 and 1".to_string());
    }

    Ok(CliOptions {
        audio_path,
        threshold,
        min_speech_ms,
        min_silence_ms,
        speech_pad_ms,
    })
}

fn parse_next<T: std::str::FromStr>(
    args: &mut impl Iterator<Item = String>,
    label: &str,
) -> Result<T, String> {
    args.next()
        .ok_or_else(|| format!("missing value for {label}"))?
        .parse::<T>()
        .map_err(|_| format!("invalid value for {label}"))
}

fn read_mono_16k_wav(path: &PathBuf) -> Result<Vec<f32>, String> {
    let mut reader = hound::WavReader::open(path)
        .map_err(|error| format!("failed to open wav audio: {error}"))?;
    let spec = reader.spec();

    if spec.channels != 1 {
        return Err("VAD input must be mono audio".to_string());
    }

    if spec.sample_rate != 16_000 {
        return Err("VAD input must use a 16 kHz sample rate".to_string());
    }

    match spec.sample_format {
        hound::SampleFormat::Float => reader
            .samples::<f32>()
            .map(|sample| sample.map_err(|error| error.to_string()))
            .collect(),
        hound::SampleFormat::Int if spec.bits_per_sample <= 16 => {
            let scale = (1_i32 << (spec.bits_per_sample.saturating_sub(1) as u32)) as f32;

            reader
                .samples::<i16>()
                .map(|sample| {
                    sample
                        .map(|value| value as f32 / scale)
                        .map_err(|error| error.to_string())
                })
                .collect()
        }
        hound::SampleFormat::Int => {
            let scale = (1_i64 << (spec.bits_per_sample.saturating_sub(1) as u32)) as f32;

            reader
                .samples::<i32>()
                .map(|sample| {
                    sample
                        .map(|value| value as f32 / scale)
                        .map_err(|error| error.to_string())
                })
                .collect()
        }
    }
}
