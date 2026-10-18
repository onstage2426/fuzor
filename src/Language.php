<?php

declare(strict_types=1);

namespace Fuzor;

use Fuzor\Stemmers\SnowballArabic;
use Fuzor\Stemmers\SnowballArmenian;
use Fuzor\Stemmers\SnowballBasque;
use Fuzor\Stemmers\SnowballCatalan;
use Fuzor\Stemmers\SnowballDanish;
use Fuzor\Stemmers\SnowballDutch;
use Fuzor\Stemmers\SnowballEnglish;
use Fuzor\Stemmers\SnowballEsperanto;
use Fuzor\Stemmers\SnowballEstonian;
use Fuzor\Stemmers\SnowballFinnish;
use Fuzor\Stemmers\SnowballFrench;
use Fuzor\Stemmers\SnowballGerman;
use Fuzor\Stemmers\SnowballGreek;
use Fuzor\Stemmers\SnowballHindi;
use Fuzor\Stemmers\SnowballHungarian;
use Fuzor\Stemmers\SnowballIndonesian;
use Fuzor\Stemmers\SnowballIrish;
use Fuzor\Stemmers\SnowballItalian;
use Fuzor\Stemmers\SnowballLithuanian;
use Fuzor\Stemmers\SnowballNepali;
use Fuzor\Stemmers\SnowballNorwegian;
use Fuzor\Stemmers\SnowballPolish;
use Fuzor\Stemmers\SnowballPortuguese;
use Fuzor\Stemmers\SnowballRomanian;
use Fuzor\Stemmers\SnowballRussian;
use Fuzor\Stemmers\SnowballSerbian;
use Fuzor\Stemmers\SnowballSpanish;
use Fuzor\Stemmers\SnowballStemmer;
use Fuzor\Stemmers\SnowballSwedish;
use Fuzor\Stemmers\SnowballTamil;
use Fuzor\Stemmers\SnowballTurkish;
use Fuzor\Stemmers\SnowballYiddish;

/**
 * Unified language registry.
 *
 * Single source of truth for supported BCP 47 language tags and their display names: the
 * languages with a Snowball stemmer, plus Chinese, Japanese, Korean and Thai, which are split
 * into n-grams (Tokenizer::ngramSize()).
 */
final class Language
{
    /**
     * @var array<string, array{name: string, stemmer: class-string<SnowballStemmer>|null}>
     */
    private const array LANG_MAP = [
        'ar' => ['name' => 'Arabic',     'stemmer' => SnowballArabic::class],
        'ca' => ['name' => 'Catalan',    'stemmer' => SnowballCatalan::class],
        'da' => ['name' => 'Danish',     'stemmer' => SnowballDanish::class],
        'de' => ['name' => 'German',     'stemmer' => SnowballGerman::class],
        'el' => ['name' => 'Greek',      'stemmer' => SnowballGreek::class],
        'en' => ['name' => 'English',    'stemmer' => SnowballEnglish::class],
        'eo' => ['name' => 'Esperanto',  'stemmer' => SnowballEsperanto::class],
        'es' => ['name' => 'Spanish',    'stemmer' => SnowballSpanish::class],
        'et' => ['name' => 'Estonian',   'stemmer' => SnowballEstonian::class],
        'eu' => ['name' => 'Basque',     'stemmer' => SnowballBasque::class],
        'fi' => ['name' => 'Finnish',    'stemmer' => SnowballFinnish::class],
        'fr' => ['name' => 'French',     'stemmer' => SnowballFrench::class],
        'ga' => ['name' => 'Irish',      'stemmer' => SnowballIrish::class],
        'hi' => ['name' => 'Hindi',      'stemmer' => SnowballHindi::class],
        'hu' => ['name' => 'Hungarian',  'stemmer' => SnowballHungarian::class],
        'hy' => ['name' => 'Armenian',   'stemmer' => SnowballArmenian::class],
        'id' => ['name' => 'Indonesian', 'stemmer' => SnowballIndonesian::class],
        'it' => ['name' => 'Italian',    'stemmer' => SnowballItalian::class],
        'ja' => ['name' => 'Japanese',   'stemmer' => null],
        'ko' => ['name' => 'Korean',     'stemmer' => null],
        'lt' => ['name' => 'Lithuanian', 'stemmer' => SnowballLithuanian::class],
        'ne' => ['name' => 'Nepali',     'stemmer' => SnowballNepali::class],
        'nl' => ['name' => 'Dutch',      'stemmer' => SnowballDutch::class],
        'no' => ['name' => 'Norwegian',  'stemmer' => SnowballNorwegian::class],
        'pl' => ['name' => 'Polish',     'stemmer' => SnowballPolish::class],
        'pt' => ['name' => 'Portuguese', 'stemmer' => SnowballPortuguese::class],
        'ro' => ['name' => 'Romanian',   'stemmer' => SnowballRomanian::class],
        'ru' => ['name' => 'Russian',    'stemmer' => SnowballRussian::class],
        'sr' => ['name' => 'Serbian',    'stemmer' => SnowballSerbian::class],
        'sv' => ['name' => 'Swedish',    'stemmer' => SnowballSwedish::class],
        'ta' => ['name' => 'Tamil',      'stemmer' => SnowballTamil::class],
        'th' => ['name' => 'Thai',       'stemmer' => null],
        'tr' => ['name' => 'Turkish',    'stemmer' => SnowballTurkish::class],
        'yi' => ['name' => 'Yiddish',    'stemmer' => SnowballYiddish::class],
        'zh' => ['name' => 'Chinese',    'stemmer' => null],
    ];

    private function __construct()
    {
    }

    /**
     * All supported languages as a sorted BCP 47 tag => display name map.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return array_map(fn(array $v): string => $v['name'], self::LANG_MAP);
    }

    /** Whether the language is supported (a stemmer, or n-gram tokenisation). */
    public static function supports(string $lang): bool
    {
        return isset(self::LANG_MAP[$lang]);
    }

    /** Whether a Snowball stemmer is available for the given language. */
    public static function hasStemmer(string $lang): bool
    {
        return isset(self::LANG_MAP[$lang]['stemmer']);
    }

    /**
     * Snowball stemmer class for the given language, or null if none exists.
     * Used internally by Stemmer to instantiate the correct implementation.
     *
     * @return class-string<SnowballStemmer>|null
     */
    public static function stemmerClass(string $lang): ?string
    {
        return self::LANG_MAP[$lang]['stemmer'] ?? null;
    }
}
