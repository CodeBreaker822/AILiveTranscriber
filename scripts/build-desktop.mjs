import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { cleanReleaseCache } from './clean-tauri-cache.mjs';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const emptyBuild = process.argv[2] === 'empty';

if (process.platform !== 'win32') {
    console.error('AITranscriber desktop releases must be built on Windows.');
    process.exit(1);
}

const tauriCli = path.join(
    projectRoot,
    'node_modules',
    '@tauri-apps',
    'cli',
    'tauri.js',
);
const args = ['build', '--config', 'tauri.release.conf.json'];

if (emptyBuild) {
    args.push('--config', 'tauri.empty.conf.json');
}

const child = spawn(process.execPath, [tauriCli, ...args], {
    cwd: projectRoot,
    stdio: 'inherit',
    windowsHide: true,
});

child.on('error', (error) => {
    console.error(`Unable to start Tauri: ${error.message}`);
    process.exitCode = 1;
});

child.on('exit', (code, signal) => {
    const exitCode = signal ? 1 : (code ?? 1);

    if (exitCode !== 0) {
        process.exitCode = exitCode;
        return;
    }

    try {
        const reclaimed = cleanReleaseCache();
        console.log(`Pruned ${reclaimed} of release compilation cache; final bundles were preserved.`);
        process.exitCode = 0;
    } catch (error) {
        console.error(`The build succeeded, but its compilation cache could not be pruned: ${error.message}`);
        process.exitCode = 1;
    }
});
