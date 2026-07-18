import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

JSON.parse(readFileSync('src-tauri/tauri.conf.json', 'utf8'));
JSON.parse(readFileSync('tauri.dilg.conf.json', 'utf8'));
JSON.parse(readFileSync('tauri.jerva.conf.json', 'utf8'));
console.log('tauri config valid');

const php = join('php', 'php.exe');
const result = spawnSync(php, ['artisan', 'test', 'tests/Unit/UpdatePackageConfigurationTest.php'], {
  stdio: 'inherit',
  shell: false,
});

if (result.error) {
  throw result.error;
}

process.exitCode = result.status ?? 1;
