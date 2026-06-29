import { spawn } from 'node:child_process';
import {
    cpSync,
    existsSync,
    mkdirSync,
    mkdtempSync,
    readFileSync,
    readdirSync,
    renameSync,
    rmSync,
    writeFileSync,
} from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import readline from 'node:readline/promises';
import { fileURLToPath } from 'node:url';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const releaseRoot = path.join(projectRoot, 'src-tauri', 'target', 'release');
const target = process.argv[2] ?? (process.platform === 'win32' ? 'windows' : 'linux');
const buildType = process.argv[3] === 'empty' ? 'empty' : 'standard';

if (!['windows', 'linux'].includes(target)) {
    throw new Error(`Unsupported update package target: ${target}`);
}

const tauriConfig = JSON.parse(
    readFileSync(path.join(projectRoot, 'src-tauri', 'tauri.conf.json'), 'utf8'),
);
const version = tauriConfig.version;
const platformName = `${target}-${process.arch}`;
const defaultOutputDirectory = path.join(releaseRoot, 'bundle', 'updates');

const commonPayload = [
    'artisan',
    'composer.json',
    'version.json',
    'app',
    'bootstrap',
    'config',
    'database/factories',
    'database/migrations',
    'database/seeders',
    'public',
    'resources',
    'routes',
    'vendor',
    'vad',
];

const payload = target === 'windows'
    ? ['aitranscriber.exe', ...commonPayload, 'php', 'ffmpeg']
    : ['aitranscriber', ...commonPayload];

function run(command, args, options = {}) {
    return new Promise((resolve, reject) => {
        const child = spawn(command, args, {
            stdio: 'inherit',
            windowsHide: true,
            ...options,
        });

        child.on('error', reject);
        child.on('exit', (code, signal) => {
            if (code === 0 && !signal) {
                resolve();
                return;
            }

            reject(new Error(`${path.basename(command)} exited with code ${code ?? signal}.`));
        });
    });
}

function expandHome(value) {
    const trimmed = value.trim().replace(/^['"]|['"]$/g, '');

    if (trimmed === '~') {
        return os.homedir();
    }

    if (trimmed.startsWith(`~${path.sep}`) || trimmed.startsWith('~/')) {
        return path.join(os.homedir(), trimmed.slice(2));
    }

    return trimmed;
}

async function chooseOutputDirectory() {
    const configured = process.env.AITRANSCRIBER_UPDATE_OUTPUT_DIR?.trim();

    if (configured) {
        return path.resolve(expandHome(configured));
    }

    if (!process.stdin.isTTY || !process.stdout.isTTY) {
        console.log(`No interactive terminal detected; using ${defaultOutputDirectory}`);
        return defaultOutputDirectory;
    }

    const prompt = readline.createInterface({ input: process.stdin, output: process.stdout });

    try {
        const answer = await prompt.question(
            `Where should the update ZIP be saved? [${defaultOutputDirectory}] `,
        );

        return answer.trim()
            ? path.resolve(expandHome(answer))
            : defaultOutputDirectory;
    } finally {
        prompt.close();
    }
}

async function chooseReleaseNotes() {
    const configured = process.env.AITRANSCRIBER_UPDATE_NOTES?.trim();

    if (configured) {
        return configured;
    }

    const defaultNotes = `AITranscriber ${version} update.`;

    if (!process.stdin.isTTY || !process.stdout.isTTY) {
        return defaultNotes;
    }

    const prompt = readline.createInterface({ input: process.stdin, output: process.stdout });

    try {
        const answer = await prompt.question(`Release notes? [${defaultNotes}] `);

        return answer.trim() || defaultNotes;
    } finally {
        prompt.close();
    }
}

function copyPayload(stagingDirectory) {
    for (const relativePath of payload) {
        const source = path.join(releaseRoot, relativePath);

        if (!existsSync(source)) {
            throw new Error(`Update payload is missing required build output: ${source}`);
        }

        const destination = path.join(stagingDirectory, relativePath);
        mkdirSync(path.dirname(destination), { recursive: true });
        cpSync(source, destination, { recursive: true, force: true });
    }
}

function assertProtectedFilesAreAbsent(directory) {
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
        const entryPath = path.join(directory, entry.name);

        if (entry.isDirectory()) {
            assertProtectedFilesAreAbsent(entryPath);
            continue;
        }

        if (entry.name === '.env' || entry.name.toLowerCase() === 'database.sqlite') {
            throw new Error(`Refusing to package protected user file: ${entryPath}`);
        }
    }
}

async function zipDirectory(stagingDirectory, destination) {
    if (process.platform === 'win32') {
        const escapePowerShell = (value) => value.replaceAll("'", "''");
        const command = [
            `$source = '${escapePowerShell(stagingDirectory)}'`,
            `$destination = '${escapePowerShell(destination)}'`,
            'Compress-Archive -Path (Join-Path $source \"*\") -DestinationPath $destination -CompressionLevel Optimal -Force',
        ].join('; ');

        await run('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command', command]);
        return;
    }

    await run('zip', ['-q', '-r', destination, '.'], { cwd: stagingDirectory });
}

const outputDirectory = await chooseOutputDirectory();
const releaseNotes = await chooseReleaseNotes();
mkdirSync(outputDirectory, { recursive: true });

const filename = `AITranscriber-update-${version}-${platformName}-${buildType}.zip`;
const destination = path.join(outputDirectory, filename);
const temporaryDestination = path.join(outputDirectory, `.${filename}.tmp.zip`);
const versionFile = path.join(outputDirectory, 'version.json');
const temporaryVersionFile = path.join(outputDirectory, '.version.json.tmp');
const stagingDirectory = mkdtempSync(path.join(os.tmpdir(), 'aitranscriber-update-'));

try {
    copyPayload(stagingDirectory);
    writeFileSync(
        path.join(stagingDirectory, 'version.json'),
        `${JSON.stringify({ version, notes: releaseNotes }, null, 2)}\n`,
    );
    assertProtectedFilesAreAbsent(stagingDirectory);
    writeFileSync(
        path.join(stagingDirectory, 'update-manifest.json'),
        `${JSON.stringify({
            product: tauriConfig.productName,
            version,
            target: platformName,
            buildType,
            extractInto: target === 'windows'
                ? 'AITranscriber installation directory'
                : 'external updater staging directory',
            requiresAppShutdown: true,
            protectedPaths: [
                '.env',
                'database/database.sqlite',
                'storage',
            ],
            payload,
        }, null, 2)}\n`,
    );

    rmSync(temporaryDestination, { force: true });
    await zipDirectory(stagingDirectory, temporaryDestination);
    rmSync(destination, { force: true });
    renameSync(temporaryDestination, destination);

    writeFileSync(
        temporaryVersionFile,
        `${JSON.stringify({ version, notes: releaseNotes }, null, 2)}\n`,
    );
    rmSync(versionFile, { force: true });
    renameSync(temporaryVersionFile, versionFile);

    console.log(`Update ZIP created: ${destination}`);
    console.log(`Version metadata updated: ${versionFile}`);
    console.log('Protected user files were not included: .env, database/database.sqlite, storage/');
} finally {
    rmSync(stagingDirectory, { recursive: true, force: true });
    rmSync(temporaryDestination, { force: true });
    rmSync(temporaryVersionFile, { force: true });
}
