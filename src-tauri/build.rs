fn main() {
    tauri_build::try_build(tauri_build::Attributes::new().app_manifest(
        tauri_build::AppManifest::new().commands(&[
            "open_external_url",
            "save_text_export",
            "save_text_export_with_dialog",
            "choose_audio_file",
        ]),
    ))
    .expect("failed to build Tauri application");
}
