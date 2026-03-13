<?php

namespace Fuzor;

/**
 * Stopword filter for a single language.
 *
 * The word list is loaded from a PHP file on first use and cached on the instance.
 * The PHP opcode cache ensures the underlying file is only parsed once per process.
 */
final class Stopwords
{
    /** @var array<string, true>|null Associative map for O(1) lookup; null until first use. */
    private ?array $words = null;

    private string $lang;

    /**
     * @param string $lang BCP 47 language tag (e.g. 'en', 'fr', 'de').
     * @throws \InvalidArgumentException If no stopword list exists for the given language.
     */
    public function __construct(string $lang = 'en')
    {
        if (!file_exists(__DIR__ . '/../resources/stopwords/' . $lang . '.php')) {
            throw new \InvalidArgumentException("No stopword list for language: '{$lang}'");
        }
        $this->lang = $lang;
    }

    /**
     * Remove stopwords from a token list.
     *
     * @param  list<string> $tokens Lowercased tokens from the tokeniser.
     * @return list<string>         Tokens with stopwords removed, re-indexed.
     */
    public function filter(array $tokens): array
    {
        /** @var array<string, true> $loaded */
        $loaded = require __DIR__ . '/../resources/stopwords/' . $this->lang . '.php';
        $this->words ??= $loaded;
        return array_values(array_filter($tokens, fn(string $t) => !isset($this->words[$t])));
    }
}
