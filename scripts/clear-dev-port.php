<?php

$port = $argv[1] ?? '8000';

if (PHP_OS_FAMILY !== 'Windows') {
    exit(0);
}

function listeningProcessIds(string $port): array
{
    exec('netstat -ano 2>&1', $lines, $exitCode);

    if ($exitCode !== 0) {
        throw new RuntimeException(implode(PHP_EOL, $lines));
    }

    $processIds = [];

    foreach ($lines as $line) {
        if (! str_contains($line, 'LISTENING')) {
            continue;
        }

        $parts = preg_split('/\s+/', trim($line));
        $localAddress = $parts[1] ?? '';
        $processId = end($parts);

        if (
            preg_match('/(?:\]|:)'.preg_quote($port, '/').'$/', $localAddress) === 1
            && ctype_digit((string) $processId)
            && (int) $processId > 0
        ) {
            $processIds[(int) $processId] = true;
        }
    }

    return array_keys($processIds);
}

try {
    $processIds = listeningProcessIds($port);
} catch (RuntimeException $error) {
    fwrite(STDERR, "Could not inspect port {$port} before starting dev server.\n");
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
}

if ($processIds === []) {
    echo "Port {$port} is clear.\n";
    exit(0);
}

foreach ($processIds as $processId) {
    echo "Stopping process tree {$processId} on port {$port}...\n";
    passthru('taskkill /PID '.(int) $processId.' /F /T');
}

for ($attempt = 0; $attempt < 10; $attempt++) {
    usleep(200_000);

    try {
        $remainingProcessIds = listeningProcessIds($port);
    } catch (RuntimeException $error) {
        fwrite(STDERR, "Could not verify port {$port} after cleanup.\n");
        fwrite(STDERR, $error->getMessage().PHP_EOL);
        exit(1);
    }

    if ($remainingProcessIds === []) {
        echo "Port {$port} is clear.\n";
        exit(0);
    }
}

fwrite(
    STDERR,
    'Could not clear port '.$port.'; still owned by PID(s) '.implode(', ', $remainingProcessIds).".\n",
);
exit(1);
