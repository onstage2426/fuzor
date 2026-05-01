<?php

namespace Fuzor;

use Fuzor\Stemmers\SnowballStemmer;

/**
 * Snowball stemmer wrapper for a single language.
 *
 * Maps BCP 47 language tags to generated Snowball stemmer classes.
 * Instantiated by IndexStorage when $language is set and a stemmer exists.
 */
final class Stemmer
{
    private SnowballStemmer $impl;

    /**
     * @param string $lang BCP 47 language tag.
     * @throws \InvalidArgumentException If no stemmer exists for the given language.
     */
    public function __construct(string $lang)
    {
        $class = Language::stemmerClass($lang);
        if ($class === null) {
            throw new \InvalidArgumentException("No stemmer for language: '{$lang}'");
        }
        $this->impl = new $class();
    }

    /** Whether a stemmer is available for the given BCP 47 language tag. */
    public static function supports(string $lang): bool
    {
        return Language::hasStemmer($lang);
    }

    /** Stem a single token. */
    public function stemToken(string $token): string
    {
        return $this->impl->stemWord($token);
    }

    /**
     * Stem every token in a list.
     *
     * @param  list<string> $tokens
     * @return list<string>
     */
    public function stemTokens(array $tokens): array
    {
        return array_map($this->impl->stemWord(...), $tokens);
    }
}
