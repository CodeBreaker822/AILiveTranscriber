use serde::Serialize;
use sherpa_onnx::{
    FastClusteringConfig, OfflineSpeakerDiarization, OfflineSpeakerDiarizationConfig,
    OfflineSpeakerSegmentationModelConfig, OfflineSpeakerSegmentationPyannoteModelConfig,
    SpeakerEmbeddingExtractor, SpeakerEmbeddingExtractorConfig, SpeakerEmbeddingManager,
};
use std::collections::HashMap;
use std::path::{Path, PathBuf};
use std::time::{Duration, Instant};

const SAMPLE_RATE: i32 = 16_000;
const MAX_EMBEDDING_AUDIO_SECONDS: usize = 30;

#[derive(Serialize)]
pub struct SpeakerSegment {
    pub start: f32,
    pub end: f32,
    pub speaker_id: String,
}

#[derive(Serialize)]
pub struct DiarizationResult {
    pub segments: Vec<SpeakerSegment>,
    pub num_speakers: i32,
}

pub struct SpeakerDiarizationEngine {
    segmentation_model: PathBuf,
    embedding_model: PathBuf,
    threads: i32,
    threshold: f32,
    diarizer: OfflineSpeakerDiarization,
    embedding_extractor: SpeakerEmbeddingExtractor,
    sessions: HashMap<String, SpeakerSession>,
}

struct SpeakerProfile {
    centroid: Vec<f32>,
    observations: u32,
}

struct SpeakerSession {
    manager: SpeakerEmbeddingManager,
    profiles: HashMap<String, SpeakerProfile>,
    next_speaker: usize,
    last_used: Instant,
}

impl SpeakerDiarizationEngine {
    pub fn load(
        segmentation_model: &Path,
        embedding_model: &Path,
        threads: usize,
        threshold: f32,
    ) -> Result<Self, String> {
        for (label, path) in [
            ("speaker segmentation", segmentation_model),
            ("speaker embedding", embedding_model),
        ] {
            if !path.is_file() {
                return Err(format!("{label} model is missing: {}", path.display()));
            }
        }

        let threads = threads.max(1).min(i32::MAX as usize) as i32;
        let threshold = threshold.clamp(0.1, 1.5);
        let embedding_config = SpeakerEmbeddingExtractorConfig {
            model: Some(embedding_model.to_string_lossy().into_owned()),
            num_threads: threads,
            provider: Some("cpu".to_string()),
            ..Default::default()
        };
        let config = OfflineSpeakerDiarizationConfig {
            segmentation: OfflineSpeakerSegmentationModelConfig {
                pyannote: OfflineSpeakerSegmentationPyannoteModelConfig {
                    model: Some(segmentation_model.to_string_lossy().into_owned()),
                },
                num_threads: threads,
                provider: Some("cpu".to_string()),
                ..Default::default()
            },
            embedding: embedding_config.clone(),
            clustering: FastClusteringConfig {
                threshold,
                ..Default::default()
            },
            ..Default::default()
        };
        let diarizer = OfflineSpeakerDiarization::create(&config)
            .ok_or_else(|| "failed to initialize Sherpa-ONNX speaker diarization".to_string())?;
        let embedding_extractor = SpeakerEmbeddingExtractor::create(&embedding_config)
            .ok_or_else(|| "failed to initialize Sherpa-ONNX speaker tracking".to_string())?;

        Ok(Self {
            segmentation_model: segmentation_model.to_path_buf(),
            embedding_model: embedding_model.to_path_buf(),
            threads,
            threshold,
            diarizer,
            embedding_extractor,
            sessions: HashMap::new(),
        })
    }

    pub fn uses_models(
        &self,
        segmentation_model: &Path,
        embedding_model: &Path,
        threads: usize,
        threshold: f32,
    ) -> bool {
        self.segmentation_model == segmentation_model
            && self.embedding_model == embedding_model
            && self.threads == threads.max(1).min(i32::MAX as usize) as i32
            && (self.threshold - threshold.clamp(0.1, 1.5)).abs() < f32::EPSILON
    }

    pub fn diarize(
        &mut self,
        audio_path: &Path,
        session_id: Option<&str>,
        match_threshold: f32,
        max_speakers: usize,
    ) -> Result<DiarizationResult, String> {
        let samples = read_pcm_wav(audio_path)?;
        let result = self
            .diarizer
            .process(&samples)
            .ok_or_else(|| "Sherpa-ONNX could not diarize the prepared audio".to_string())?;
        let local_segments = result
            .sort_by_start_time()
            .into_iter()
            .filter(|segment| segment.end > segment.start)
            .map(|segment| (segment.start, segment.end, segment.speaker))
            .collect::<Vec<_>>();
        let speaker_map = session_id
            .filter(|value| !value.trim().is_empty())
            .map(|value| {
                self.resolve_session_speakers(
                    value.trim(),
                    &samples,
                    &local_segments,
                    match_threshold,
                    max_speakers,
                )
            })
            .transpose()?
            .unwrap_or_default();
        let segments = local_segments
            .into_iter()
            .map(|(start, end, local_speaker)| SpeakerSegment {
                start,
                end,
                speaker_id: speaker_map
                    .get(&local_speaker)
                    .cloned()
                    .unwrap_or_else(|| format!("speaker_{}", local_speaker + 1)),
            })
            .collect::<Vec<_>>();
        let num_speakers = if speaker_map.is_empty() {
            result.num_speakers()
        } else {
            speaker_map
                .values()
                .collect::<std::collections::HashSet<_>>()
                .len() as i32
        };

        Ok(DiarizationResult {
            segments,
            num_speakers,
        })
    }

    pub fn release_session(&mut self, session_id: &str) -> bool {
        self.sessions.remove(session_id.trim()).is_some()
    }

    pub fn purge_expired_sessions(&mut self, max_idle: Duration) {
        self.sessions
            .retain(|_, session| session.last_used.elapsed() < max_idle);
    }

    pub fn has_active_sessions(&self) -> bool {
        !self.sessions.is_empty()
    }

    fn resolve_session_speakers(
        &mut self,
        session_id: &str,
        samples: &[f32],
        segments: &[(f32, f32, i32)],
        match_threshold: f32,
        max_speakers: usize,
    ) -> Result<HashMap<i32, String>, String> {
        let mut local_audio: HashMap<i32, (f32, Vec<f32>)> = HashMap::new();
        let sample_limit = SAMPLE_RATE as usize * MAX_EMBEDDING_AUDIO_SECONDS;

        for (start, end, speaker) in segments {
            let (_, audio) = local_audio
                .entry(*speaker)
                .or_insert_with(|| (*start, Vec::new()));
            if audio.len() >= sample_limit {
                continue;
            }

            let start_sample = ((*start).max(0.0) * SAMPLE_RATE as f32) as usize;
            let end_sample = ((*end).max(*start) * SAMPLE_RATE as f32) as usize;
            let from = start_sample.min(samples.len());
            let to = end_sample.min(samples.len());
            let remaining = sample_limit.saturating_sub(audio.len());

            if to > from && remaining > 0 {
                audio.extend_from_slice(&samples[from..to.min(from + remaining)]);
            }
        }

        let mut embeddings = Vec::new();
        for (speaker, (first_start, audio)) in local_audio {
            if let Some(embedding) = self.compute_embedding(&audio) {
                embeddings.push((speaker, first_start, embedding));
            }
        }
        embeddings.sort_by(|first, second| first.1.total_cmp(&second.1));

        let dimension = self.embedding_extractor.dim();
        if !self.sessions.contains_key(session_id) {
            let manager = SpeakerEmbeddingManager::create(dimension)
                .ok_or_else(|| "failed to create temporary Sherpa speaker registry".to_string())?;
            self.sessions.insert(
                session_id.to_string(),
                SpeakerSession {
                    manager,
                    profiles: HashMap::new(),
                    next_speaker: 1,
                    last_used: Instant::now(),
                },
            );
        }
        let session = self
            .sessions
            .get_mut(session_id)
            .ok_or_else(|| "temporary Sherpa speaker registry is unavailable".to_string())?;
        session.last_used = Instant::now();

        let threshold = match_threshold.clamp(0.1, 1.0);
        let speaker_limit = max_speakers.clamp(1, 64);
        let mut resolved = HashMap::new();

        for (local_speaker, _, embedding) in embeddings {
            let matched = session.manager.search(&embedding, threshold).or_else(|| {
                if session.profiles.len() < speaker_limit {
                    None
                } else {
                    session
                        .manager
                        .get_best_matches(&embedding, 0.0, 1)
                        .into_iter()
                        .next()
                        .map(|result| result.name)
                }
            });
            let speaker_id = if let Some(speaker_id) = matched {
                if let Some(profile) = session.profiles.get_mut(&speaker_id) {
                    update_centroid(profile, &embedding);
                    session.manager.remove(&speaker_id);
                    session.manager.add(&speaker_id, &profile.centroid);
                }
                speaker_id
            } else if session.profiles.len() >= speaker_limit {
                session
                    .profiles
                    .keys()
                    .next()
                    .cloned()
                    .unwrap_or_else(|| "speaker_1".to_string())
            } else {
                let speaker_id = format!("speaker_{}", session.next_speaker);
                session.next_speaker += 1;
                session.manager.add(&speaker_id, &embedding);
                session.profiles.insert(
                    speaker_id.clone(),
                    SpeakerProfile {
                        centroid: embedding,
                        observations: 1,
                    },
                );
                speaker_id
            };

            resolved.insert(local_speaker, speaker_id);
        }

        Ok(resolved)
    }

    fn compute_embedding(&self, samples: &[f32]) -> Option<Vec<f32>> {
        let stream = self.embedding_extractor.create_stream()?;
        stream.accept_waveform(SAMPLE_RATE, samples);
        stream.input_finished();

        self.embedding_extractor
            .is_ready(&stream)
            .then(|| self.embedding_extractor.compute(&stream))
            .flatten()
    }
}

fn update_centroid(profile: &mut SpeakerProfile, embedding: &[f32]) {
    if profile.centroid.len() != embedding.len() {
        return;
    }

    let weight = profile.observations.min(8) as f32;
    for (current, next) in profile.centroid.iter_mut().zip(embedding) {
        *current = ((*current * weight) + *next) / (weight + 1.0);
    }

    let magnitude = profile
        .centroid
        .iter()
        .map(|value| value * value)
        .sum::<f32>()
        .sqrt();
    if magnitude > f32::EPSILON {
        for value in &mut profile.centroid {
            *value /= magnitude;
        }
    }
    profile.observations = profile.observations.saturating_add(1);
}

#[cfg(test)]
mod tests {
    use super::{update_centroid, SpeakerProfile};

    #[test]
    fn centroid_updates_in_place_without_accumulating_embeddings() {
        let mut profile = SpeakerProfile {
            centroid: vec![1.0, 0.0],
            observations: 1,
        };

        update_centroid(&mut profile, &[0.8, 0.6]);

        assert_eq!(profile.centroid.len(), 2);
        assert_eq!(profile.observations, 2);
        let magnitude = profile
            .centroid
            .iter()
            .map(|value| value * value)
            .sum::<f32>()
            .sqrt();
        assert!((magnitude - 1.0).abs() < 0.0001);
    }
}

fn read_pcm_wav(audio_path: &Path) -> Result<Vec<f32>, String> {
    let mut reader = hound::WavReader::open(audio_path)
        .map_err(|error| format!("failed to open diarization WAV: {error}"))?;
    let spec = reader.spec();

    if spec.channels != 1 || spec.sample_rate != 16_000 {
        return Err(format!(
            "speaker diarization requires mono 16 kHz WAV; received {} channel(s) at {} Hz",
            spec.channels, spec.sample_rate
        ));
    }

    match (spec.sample_format, spec.bits_per_sample) {
        (hound::SampleFormat::Int, 16) => reader
            .samples::<i16>()
            .map(|sample| {
                sample
                    .map(|value| value as f32 / i16::MAX as f32)
                    .map_err(|error| format!("failed to decode diarization WAV: {error}"))
            })
            .collect(),
        (hound::SampleFormat::Float, 32) => reader
            .samples::<f32>()
            .map(|sample| {
                sample.map_err(|error| format!("failed to decode diarization WAV: {error}"))
            })
            .collect(),
        _ => Err(format!(
            "speaker diarization requires 16-bit PCM or 32-bit float WAV; received {}-bit {:?}",
            spec.bits_per_sample, spec.sample_format
        )),
    }
}
