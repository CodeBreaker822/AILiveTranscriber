<?php

$port = $argv[1] ?? '8000';

if (PHP_OS_FAMILY !== 'Windows') {
    exit(0);
}

exec('netstat -ano 2>&1', $lines, $exitCode);

if ($exitCode !== 0) {
    fwrite(STDERR, "Could not inspect port {$port} before starting dev server.\n");
    fwrite(STDERR, implode(PHP_EOL, $lines).PHP_EOL);
    exit(1);
}

$processIds = [];

foreach ($lines as $line) {
    $hasPort = str_contains($line, ':'.$port) || str_contains($line, ']:'.$port);

    if (! $hasPort || ! str_contains($line, 'LISTENING')) {
        continue;
    }

    $parts = preg_split('/\s+/', trim($line));
    $processId = end($parts);

    if (ctype_digit((string) $processId) && (int) $processId > 0) {
        $processIds[(int) $processId] = true;
    }
}

if ($processIds === []) {
    echo "Port {$port} is clear.\n";
    exit(0);
}

foreach (array_keys($processIds) as $processId) {
    echo "Stopping process tree {$processId} on port {$port}...\n";
    passthru('taskkill /PID '.(int) $processId.' /F /T', $taskkillExitCode);

    if ($taskkillExitCode !== 0) {
        fwrite(STDERR, "Could not stop process {$processId} on port {$port}.\n");
        exit($taskkillExitCode);
    }
}

echo "Port {$port} is clear.\n";
