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
        $resolved = self::resolvePath($path);
        $engine   = new IndexStorage(dirname($resolved));
        $engine->createIndex(basename($resolved), $force, $language);
        return new IndexHandle($engine);
    }
}
