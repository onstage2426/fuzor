<?php

declare(strict_types=1);

namespace Fuzor;

/**
 * Reduces words to a common stem, so "running" and "runs" match "run".
 *
 * An index uses a stemmer when SchemaConfig::$stemmer names a class implementing this interface
 * (with a constructor that takes no arguments). Every indexed word and every query word goes
 * through stem(); the index stores the class and id() and creates the stemmer again whenever it
 * is opened. Fuzor ships no stemmers; docs/language.md shows how to write one or wrap a stemming
 * library.
 *
 * This interface stays the same for all of Fuzor 2.x.
 */
interface Stemmer
{
    /**
     * Name and version of this stemmer's output, such as 'plural@1' or 'snowball-english@3.2.0'.
     *
     * Stored in the index when it is created. Change it whenever stem() returns a different stem
     * for some word: an index whose stored id differs reports in its search warnings that it was
     * built with other stems and needs rebuild().
     */
    public function id(): string;

    /**
     * The stem of one token: a lowercased word, number or CJK / Thai n-gram, as the tokenizer
     * splits text. Return the token unchanged when it has no stem; never an empty string. The same
     * token must always give the same stem.
     */
    public function stem(string $word): string;
}
