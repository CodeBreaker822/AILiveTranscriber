import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const updatePackager = path.join(projectRoot, 'scripts', 'create-update-package.mjs');
const requestedTarget = process.argv[2] ?? 'current';
const emptyBuild = process.argv[3] === 'empty';
const currentTarget = process.platform === 'win32' ? 'windows' : process.platform;

if (!['current', 'windows', 'linux'].includes(requestedTarget)) {
    console.error(`Unknown desktop target: ${requestedTarget}`);
    process.exit(1);
}

if (requestedTarget !== 'current' && requestedTarget !== currentTarget) {
    console.error(
        `The ${requestedTarget} desktop app must be built on ${requestedTarget}. `
        + `This machine is running ${currentTarget}.`,
    );
    process.exit(1);
}

if (!['windows', 'linux'].includes(currentTarget)) {
    console.error(`Desktop builds are not configured for ${process.platform}.`);
    process.exit(1);
}

const tauri = path.join(
    projectRoot,
    'node_modules',
    '.bin',
    process.platform === 'win32' ? 'tauri.cmd' : 'tauri',
);
const args = ['build'];

if (emptyBuild) {
    args.push('--config', 'tauri.empty.conf.json');
}

const child = spawn(tauri, args, {
    cwd: projectRoot,
    stdio: 'inherit',
    windowsHide: true,
});

child.on('error', (error) => {
    console.error(`Unable to start Tauri: ${error.message}`);
    process.exitCode = 1;
});

child.on('exit', (code, signal) => {
    if (code !== 0 || signal) {
        process.exitCode = signal ? 1 : (code ?? 1);
        return;
    }

    const packager = spawn(
        process.execPath,
        [updatePackager, currentTarget, emptyBuild ? 'empty' : 'standard'],
        {
            cwd: projectRoot,
            stdio: 'inherit',
            windowsHide: true,
        },
    );

    packager.on('error', (error) => {
        console.error(`Unable to create update ZIP: ${error.message}`);
        process.exitCode = 1;
    });

    packager.on('exit', (packageCode, packageSignal) => {
        process.exitCode = packageSignal ? 1 : (packageCode ?? 1);
    });
});
