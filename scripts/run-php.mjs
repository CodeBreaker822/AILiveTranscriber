import { existsSync } from 'node:fs';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const bundledWindowsPhp = path.join(projectRoot, 'php', 'php.exe');
const php = process.platform === 'win32' && existsSync(bundledWindowsPhp)
    ? bundledWindowsPhp
    : 'php';

const child = spawn(php, process.argv.slice(2), {
    cwd: projectRoot,
    stdio: 'inherit',
    windowsHide: true,
});

child.on('error', (error) => {
    console.error(`Unable to start PHP (${php}): ${error.message}`);
    process.exitCode = 1;
});

child.on('exit', (code, signal) => {
    process.exitCode = signal ? 1 : (code ?? 1);
});
