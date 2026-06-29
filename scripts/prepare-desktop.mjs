import { spawn } from 'node:child_process';
import { mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const runPhp = path.join(projectRoot, 'scripts', 'run-php.mjs');
const vite = path.join(projectRoot, 'node_modules', 'vite', 'bin', 'vite.js');
const emptyBuild = process.argv[2] === 'empty';
const tauriConfig = JSON.parse(
    readFileSync(path.join(projectRoot, 'src-tauri', 'tauri.conf.json'), 'utf8'),
);

function run(command, args) {
    return new Promise((resolve, reject) => {
        const child = spawn(command, args, {
            cwd: projectRoot,
            stdio: 'inherit',
            windowsHide: true,
        });

        child.on('error', reject);
        child.on('exit', (code, signal) => {
            if (code === 0 && !signal) {
                resolve();
                return;
            }

            reject(new Error(`${path.basename(command)} exited with code ${code ?? signal}.`));
        });
    });
}

try {
    rmSync(path.join(projectRoot, 'public', 'hot'), { force: true });
    await run(process.execPath, [runPhp, 'artisan', 'app:build-vad-cli']);
    await run(process.execPath, [
        runPhp,
        'artisan',
        emptyBuild ? 'app:prepare-tauri-empty-build' : 'app:prepare-tauri-build',
    ]);
    await run(process.execPath, [vite, 'build']);
    const buildMetadataDirectory = path.join(projectRoot, 'build', 'tauri');
    mkdirSync(buildMetadataDirectory, { recursive: true });
    writeFileSync(
        path.join(buildMetadataDirectory, 'version.json'),
        `${JSON.stringify({
            version: tauriConfig.version,
            notes: `AITranscriber ${tauriConfig.version} update.`,
        }, null, 2)}\n`,
    );
} catch (error) {
    console.error(`Desktop build preparation failed: ${error.message}`);
    process.exitCode = 1;
}
