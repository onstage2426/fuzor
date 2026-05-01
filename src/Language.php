<?php

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
 * Single source of truth for supported BCP 47 language tags, their display names,
 * and which features (stopwords, stemming) are available per language.
 */
final class Language
{
    /**
     * @var array<string, array{name: string, stopwords: bool, stemmer: class-string<SnowballStemmer>|null}>
     */
    private const array LANG_MAP = [
        'af' => ['name' => 'Afrikaans',  'stopwords' => true,  'stemmer' => null],
        'ar' => ['name' => 'Arabic',     'stopwords' => true,  'stemmer' => SnowballArabic::class],
        'bg' => ['name' => 'Bulgarian',  'stopwords' => true,  'stemmer' => null],
        'bn' => ['name' => 'Bengali',    'stopwords' => true,  'stemmer' => null],
        'br' => ['name' => 'Breton',     'stopwords' => true,  'stemmer' => null],
        'ca' => ['name' => 'Catalan',    'stopwords' => true,  'stemmer' => SnowballCatalan::class],
        'cs' => ['name' => 'Czech',      'stopwords' => true,  'stemmer' => null],
        'da' => ['name' => 'Danish',     'stopwords' => true,  'stemmer' => SnowballDanish::class],
        'de' => ['name' => 'German',     'stopwords' => true,  'stemmer' => SnowballGerman::class],
        'el' => ['name' => 'Greek',      'stopwords' => true,  'stemmer' => SnowballGreek::class],
        'en' => ['name' => 'English',    'stopwords' => true,  'stemmer' => SnowballEnglish::class],
        'eo' => ['name' => 'Esperanto',  'stopwords' => true,  'stemmer' => SnowballEsperanto::class],
        'es' => ['name' => 'Spanish',    'stopwords' => true,  'stemmer' => SnowballSpanish::class],
        'et' => ['name' => 'Estonian',   'stopwords' => true,  'stemmer' => SnowballEstonian::class],
        'eu' => ['name' => 'Basque',     'stopwords' => true,  'stemmer' => SnowballBasque::class],
        'fa' => ['name' => 'Persian',    'stopwords' => true,  'stemmer' => null],
        'fi' => ['name' => 'Finnish',    'stopwords' => true,  'stemmer' => SnowballFinnish::class],
        'fr' => ['name' => 'French',     'stopwords' => true,  'stemmer' => SnowballFrench::class],
        'ga' => ['name' => 'Irish',      'stopwords' => true,  'stemmer' => SnowballIrish::class],
        'gl' => ['name' => 'Galician',   'stopwords' => true,  'stemmer' => null],
        'gu' => ['name' => 'Gujarati',   'stopwords' => true,  'stemmer' => null],
        'ha' => ['name' => 'Hausa',      'stopwords' => true,  'stemmer' => null],
        'he' => ['name' => 'Hebrew',     'stopwords' => true,  'stemmer' => null],
        'hi' => ['name' => 'Hindi',      'stopwords' => true,  'stemmer' => SnowballHindi::class],
        'hr' => ['name' => 'Croatian',   'stopwords' => true,  'stemmer' => null],
        'hu' => ['name' => 'Hungarian',  'stopwords' => true,  'stemmer' => SnowballHungarian::class],
        'hy' => ['name' => 'Armenian',   'stopwords' => true,  'stemmer' => SnowballArmenian::class],
        'id' => ['name' => 'Indonesian', 'stopwords' => true,  'stemmer' => SnowballIndonesian::class],
        'it' => ['name' => 'Italian',    'stopwords' => true,  'stemmer' => SnowballItalian::class],
        'ja' => ['name' => 'Japanese',   'stopwords' => true,  'stemmer' => null],
        'ko' => ['name' => 'Korean',     'stopwords' => true,  'stemmer' => null],
        'ku' => ['name' => 'Kurdish',    'stopwords' => true,  'stemmer' => null],
        'la' => ['name' => 'Latin',      'stopwords' => true,  'stemmer' => null],
        'lt' => ['name' => 'Lithuanian', 'stopwords' => true,  'stemmer' => SnowballLithuanian::class],
        'lv' => ['name' => 'Latvian',    'stopwords' => true,  'stemmer' => null],
        'mr' => ['name' => 'Marathi',    'stopwords' => true,  'stemmer' => null],
        'ms' => ['name' => 'Malay',      'stopwords' => true,  'stemmer' => null],
        'ne' => ['name' => 'Nepali',     'stopwords' => false, 'stemmer' => SnowballNepali::class],
        'nl' => ['name' => 'Dutch',      'stopwords' => true,  'stemmer' => SnowballDutch::class],
        'no' => ['name' => 'Norwegian',  'stopwords' => true,  'stemmer' => SnowballNorwegian::class],
        'pl' => ['name' => 'Polish',     'stopwords' => true,  'stemmer' => SnowballPolish::class],
        'pt' => ['name' => 'Portuguese', 'stopwords' => true,  'stemmer' => SnowballPortuguese::class],
        'ro' => ['name' => 'Romanian',   'stopwords' => true,  'stemmer' => SnowballRomanian::class],
        'ru' => ['name' => 'Russian',    'stopwords' => true,  'stemmer' => SnowballRussian::class],
        'sk' => ['name' => 'Slovak',     'stopwords' => true,  'stemmer' => null],
        'sl' => ['name' => 'Slovenian',  'stopwords' => true,  'stemmer' => null],
        'so' => ['name' => 'Somali',     'stopwords' => true,  'stemmer' => null],
        'sr' => ['name' => 'Serbian',    'stopwords' => false, 'stemmer' => SnowballSerbian::class],
        'st' => ['name' => 'Sotho',      'stopwords' => true,  'stemmer' => null],
        'sv' => ['name' => 'Swedish',    'stopwords' => true,  'stemmer' => SnowballSwedish::class],
        'sw' => ['name' => 'Swahili',    'stopwords' => true,  'stemmer' => null],
        'ta' => ['name' => 'Tamil',      'stopwords' => false, 'stemmer' => SnowballTamil::class],
        'th' => ['name' => 'Thai',       'stopwords' => true,  'stemmer' => null],
        'tl' => ['name' => 'Tagalog',    'stopwords' => true,  'stemmer' => null],
        'tr' => ['name' => 'Turkish',    'stopwords' => true,  'stemmer' => SnowballTurkish::class],
        'uk' => ['name' => 'Ukrainian',  'stopwords' => true,  'stemmer' => null],
        'ur' => ['name' => 'Urdu',       'stopwords' => true,  'stemmer' => null],
        'vi' => ['name' => 'Vietnamese', 'stopwords' => true,  'stemmer' => null],
        'yi' => ['name' => 'Yiddish',    'stopwords' => false, 'stemmer' => SnowballYiddish::class],
        'yo' => ['name' => 'Yoruba',     'stopwords' => true,  'stemmer' => null],
        'zh' => ['name' => 'Chinese',    'stopwords' => true,  'stemmer' => null],
        'zu' => ['name' => 'Zulu',       'stopwords' => true,  'stemmer' => null],
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
        return array_map(fn($v) => $v['name'], self::LANG_MAP);
    }

    /** Whether the language has any support (stopwords or stemming). */
    public static function supports(string $lang): bool
    {
        return isset(self::LANG_MAP[$lang]);
    }

    /** Whether a stopword list is available for the given language. */
    public static function hasStopwords(string $lang): bool
    {
        return self::LANG_MAP[$lang]['stopwords'] ?? false;
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
