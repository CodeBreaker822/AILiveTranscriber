import { existsSync } from 'node:fs';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const bundledWindowsPhp = path.join(projectRoot, 'php', 'php.exe');
const php = process.platform === 'win32' && existsSync(bundledWindowsPhp)
    ? bundledWindowsPhp
    : 'php';
const vite = path.join(projectRoot, 'node_modules', 'vite', 'bin', 'vite.js');
const children = new Set();
let stopping = false;

function runOnce(args) {
    return new Promise((resolve, reject) => {
        const child = spawn(php, args, {
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

            reject(new Error(`PHP exited with code ${code ?? signal}.`));
        });
    });
}

function start(name, command, args) {
    const child = spawn(command, args, {
        cwd: projectRoot,
        stdio: 'inherit',
        windowsHide: true,
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

    start('Laravel server', php, [
        'artisan',
        'serve',
        '--host=127.0.0.1',
        '--port=8010',
    ]);
    start('Queue worker', php, ['artisan', 'queue:listen', '--tries=1']);
    start('Vite server', process.execPath, [vite]);
} catch (error) {
    stop(`Local development startup failed: ${error.message}`);
}
