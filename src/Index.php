<?php

namespace Fuzor;

use Fuzor\IndexStorage;

/**
 * Factory for opening and creating Fuzor index files.
 *
 * Returns an IndexHandle on which all indexing and searching is performed.
 */
class Index
{
    private function __construct()
    {
    }

    /**
     * Resolve a path to a canonical string, even if the file does not yet exist.
     *
     * Uses realpath() on the directory (which must exist) and appends the filename.
     *
     * @param  string $path Absolute or relative path to resolve.
     * @return string       Canonical absolute path.
     * @throws \RuntimeException If the parent directory does not exist.
     */
    private static function resolvePath(string $path): string
    {
        $dir = realpath(dirname($path));
        if ($dir === false) {
            throw new \RuntimeException("Directory does not exist: " . dirname($path));
        }
        return $dir . DIRECTORY_SEPARATOR . basename($path);
    }

    /**
     * Return all supported languages as a sorted BCP 47 tag => display name map.
     *
     * Suitable for populating a select list. Every tag in this map is a valid
     * $language argument for Index::create().
     *
     * @return array<string, string>
     */
    public static function languages(): array
    {
        return Language::all();
    }

    /**
     * Return true if a valid Fuzor index exists at $path.
     *
     * Returns false for non-existent paths, non-SQLite files, and SQLite files
     * that do not contain the Fuzor schema. Never throws.
     *
     * @param string $path Absolute or relative path to check.
     */
    public static function exists(string $path): bool
    {
        $dir = realpath(dirname($path));
        /** @infection-ignore-all ReturnRemoval: without this return $dir is false; string concat yields '/<basename>' which file_exists() also returns false for, producing identical behaviour via the next guard */
        if ($dir === false) {
            return false;
        }
        $resolved = $dir . DIRECTORY_SEPARATOR . basename($path);
        if (!file_exists($resolved)) {
            return false;
        }
        try {
            $pdo    = new \PDO('sqlite:' . $resolved);
            $result = $pdo->query("SELECT 1 FROM wordlist LIMIT 1");
            return $result !== false;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Open an existing index file.
     *
     * @param  string $path Absolute or relative path to the SQLite index file.
     * @throws \RuntimeException If the index file does not exist.
     */
    public static function open(string $path): IndexHandle
    {
        $resolved = self::resolvePath($path);
        $engine   = new IndexStorage(dirname($resolved));
        $engine->selectIndex(basename($resolved));
        return new IndexHandle($engine);
    }

    /**
     * Create a new index file.
     *
     * @param  string      $path     Absolute or relative path for the new SQLite index file.
     * @param  bool        $force    When true, any existing file at that path is overwritten.
     * @param  string|null $language BCP 47 language tag for stopword filtering and stemming (e.g. 'en');
     *                               persisted in the index file.
     * @throws \RuntimeException        If a file already exists at $path and $force is false.
     * @throws \InvalidArgumentException If $language is set but has no stopword list or stemmer.
     */
    public static function create(string $path, bool $force = false, ?string $language = null): IndexHandle
    {
        if ($language !== null && !Language::supports($language)) {
            throw new \InvalidArgumentException("No stopword list or stemmer for language: '{$language}'");
        }
        $resolved = self::resolvePath($path);
        $engine   = new IndexStorage(dirname($resolved));
        $engine->createIndex(basename($resolved), $force, $language);
        return new IndexHandle($engine);
    }

    /**
     * Atomically rebuild an index by writing to a temporary file and renaming it over the target.
     *
     * The callback receives a fresh, empty IndexHandle to populate. If the callback throws,
     * the temporary file is removed and the original index is left untouched. Language is
     * preserved from the existing index when one already exists at $path.
     *
     * @param  string   $path     Absolute or relative path to the index file to rebuild.
     * @param  callable $callback fn(IndexHandle $new): void — populate the new index here.
     * @return IndexHandle        Open handle to the rebuilt index.
     * @throws \RuntimeException  If the rename fails or the parent directory does not exist.
     */
    public static function rebuild(string $path, callable $callback): IndexHandle
    {
        $resolved = self::resolvePath($path);
        $existing = file_exists($resolved) ? self::open($resolved) : null;
        $language = $existing?->language;
        /** @infection-ignore-all MethodCallRemoval: resource cleanup; GC closes the connection if skipped, no observable effect on the rebuild outcome */
        $existing?->close();

        /** @infection-ignore-all DecrementInteger|IncrementInteger|ConcatOperandRemoval|Concat: temp path construction details; any unique path in the same directory produces identical rename semantics */
        $tmp = $resolved . '.tmp-' . bin2hex(random_bytes(4));

        try {
            $handle = self::create($tmp, language: $language);
            $callback($handle);
            $handle->close();

            /** @infection-ignore-all Throw_: rename() returns false only on OS-level failure (cross-device, permissions); not reproducible in unit tests without filesystem mocking */
            if (!rename($tmp, $resolved)) {
                throw new \RuntimeException("Failed to atomically replace index at {$resolved}.");
            }
        } catch (\Throwable $e) {
            @unlink($tmp);
            @unlink($tmp . '-wal');
            @unlink($tmp . '-shm');
            throw $e;
        }

        return self::open($resolved);
    }
}
