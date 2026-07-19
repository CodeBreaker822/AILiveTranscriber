import { spawn } from 'node:child_process';
import { createHash } from 'node:crypto';
import { cpSync, existsSync, mkdirSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const runPhp = path.join(projectRoot, 'scripts', 'run-php.mjs');
const vite = path.join(projectRoot, 'node_modules', 'vite', 'bin', 'vite.js');
const cliArgs = process.argv.slice(2).map((arg) => String(arg).toLowerCase());
const emptyBuild = cliArgs.includes('empty');
const envPath = path.join(projectRoot, '.env');
const envFile = existsSync(envPath) ? readFileSync(envPath, 'utf8') : '';
const editionAliases = new Map([
    ['dilg', 'dilg'],
    ['astra', 'dilg'],
    ['jerva', 'jerva'],
]);
const requestedEdition = cliArgs.map((arg) => editionAliases.get(arg)).find(Boolean)
    || editionAliases.get(String(process.env.AI_TRANSCRIBER_EDITION || '').toLowerCase())
    || editionAliases.get(String(process.env.APP_EDITION || '').toLowerCase())
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
const selectedBrandName = editionSettings[requestedEdition].JERVA_BRAND_NAME
    || editionSettings[requestedEdition].ASTRA_BRAND_NAME
    || 'Transcriber';
const selectedResourceView = requestedEdition === 'dilg' ? 'astra' : 'jerva';
const tauriConfig = JSON.parse(
    readFileSync(path.join(projectRoot, 'src-tauri', 'tauri.conf.json'), 'utf8'),
);
const appLogoOnly = (
    editionSettings[requestedEdition].JERVA_LOGO_ONLY
    || editionSettings[requestedEdition].ASTRA_LOGO_ONLY
) === 'true';
const privateBrandingDirectory = path.normalize('branding').toLowerCase();
const primaryLogoPath = path.normalize(
    editionSettings[requestedEdition].JERVA_LOGO_PATH || editionSettings[requestedEdition].ASTRA_LOGO_PATH,
).toLowerCase();
Object.assign(process.env, editionSettings[requestedEdition]);
const bundledSherpaModels = [
    {
        file: 'pyannote-segmentation-3.0-int8.onnx',
        bytes: 1_540_506,
        sha256: 'd582f4b4c6b48205de7e0643c57df0df5615a3c176189be3fc461e9d18827b5d',
    },
    {
        file: 'nemo-en-titanet-small.onnx',
        bytes: 40_257_283,
        sha256: 'ad4a1802485d8b34c722d2a9d04249662f2ece5d28a7a039063ca22f515a789e',
    },
];

function verifyBundledSherpaModels() {
    for (const model of bundledSherpaModels) {
        const modelPath = path.join(projectRoot, 'sherpa', 'models', model.file);

        if (!existsSync(modelPath) || statSync(modelPath).size !== model.bytes) {
            throw new Error(`Bundled Sherpa model is missing or incomplete: ${modelPath}`);
        }

        const digest = createHash('sha256').update(readFileSync(modelPath)).digest('hex');
        if (digest !== model.sha256) {
            throw new Error(`Bundled Sherpa model checksum failed: ${modelPath}`);
        }
    }
}

function preparePackagedPublicDirectory() {
    const sourceDirectory = path.join(projectRoot, 'public');
    const destinationDirectory = path.join(projectRoot, 'build', 'tauri', 'public');

    rmSync(destinationDirectory, { recursive: true, force: true });
    mkdirSync(destinationDirectory, { recursive: true });
    cpSync(sourceDirectory, destinationDirectory, {
        recursive: true,
        force: true,
        filter: (sourcePath) => {
            if (!appLogoOnly) {
                return true;
            }

            const relativePath = path.relative(sourceDirectory, sourcePath);

            if (!relativePath || relativePath.startsWith('..') || path.isAbsolute(relativePath)) {
                return true;
            }

            const normalizedRelativePath = path.normalize(relativePath).toLowerCase();

            if (normalizedRelativePath === primaryLogoPath) {
                return true;
            }

            return normalizedRelativePath !== privateBrandingDirectory
                && !normalizedRelativePath.startsWith(`${privateBrandingDirectory}${path.sep}`);
        },
    });
}

function preparePackagedResourcesDirectory() {
    const sourceDirectory = path.join(projectRoot, 'resources');
    const destinationDirectory = path.join(projectRoot, 'build', 'tauri', 'resources');
    const viewsDirectory = path.join(sourceDirectory, 'views');
    const selectedViewsDirectory = path.normalize(path.join('views', selectedResourceView)).toLowerCase();
    const sharedViewsDirectory = path.normalize(path.join('views', 'shared')).toLowerCase();

    rmSync(destinationDirectory, { recursive: true, force: true });
    mkdirSync(destinationDirectory, { recursive: true });
    cpSync(sourceDirectory, destinationDirectory, {
        recursive: true,
        force: true,
        filter: (sourcePath) => {
            const relativePath = path.relative(sourceDirectory, sourcePath);

            if (!relativePath || relativePath.startsWith('..') || path.isAbsolute(relativePath)) {
                return true;
            }

            const normalizedRelativePath = path.normalize(relativePath).toLowerCase();

            if (
                normalizedRelativePath === 'views'
                || normalizedRelativePath === selectedViewsDirectory
                || normalizedRelativePath.startsWith(`${selectedViewsDirectory}${path.sep}`)
                || normalizedRelativePath === sharedViewsDirectory
                || normalizedRelativePath.startsWith(`${sharedViewsDirectory}${path.sep}`)
            ) {
                return true;
            }

            if (
                normalizedRelativePath.startsWith(`views${path.sep}`)
                || normalizedRelativePath === path.relative(sourceDirectory, viewsDirectory).toLowerCase()
            ) {
                return false;
            }

            return true;
        },
    });
}

function setEnvValue(content, key, value) {
    const safeValue = String(value).includes(' ') ? `"${String(value).replaceAll('"', '\\"')}"` : String(value);
    const line = `${key}=${safeValue}`;
    const pattern = new RegExp(`^\\s*${key}\\s*=.*$`, 'm');

    if (pattern.test(content)) {
        return content.replace(pattern, line);
    }

    return `${content.replace(/\s*$/, '')}\n${line}\n`;
}

function preparePackagedEnvFile() {
    let content = envFile || '';

    for (const [key, value] of Object.entries(editionSettings[requestedEdition])) {
        content = setEnvValue(content, key, value);
    }

    const buildMetadataDirectory = path.join(projectRoot, 'build', 'tauri');
    mkdirSync(buildMetadataDirectory, { recursive: true });
    writeFileSync(path.join(buildMetadataDirectory, '.env'), content.replace(/\s*$/, '\n'));
}

function run(command, args) {
    return new Promise((resolve, reject) => {
        const child = spawn(command, args, {
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

            reject(new Error(`${path.basename(command)} exited with code ${code ?? signal}.`));
        });
    });
}

try {
    verifyBundledSherpaModels();
    rmSync(path.join(projectRoot, 'public', 'hot'), { force: true });
    await run(process.execPath, [runPhp, 'artisan', 'app:build-vad-cli']);
    await run(process.execPath, [
        runPhp,
        'artisan',
        emptyBuild ? 'app:prepare-tauri-empty-build' : 'app:prepare-tauri-build',
    ]);
    await run(process.execPath, [vite, 'build']);
    const buildMetadataDirectory = path.join(projectRoot, 'build', 'tauri');
    mkdirSync(buildMetadataDirectory, { recursive: true });
    mkdirSync(path.join(buildMetadataDirectory, 'workers'), { recursive: true });
    preparePackagedEnvFile();
    preparePackagedPublicDirectory();
    preparePackagedResourcesDirectory();
    writeFileSync(
        path.join(buildMetadataDirectory, 'version.json'),
        `${JSON.stringify({
            version: tauriConfig.version,
            notes: `${selectedBrandName} ${tauriConfig.version} update.`,
        }, null, 2)}\n`,
    );
} catch (error) {
    console.error(`Desktop build preparation failed: ${error.message}`);
    process.exitCode = 1;
}
