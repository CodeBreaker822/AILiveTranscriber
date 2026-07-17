import { spawn } from 'node:child_process';
import { existsSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { cleanReleaseCache } from './clean-tauri-cache.mjs';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const emptyBuild = process.argv[2] === 'empty';
const vulkanBuild = process.argv.includes('vulkan') || process.env.AI_TRANSCRIBER_ENABLE_VULKAN === '1';

function findVulkanSdk() {
    const configured = String(process.env.VULKAN_SDK || '').trim();
    const sdkRoot = 'C:\\VulkanSDK';
    const candidates = [
        configured,
        ...(
            existsSync(sdkRoot)
                ? readdirSync(sdkRoot, { withFileTypes: true })
                    .filter((entry) => entry.isDirectory())
                    .map((entry) => path.join(sdkRoot, entry.name))
                    .sort((left, right) => right.localeCompare(left, undefined, { numeric: true }))
                : []
        ),
    ];

    return candidates.find((candidate) => candidate
        && existsSync(path.join(candidate, 'Bin', 'vulkan-1.dll'))
        && existsSync(path.join(candidate, 'Lib', 'vulkan-1.lib'))
        && existsSync(path.join(candidate, 'Include', 'vulkan', 'vulkan.h')));
}

if (process.platform !== 'win32') {
    console.error('AITranscriber desktop releases must be built on Windows.');
    process.exit(1);
}

const vulkanSdk = vulkanBuild ? findVulkanSdk() : null;

if (vulkanBuild && !vulkanSdk) {
    console.error(
        'The Vulkan SDK is required only for Vulkan-enabled builds. Install it with '
        + '`winget install --exact --id KhronosGroup.VulkanSDK`, then rerun this command.',
    );
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

if (vulkanBuild) {
    args.push('--features', 'vulkan');
}

const child = spawn(process.execPath, [tauriCli, ...args], {
    cwd: projectRoot,
    env: vulkanSdk ? { ...process.env, VULKAN_SDK: vulkanSdk } : process.env,
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

    if (process.env.AI_TRANSCRIBER_PRUNE_TAURI_CACHE !== '1') {
        console.log('Preserved Tauri release compilation cache for faster future builds.');
        console.log('Run `npm run clean:tauri` when you need to reclaim disk space.');
        process.exitCode = 0;
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
