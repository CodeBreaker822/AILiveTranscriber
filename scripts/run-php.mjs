import { existsSync } from 'node:fs';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import os from 'node:os';
import path from 'node:path';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const bundledWindowsPhp = path.join(projectRoot, 'php', 'php.exe');
const php = process.platform === 'win32' && existsSync(bundledWindowsPhp)
    ? bundledWindowsPhp
    : 'php';
const caBundle = path.join(projectRoot, 'php', 'extras', 'ssl', 'cacert.pem');
const logicalProcessors = Math.max(1, os.availableParallelism?.() ?? os.cpus().length);
const whisperThreads = logicalProcessors <= 2
    ? 1
    : Math.min(Math.max(1, Math.floor(logicalProcessors * 0.6)), logicalProcessors - 2);
const totalMemoryMb = Math.floor(os.totalmem() / 1024 / 1024);
const availableMemoryMb = Math.floor(os.freemem() / 1024 / 1024);
const whisperMemoryBudgetMb = Math.max(1, Math.min(
    Math.floor(totalMemoryMb / 2),
    Math.floor((availableMemoryMb * 2) / 3),
));
const runtimeEnvironment = {
    ...process.env,
    CURL_CA_BUNDLE: caBundle,
    SSL_CERT_FILE: caBundle,
    AI_TRANSCRIBER_CA_BUNDLE: caBundle,
    AI_TRANSCRIBER_WHISPER_THREADS: String(whisperThreads),
    AI_TRANSCRIBER_WHISPER_MEMORY_BUDGET_MB: String(whisperMemoryBudgetMb),
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
