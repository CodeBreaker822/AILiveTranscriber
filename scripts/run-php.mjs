import { existsSync } from 'node:fs';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { resourceEnvironment } from './resource-profile.mjs';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const bundledWindowsPhp = path.join(projectRoot, 'php', 'php.exe');

if (process.platform !== 'win32' || !existsSync(bundledWindowsPhp)) {
    throw new Error('AITranscriber requires its bundled Windows PHP runtime.');
}

const php = bundledWindowsPhp;
const caBundle = path.join(projectRoot, 'php', 'extras', 'ssl', 'cacert.pem');
const runtimeEnvironment = {
    ...process.env,
    CURL_CA_BUNDLE: caBundle,
    SSL_CERT_FILE: caBundle,
    AI_TRANSCRIBER_CA_BUNDLE: caBundle,
    ...resourceEnvironment(),
};

const child = spawn(php, process.argv.slice(2), {
    cwd: projectRoot,
    stdio: 'inherit',
    windowsHide: true,
    env: runtimeEnvironment,
});

child.on('error', (error) => {
    console.error(`Unable to start PHP (${php}): ${error.message}`);
    process.exitCode = 1;
});

child.on('exit', (code, signal) => {
    process.exitCode = signal ? 1 : (code ?? 1);
});
