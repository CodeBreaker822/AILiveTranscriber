import { existsSync, readdirSync, rmSync, statSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const scriptPath = fileURLToPath(import.meta.url);
const projectRoot = path.resolve(path.dirname(scriptPath), '..');

const releaseCachePaths = [
    'src-tauri/target/release/.fingerprint',
    'src-tauri/target/release/build',
    'src-tauri/target/release/deps',
    'src-tauri/target/release/examples',
    'src-tauri/target/release/incremental',
    'src-tauri/target/release/app_lib.d',
    'src-tauri/target/release/app_lib.dll',
    'src-tauri/target/release/app_lib.dll.exp',
    'src-tauri/target/release/app_lib.dll.lib',
    'src-tauri/target/release/app_lib.lib',
    'src-tauri/target/release/app_lib.pdb',
    'src-tauri/target/release/libapp_lib.d',
    'src-tauri/target/release/libapp_lib.rlib',
    'src-tauri/target/release/aitranscriber.d',
];

const developmentCachePaths = [
    'src-tauri/target/debug',
    'src-tauri/target-whisper-check',
];

function remove(relativePath) {
    const target = path.resolve(projectRoot, relativePath);
    const projectPrefix = `${projectRoot}${path.sep}`.toLowerCase();

    if (!target.toLowerCase().startsWith(projectPrefix)) {
        throw new Error(`Refusing to remove a path outside the project: ${target}`);
    }

    if (!existsSync(target)) {
        return 0;
    }

    const bytes = sizeOf(target);
    rmSync(target, { recursive: true, force: true, maxRetries: 3, retryDelay: 150 });
    return bytes;
}

function sizeOf(target) {
    const entry = statSync(target);

    if (!entry.isDirectory()) {
        return entry.size;
    }

    return readdirSync(target, { withFileTypes: true }).reduce((bytes, child) => {
        return bytes + sizeOf(path.join(target, child.name));
    }, 0);
}

function formatBytes(bytes) {
    if (bytes < 1024 ** 2) {
        return `${Math.round(bytes / 1024)} KB`;
    }

    if (bytes < 1024 ** 3) {
        return `${(bytes / 1024 ** 2).toFixed(1)} MB`;
    }

    return `${(bytes / 1024 ** 3).toFixed(2)} GB`;
}

function clean(paths) {
    return formatBytes(paths.reduce((bytes, relativePath) => bytes + remove(relativePath), 0));
}

export function cleanReleaseCache() {
    return clean(releaseCachePaths);
}

export function cleanAllCaches() {
    return clean([...developmentCachePaths, ...releaseCachePaths]);
}

const isDirectRun = process.argv[1]
    && path.resolve(process.argv[1]).toLowerCase() === scriptPath.toLowerCase();

if (isDirectRun) {
    console.log(`Pruned ${cleanAllCaches()} of Tauri compilation cache.`);
    console.log('Preserved release executable, installers, update ZIPs, resources, models, database, and storage.');
}
