<?php

declare(strict_types=1);

ini_set('memory_limit', '6G');

require_once __DIR__ . '/../vendor/autoload.php';

use Fuzor\Index;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function memMB(): float
{
    return memory_get_usage(true) / 1024 / 1024;
}

function peakMemMB(): float
{
    return memory_get_peak_usage(true) / 1024 / 1024;
}

function fmtDuration(float $seconds): string
{
    if ($seconds < 60) {
        return sprintf('%.1f s', $seconds);
    }
    $m = (int) ($seconds / 60);
    $s = $seconds - $m * 60;
    return sprintf('%dm %04.1fs', $m, $s);
}

// ---------------------------------------------------------------------------
// Dataset
// ---------------------------------------------------------------------------

$wikiDir = __DIR__ . '/../resources/datasets/wiki2';
$files   = glob($wikiDir . '/wiki_*.json') ?: [];
sort($files);

if (empty($files)) {
    fwrite(STDERR, "No wiki JSON files found in: $wikiDir\n");
    exit(1);
}

$fileCount = count($files);

// ---------------------------------------------------------------------------
// Output tee: write to STDOUT + result-wiki-index.txt
// ---------------------------------------------------------------------------

$outPath = __DIR__ . '/result-wiki-index.txt';
$outFh   = fopen($outPath, 'w');
if ($outFh === false) {
    fwrite(STDERR, "Cannot open output file: $outPath\n");
    exit(1);
}

function out(string $line = ''): void
{
    global $outFh;
    assert(is_resource($outFh));
    echo $line . PHP_EOL;
    fwrite($outFh, $line . PHP_EOL);
}

// ---------------------------------------------------------------------------
// Header
// ---------------------------------------------------------------------------

out(str_repeat('=', 60));
out(' Fuzor — Wikipedia Index Benchmark');
out(' Date  : ' . date('Y-m-d H:i:s'));
out(' Files : ' . $fileCount . ' × ~105 MB JSON');
// out(' Lang  : en (English stopwords)');
out(str_repeat('=', 60));
out();

// ---------------------------------------------------------------------------
// Index build
// ---------------------------------------------------------------------------

out(str_pad(' Index build ', 60, '-', STR_PAD_BOTH));

$idxPath = __DIR__ . '/wiki.db';
@unlink($idxPath);

$index = Index::create($idxPath, true);
// $index->language = 'en';

$totalDocs  = 0;
$buildStart = hrtime(true);

foreach ($files as $i => $file) {
    $fileLabel = basename($file);
    $fileStart = hrtime(true);

    $json = file_get_contents($file);
    /** @var list<array{id: string, title: string, text: string}> $docs */
    $docs = json_decode((string) $json, true);
    unset($json);

    $count = count($docs);

    $batch = array_map(fn(array $d): array => [
        'id'    => (int) $d['id'],
        'title' => $d['title'],
    ], $docs);
    unset($docs);

    $index->insertMany($batch);
    unset($batch);

    $elapsed    = (hrtime(true) - $fileStart) / 1e9;
    $totalDocs += $count;

    $cumElapsed = (hrtime(true) - $buildStart) / 1e9;
    $docsPerSec = $totalDocs / max($cumElapsed, 0.001);

    out(sprintf(
        '  [%3d/%3d] %-20s %5d docs  %6.1f s  %7.0f docs/s  mem %5.0f MB',
        $i + 1,
        $fileCount,
        $fileLabel,
        $count,
        $elapsed,
        $docsPerSec,
        memMB(),
    ));
}

$buildElapsed = (hrtime(true) - $buildStart) / 1e9;
$idxSize      = file_exists($idxPath) ? filesize($idxPath) / 1024 / 1024 : 0;

out();
out(sprintf('  %-42s %12s',   'Total documents indexed',   number_format($totalDocs)));
out(sprintf('  %-42s %12s',   'Total build time',          fmtDuration($buildElapsed)));
out(sprintf('  %-42s %8.0f docs/s', 'Average throughput',  $totalDocs / $buildElapsed));
out(sprintf('  %-42s %8.1f MB',     'Peak PHP memory',     peakMemMB()));
out(sprintf('  %-42s %8.1f MB',     'Index file size',     $idxSize));
out();

$index->close();

out(str_repeat('-', 60));
out('Done.');

fclose($outFh);
echo PHP_EOL . 'Results written to: ' . substr($outPath, strlen((string) realpath(__DIR__ . '/..')) + 1) . PHP_EOL;
