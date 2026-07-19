import { spawn, spawnSync } from 'node:child_process';
import { copyFileSync, existsSync, mkdirSync, readFileSync, readdirSync, rmSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { cleanReleaseCache } from './clean-tauri-cache.mjs';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const cliArgs = process.argv.slice(2).map((arg) => String(arg).toLowerCase());
const emptyBuild = cliArgs.includes('empty');
const editionAliases = new Map([
    ['dilg', 'dilg'],
    ['astra', 'dilg'],
    ['jerva', 'jerva'],
]);
const requestedEdition = cliArgs.map((arg) => editionAliases.get(arg)).find(Boolean)
    || editionAliases.get(String(process.env.AI_TRANSCRIBER_EDITION || '').toLowerCase())
    || editionAliases.get(String(process.env.APP_EDITION || '').toLowerCase())
    || 'dilg';
const gpuBuildRequired = process.argv.includes('gpu') || process.env.AI_TRANSCRIBER_ENABLE_GPU === '1';
const cudaBuildRequired = gpuBuildRequired || process.argv.includes('cuda') || process.env.AI_TRANSCRIBER_ENABLE_CUDA === '1';
const vulkanBuildRequired = gpuBuildRequired || process.argv.includes('vulkan') || process.env.AI_TRANSCRIBER_ENABLE_VULKAN === '1';

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

function findCudaToolkit() {
    const configured = String(process.env.CUDA_PATH || '').trim();
    const toolkitRoot = 'C:\\Program Files\\NVIDIA GPU Computing Toolkit\\CUDA';
    const candidates = [
        configured,
        ...(
            existsSync(toolkitRoot)
                ? readdirSync(toolkitRoot, { withFileTypes: true })
                    .filter((entry) => entry.isDirectory())
                    .map((entry) => path.join(toolkitRoot, entry.name))
                    .sort((left, right) => right.localeCompare(left, undefined, { numeric: true }))
                : []
        ),
    ];

    return candidates.find((candidate) => candidate
        && existsSync(path.join(candidate, 'bin', 'nvcc.exe'))
        && existsSync(path.join(candidate, 'lib', 'x64', 'cuda.lib'))
        && existsSync(path.join(candidate, 'lib', 'x64', 'cudart.lib'))
        && existsSync(path.join(candidate, 'lib', 'x64', 'cublas.lib'))
        && existsSync(path.join(candidate, 'lib', 'x64', 'cublasLt.lib')));
}

if (process.platform !== 'win32') {
    console.error('Desktop releases must be built on Windows.');
    process.exit(1);
}

const cudaToolkit = findCudaToolkit();
const vulkanSdk = findVulkanSdk();

if (cudaBuildRequired && !cudaToolkit) {
    console.error(
        'The CUDA Toolkit is required to build the optional CUDA worker. Install it on the build machine, '
        + 'or run the normal build without the `cuda`/`gpu` flag. CUDA runtime DLLs are not bundled for users.',
    );
    process.exit(1);
}

if (vulkanBuildRequired && !vulkanSdk) {
    console.error(
        'The Vulkan SDK is required to build the optional Vulkan worker. Install it with '
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
const userCargo = path.join(String(process.env.USERPROFILE || ''), '.cargo', 'bin', 'cargo.exe');
const cargo = existsSync(userCargo) ? userCargo : 'cargo';
const brandConfig = requestedEdition === 'jerva' ? 'tauri.jerva.conf.json' : 'tauri.dilg.conf.json';
const brandConfigJson = JSON.parse(readFileSync(path.join(projectRoot, brandConfig), 'utf8'));

if (
    requestedEdition === 'jerva'
    && brandConfigJson?.plugins?.updater?.pubkey === 'JERVA_UPDATER_PUBLIC_KEY_NOT_CONFIGURED'
) {
    console.error('JERVA updater public key is not configured. Generate a separate JERVA Tauri signing key, then place its public key in tauri.jerva.conf.json.');
    process.exit(1);
}

const workerDirectory = path.join(projectRoot, 'build', 'tauri', 'workers');
rmSync(workerDirectory, { recursive: true, force: true });
mkdirSync(workerDirectory, { recursive: true });

function buildOfflineWhisperWorker(backend, feature, env) {
    const cargoArgs = ['build', '--release', '--bin', 'offline-whisper-worker'];

    if (feature) {
        cargoArgs.push('--features', feature);
    }

    const result = spawnSync(cargo, cargoArgs, {
        cwd: path.join(projectRoot, 'src-tauri'),
        env: {
            ...process.env,
            ...env,
        },
        stdio: 'inherit',
        windowsHide: true,
    });

    if (result.status !== 0) {
        throw new Error(`Failed to build the optional ${backend} offline Whisper worker.`);
    }

    copyFileSync(
        path.join(projectRoot, 'src-tauri', 'target', 'release', 'offline-whisper-worker.exe'),
        path.join(workerDirectory, `offline-whisper-${backend}.exe`),
    );
}

try {
    if (cudaToolkit) {
        console.log('Building optional CUDA offline Whisper worker.');
        buildOfflineWhisperWorker('cuda', 'cuda', { CUDA_PATH: cudaToolkit });
    } else {
        console.log('Skipping optional CUDA offline Whisper worker; CUDA Toolkit is not installed on this build machine.');
    }

    if (vulkanSdk) {
        console.log('Building optional Vulkan offline Whisper worker.');
        buildOfflineWhisperWorker('vulkan', 'vulkan', { VULKAN_SDK: vulkanSdk });
    } else {
        console.log('Skipping optional Vulkan offline Whisper worker; Vulkan SDK is not installed on this build machine.');
    }
} catch (error) {
    console.error(error.message);
    process.exit(1);
}

const args = ['build', '--config', 'tauri.release.conf.json', '--config', brandConfig];

if (emptyBuild) {
    args.push('--config', 'tauri.empty.conf.json');
}

const child = spawn(process.execPath, [tauriCli, ...args], {
    cwd: projectRoot,
    env: {
        ...process.env,
        AI_TRANSCRIBER_EDITION: requestedEdition,
        ...(cudaToolkit ? { CUDA_PATH: cudaToolkit } : {}),
        ...(vulkanSdk ? { VULKAN_SDK: vulkanSdk } : {}),
    },
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
