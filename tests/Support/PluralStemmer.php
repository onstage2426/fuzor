<?php

declare(strict_types=1);

namespace Fuzor\Tests\Support;

use Fuzor\Stemmer;

/** The example stemmer of docs/language.md, kept identical so the example stays tested. */
final class PluralStemmer implements Stemmer
{
    public function id(): string
    {
        return 'plural@1';
    }

    public function stem(string $word): string
    {
        return match (true) {
            // batteries → battery
            strlen($word) > 4 && str_ends_with($word, 'ies') => substr($word, 0, -3) . 'y',
            // boxes → box, glasses → glass
            strlen($word) > 4 && preg_match('/(s|x|z|ch|sh)es$/', $word) === 1 => substr($word, 0, -2),
            // boots → boot, but class stays class
            strlen($word) > 3 && str_ends_with($word, 's') && !str_ends_with($word, 'ss') => substr($word, 0, -1),
            default => $word,
        };
    }
}
