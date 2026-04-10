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
    /** @var array<string, string> Maps BCP 47 tag to Snowball class suffix. */
    private const array LANG_MAP = [
        'ar' => 'Arabic',
        'hy' => 'Armenian',
        'eu' => 'Basque',
        'ca' => 'Catalan',
        'da' => 'Danish',
        'nl' => 'Dutch',
        'en' => 'English',
        'eo' => 'Esperanto',
        'et' => 'Estonian',
        'fi' => 'Finnish',
        'fr' => 'French',
        'de' => 'German',
        'el' => 'Greek',
        'hi' => 'Hindi',
        'hu' => 'Hungarian',
        'id' => 'Indonesian',
        'ga' => 'Irish',
        'it' => 'Italian',
        'lt' => 'Lithuanian',
        'ne' => 'Nepali',
        'no' => 'Norwegian',
        'pl' => 'Polish',
        'pt' => 'Portuguese',
        'ro' => 'Romanian',
        'ru' => 'Russian',
        'sr' => 'Serbian',
        'es' => 'Spanish',
        'sv' => 'Swedish',
        'ta' => 'Tamil',
        'tr' => 'Turkish',
        'yi' => 'Yiddish',
    ];

    private SnowballStemmer $impl;

    /**
     * @param string $lang BCP 47 language tag.
     * @throws \InvalidArgumentException If no stemmer exists for the given language.
     */
    public function __construct(string $lang)
    {
        $name = self::LANG_MAP[$lang] ?? null;
        if ($name === null) {
            throw new \InvalidArgumentException("No stemmer for language: '{$lang}'");
        }
        $class = 'Fuzor\\Stemmers\\Snowball' . $name;
        $this->impl = new $class();
    }

    /** Whether a stemmer is available for the given BCP 47 language tag. */
    public static function supports(string $lang): bool
    {
        return isset(self::LANG_MAP[$lang]);
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
