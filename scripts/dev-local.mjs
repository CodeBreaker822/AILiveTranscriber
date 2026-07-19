import { existsSync } from 'node:fs';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { resourceEnvironment } from './resource-profile.mjs';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const bundledWindowsPhp = path.join(projectRoot, 'php', 'php.exe');
const editionAliases = new Map([
    ['dilg', 'dilg'],
    ['astra', 'dilg'],
    ['jerva', 'jerva'],
]);
const cliArgs = process.argv.slice(2).map((arg) => String(arg).toLowerCase());
const requestedEdition = cliArgs.map((arg) => editionAliases.get(arg)).find(Boolean)
    || editionAliases.get(String(process.env.AI_TRANSCRIBER_EDITION || '').toLowerCase())
    || 'dilg';
const editionSettings = {
    dilg: {
        APP_EDITION: 'dilg',
        ASTRA_APP_NAME: 'ASTRA AI Transcriber',
        ASTRA_BRAND_NAME: 'ASTRA AI Transcriber',
        ASTRA_BRAND_SHORT: 'ASTRA',
        ASTRA_BRAND_TAGLINE: 'Adaptive Speech Transcription and Recording Assistant.',
        ASTRA_LOGO_PATH: 'AILogo.png',
        ASTRA_EXTRA_LOGOS: 'branding/logo-1.png,branding/logo-2.png',
        ASTRA_FOOTER_TEXT: 'ASTRA - Adaptive Speech Transcription and Recording Assistant. All rights reserved.',
        ASTRA_LOGO_ONLY: 'false',
        ASTRA_FOOTER_LICENSE: 'false',
    },
    jerva: {
        APP_EDITION: 'jerva',
        JERVA_APP_NAME: 'JERVA Transcriber',
        JERVA_BRAND_NAME: 'JERVA Transcriber',
        JERVA_BRAND_SHORT: 'JERVA',
        JERVA_BRAND_TAGLINE: 'Transcription workspace.',
        JERVA_LOGO_PATH: 'JervaLogo.png',
        JERVA_EXTRA_LOGOS: '',
        JERVA_FOOTER_TEXT: 'JERVA Transcriber. All rights reserved.',
        JERVA_LOGO_ONLY: 'true',
        JERVA_FOOTER_LICENSE: 'false',
    },
};

if (process.platform !== 'win32' || !existsSync(bundledWindowsPhp)) {
    throw new Error('Desktop development requires its bundled Windows PHP runtime.');
}

const php = bundledWindowsPhp;
const caBundle = path.join(projectRoot, 'php', 'extras', 'ssl', 'cacert.pem');
const runtimeEnvironment = {
    ...process.env,
    CURL_CA_BUNDLE: caBundle,
    SSL_CERT_FILE: caBundle,
    AI_TRANSCRIBER_CA_BUNDLE: caBundle,
    ...resourceEnvironment(),
    ...editionSettings[requestedEdition],
    AI_TRANSCRIBER_EDITION: requestedEdition,
    AI_TRANSCRIBER_DESKTOP_DEV: 'true',
};
const vite = path.join(projectRoot, 'node_modules', 'vite', 'bin', 'vite.js');
const publicDirectory = path.join(projectRoot, 'public');
const laravelServerRouter = path.join(
    projectRoot,
    'vendor',
    'laravel',
    'framework',
    'src',
    'Illuminate',
    'Foundation',
    'resources',
    'server.php',
);
const children = new Set();
let stopping = false;

function runOnce(args) {
    return new Promise((resolve, reject) => {
        const child = spawn(php, args, {
            cwd: projectRoot,
            stdio: 'inherit',
            windowsHide: true,
            env: runtimeEnvironment,
        });

        child.on('error', reject);
        child.on('exit', (code, signal) => {
            if (code === 0 && !signal) {
                resolve();
                return;
            }

            reject(new Error(`PHP exited with code ${code ?? signal}.`));
        });
    });
}

function start(name, command, args, options = {}) {
    const child = spawn(command, args, {
        cwd: options.cwd ?? projectRoot,
        stdio: 'inherit',
        windowsHide: true,
        env: runtimeEnvironment,
    });

    children.add(child);
    child.on('error', (error) => stop(`${name} failed to start: ${error.message}`));
    child.on('exit', (code, signal) => {
        children.delete(child);

        if (!stopping) {
            stop(`${name} stopped with code ${code ?? signal}.`);
        }
    });
}

function stop(message) {
    if (stopping) {
        return;
    }

    stopping = true;

    if (message) {
        console.error(message);
    }

    for (const child of children) {
        child.kill();
    }

    setTimeout(() => process.exit(message ? 1 : 0), 250);
}

process.on('SIGINT', () => stop());
process.on('SIGTERM', () => stop());

try {
    await runOnce(['scripts/clear-dev-port.php', '8010']);
    await runOnce(['scripts/clear-dev-port.php', '5173']);
    await runOnce(['artisan', 'view:clear', '--no-ansi']);
    await runOnce(['artisan', 'config:clear', '--no-ansi']);
    await runOnce(['artisan', 'queue:clear', 'database', '--queue=default', '--force', '--no-interaction']);
    await runOnce(['artisan', 'queue:clear', 'database', '--queue=audio', '--force', '--no-interaction']);
    await runOnce(['artisan', 'queue:clear', 'database', '--queue=transcripts', '--force', '--no-interaction']);

    start('Vite server', process.execPath, [vite]);
    start('Laravel server', php, [
        '-S',
        '127.0.0.1:8010',
        '-t',
        publicDirectory,
        laravelServerRouter,
    ], { cwd: publicDirectory });
    start('Audio queue worker', php, [
        'artisan',
        'queue:work',
        '--queue=audio',
        '--sleep=1',
        '--tries=3',
        '--timeout=0',
    ]);
    start('Transcript queue worker', php, [
        'artisan',
        'queue:work',
        '--queue=transcripts',
        '--sleep=1',
        '--tries=3',
        '--timeout=0',
    ]);
    start('Default queue worker', php, [
        'artisan',
        'queue:work',
        '--queue=default',
        '--sleep=1',
        '--tries=3',
        '--timeout=0',
    ]);
} catch (error) {
    stop(`Local development startup failed: ${error.message}`);
}
