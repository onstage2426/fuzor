<?php

namespace Fuzor\Tests;

use Fuzor\Tokenizer;
use PHPUnit\Framework\TestCase;

/**
 * Pins how PHP lowercases text and which characters the tokenizer keeps inside a word.
 *
 * An index stores terms and sort keys normalised by mb_strtolower() and by the Unicode classes
 * of PCRE; a query is normalised by the PHP that runs it. When a PHP or PCRE update changes
 * either, words indexed before the update stop matching until the index is rebuilt, without any
 * error. These tests fail on such a platform, naming the characters that changed.
 *
 * The fixture covers scripts whose characters have been assigned for years, so two current
 * platforms agree on it. A failure after a platform update means: indexes holding the listed
 * characters need a rebuild (say so in the changelog), then regenerate the fixture with
 * FUZOR_PLATFORM_UPDATE=1 vendor/bin/phpunit --filter PlatformTextTest.
 */
class PlatformTextTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/fixtures/platform-text.txt';

    /**
     * Inclusive code point ranges the fixture covers.
     *
     * @var list<array{int, int}>
     */
    private const array RANGES = [
        [0x0000, 0x036F],   // Latin, IPA, modifier letters, combining marks
        [0x0370, 0x058F],   // Greek, Cyrillic, Armenian
        [0x0590, 0x06FF],   // Hebrew, Arabic
        [0x0900, 0x097F],   // Devanagari
        [0x0E00, 0x0E7F],   // Thai
        [0x10A0, 0x10FF],   // Georgian
        [0x13A0, 0x13FF],   // Cherokee
        [0x1C90, 0x1CBF],   // Georgian capitals
        [0x1E00, 0x1FFF],   // Latin additional, Greek extended
        [0x2100, 0x218F],   // letterlike symbols, number forms
        [0x3040, 0x30FF],   // Hiragana, Katakana
        [0x4E00, 0x9FA5],   // CJK ideographs
        [0xAB70, 0xABBF],   // Cherokee small letters
        [0xAC00, 0xD7A3],   // Hangul syllables
        [0xFF00, 0xFFEF],   // halfwidth and fullwidth forms
        [0x10400, 0x1044F], // Deseret
    ];

    public function testLowercasingAndWordCharactersMatchTheFixture(): void
    {
        $actual = self::describePlatform();

        if (getenv('FUZOR_PLATFORM_UPDATE')) {
            $header = '# PHP ' . PHP_VERSION . ', PCRE ' . PCRE_VERSION . "\n";
            file_put_contents(self::FIXTURE, $header . implode("\n", $actual) . "\n");
            $this->markTestSkipped('fixture written: ' . count($actual) . ' lines');
        }

        $this->assertFileExists(self::FIXTURE);
        $lines    = file(self::FIXTURE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $expected = array_values(array_filter($lines, fn(string $line): bool => $line[0] !== '#'));

        $gone  = array_values(array_diff($expected, $actual));
        $added = array_values(array_diff($actual, $expected));
        $this->assertSame(
            [],
            [
                ...array_map(fn(string $line): string => "fixture: {$line}", array_slice($gone, 0, 20)),
                ...array_map(fn(string $line): string => "now:     {$line}", array_slice($added, 0, 20)),
            ],
            'PHP ' . PHP_VERSION . ' / PCRE ' . PCRE_VERSION . ' normalises text differently from the fixture ('
            . count($gone) . ' lines gone, ' . count($added) . ' new; the first of each are shown).'
            . ' Indexes holding these characters need a rebuild.'
        );
    }

    /**
     * Words whose normalisation has changed between platform versions before, or follows a
     * special rule: readable examples next to the fixture's table.
     */
    public function testWordsWithSpecialCasingKeepTheirTerms(): void
    {
        $cases = [
            // A capital sigma at the end of a word becomes the final form.
            'ΟΔΟΣ ΣΟΦΟΣ Σ'   => ['οδος', 'σοφος', 'σ'],
            // The dotted capital I lowercases to i + a combining dot, which stays in the word.
            'İstanbul ISPARTA' => ["i\u{307}stanbul", 'isparta'],
            // Combining marks belong to the letter before them; one without a letter is dropped.
            "E\u{301}cole \u{301}x" => ["e\u{301}cole", 'x'],
            'हिन्दी भाषा'        => ['हिन्दी', 'भाषा'],
            'العَرَبِيَّةُ'         => ['العَرَبِيَّةُ'],
            'ที่นี่ น้ำ'          => ['ที่นี่', 'น้ำ'],
            // The sharp s stays one letter in both cases.
            'Straße STRASSE ẞ' => ['straße', 'strasse', 'ß'],
            // Scripts that received their capitals late.
            'Ა Ꭰ 𐐀'           => ['ა', 'ꭰ', '𐐨'],
            // Signs with a fixed compatibility mapping.
            'K Å Ω'           => ['k', 'å', 'ω'],
            // Ligatures, digraphs and fullwidth letters.
            'ﬁnal Ǆungla ＡＢＣ' => ['ﬁnal', 'ǆungla', 'ａｂｃ'],
            // Numbers of every kind are word characters.
            'Ⅷ ① ½ ² １２３'    => ['ⅷ', '①', '½', '²', '１２３'],
            // Symbols and format characters are not.
            "🙂x so\u{AD}ft a—b" => ['x', 'so', 'ft', 'a', 'b'],
        ];
        foreach ($cases as $text => $terms) {
            $this->assertSame($terms, Tokenizer::split($text), $text);
        }
    }

    public function testSortKeysOfWordsWithSpecialCasing(): void
    {
        $cases = [
            'ΟΔΟΣ'       => 'οδος',
            'İstanbul'   => 'istanbul',
            'Straße ẞ'   => 'strasse ss',
            'K Å'        => 'k a',
            "E\u{301}COLE" => 'ecole',
            'Ა Ꭰ'        => 'ა ꭰ',
        ];
        foreach ($cases as $text => $key) {
            $this->assertSame($key, Tokenizer::sortKey($text), $text);
        }
    }

    /**
     * One line per character that lowercases to something else, and one per run of characters
     * the tokenizer keeps inside a word.
     *
     * @return list<string>
     */
    private static function describePlatform(): array
    {
        $lines = [];
        foreach (self::RANGES as [$from, $to]) {
            $runStart = null;
            for ($cp = $from; $cp <= $to + 1; $cp++) {
                $inWord = false;
                if ($cp <= $to) {
                    $char  = mb_chr($cp, 'UTF-8');
                    $lower = mb_strtolower($char, 'UTF-8');
                    if ($lower !== $char) {
                        $lines[] = 'lower ' . self::hex($char) . ' ' . self::hex($lower);
                    }
                    // Inside a word the character joins its neighbours into one term.
                    $inWord = count(Tokenizer::split("x{$char}x")) === 1;
                }
                if ($inWord && $runStart === null) {
                    $runStart = $cp;
                } elseif (!$inWord && $runStart !== null) {
                    $lines[]  = sprintf('word %04X-%04X', $runStart, $cp - 1);
                    $runStart = null;
                }
            }
        }
        return $lines;
    }

    /** Code points of $text as hex, joined by '+'. */
    private static function hex(string $text): string
    {
        return implode('+', array_map(
            fn(string $char): string => sprintf('%04X', mb_ord($char, 'UTF-8')),
            mb_str_split($text, 1, 'UTF-8'),
        ));
    }
}
