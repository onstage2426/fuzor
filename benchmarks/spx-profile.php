<?php

declare(strict_types=1);

/**
 * SPX flat-profile aggregator.
 *
 * Parses a .txt or .txt.gz SPX full-report trace and prints a flat profile
 * sorted by self wall time.
 *
 * Usage:
 *   php benchmarks/spx-profile.php /tmp/spx/spx-full-*.txt.gz [--top=N]
 *
 * To generate a trace:
 *   SPX_ENABLED=1 SPX_REPORT=full php benchmarks/bench-wiki-search.php
 *   # Files appear in /tmp/spx/ (or whatever spx.data_dir is set to in php.ini).
 */

// ---------------------------------------------------------------------------
// Args
// ---------------------------------------------------------------------------

/** @var array<string> $argv */
$args    = array_slice($argv, 1);
$top     = 20;
$traceFile = null;

foreach ($args as $arg) {
    if (str_starts_with($arg, '--top=')) {
        $top = (int) substr($arg, 6);
    } else {
        $traceFile = $arg;
    }
}

if ($traceFile === null) {
    // Default: newest .txt or .txt.gz in /tmp/spx
    $candidates = array_merge(
        glob('/tmp/spx/spx-full-*.txt.gz') ?: [],
        glob('/tmp/spx/spx-full-*.txt')    ?: [],
    );
    usort($candidates, fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    $traceFile = $candidates[0] ?? null;
}

if ($traceFile === null || !file_exists($traceFile)) {
    fwrite(STDERR, "Usage: php spx-profile.php <spx-trace.txt[.gz]> [--top=N]\n");
    fwrite(STDERR, "No trace file found in /tmp/spx/\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Parse
// ---------------------------------------------------------------------------

$opener = str_ends_with($traceFile, '.gz') ? 'gzopen' : 'fopen';
/** @var resource $fh */
$fh = $opener($traceFile, 'r');

/** @var list<string> $funcs */
$funcs   = [];
/** @var array<int, int> $selfNs */
$selfNs  = [];
/** @var array<int, int> $calls */
$calls   = [];
/** @var list<array{int, int, int}> $stack frame: [func_id, enter_ns, child_ns] */
$stack   = [];
$section = null;

$getLine = str_ends_with($traceFile, '.gz')
    ? fn(): string|false => gzgets($fh, 4096)
    : fn(): string|false => fgets($fh, 4096);

$eof = str_ends_with($traceFile, '.gz')
    ? fn(): bool => gzeof($fh)
    : fn(): bool => feof($fh);

while (!$eof()) {
    $line = trim((string) $getLine());
    if ($line === '[events]')    { $section = 'events';    continue; }
    if ($line === '[functions]') { $section = 'functions'; continue; }
    if ($line === '')            { continue; }

    if ($section === 'functions') {
        $funcs[] = $line;
        continue;
    }

    if ($section === 'events') {
        // Format: <func_id> <1=enter|0=exit> <timestamp_ns> [<metric2> ...]
        $parts  = explode(' ', $line);
        $id     = (int) $parts[0];
        $enter  = (int) $parts[1];
        $ns     = (int) $parts[2];

        if ($enter) {
            $stack[] = [$id, $ns, 0];
            $calls[$id] = ($calls[$id] ?? 0) + 1;
        } else {
            $frame = array_pop($stack);
            assert($frame !== null);
            $total    = $ns - $frame[1];
            $self     = $total - $frame[2];
            $selfNs[$frame[0]] = ($selfNs[$frame[0]] ?? 0) + $self;
            if ($stack !== []) {
                $stack[count($stack) - 1][2] += $total;
            }
        }
    }
}

if (is_resource($fh)) {
    str_ends_with($traceFile, '.gz') ? gzclose($fh) : fclose($fh);
}

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------

arsort($selfNs);

$grandNs = array_sum($selfNs);

$shortName = fn(string $raw): string =>
    preg_replace('#.+/(src|benchmarks|tests)/#', '$1/', $raw) ?? $raw;

echo "\n";
echo sprintf("  SPX Flat Profile — %s\n", basename($traceFile));
echo sprintf("  Total wall: %.2f s\n\n", $grandNs / 1e9);
echo sprintf("  %-60s %9s %8s %8s\n", 'Function', 'Self ms', 'Calls', '% Wall');
echo '  ' . str_repeat('-', 89) . "\n";

$shown = 0;
foreach ($selfNs as $id => $ns) {
    $ms  = $ns / 1e6;
    $pct = $grandNs > 0 ? $ns / $grandNs * 100 : 0.0;
    echo sprintf(
        "  %-60s %9.1f %8d %7.1f%%\n",
        $shortName($funcs[$id] ?? "?{$id}"),
        $ms,
        $calls[$id] ?? 0,
        $pct,
    );
    if (++$shown >= $top) {
        break;
    }
}

echo "\n";
