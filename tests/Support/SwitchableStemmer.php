<?php

declare(strict_types=1);

namespace Fuzor\Tests\Support;

use Fuzor\Stemmer;

/** A stemmer whose id() a test can change, as a new release of a stemmer package would. Strips a trailing "s". */
final class SwitchableStemmer implements Stemmer
{
    public static string $id = 'switchable@1';

    public function id(): string
    {
        return self::$id;
    }

    public function stem(string $word): string
    {
        return strlen($word) > 3 && str_ends_with($word, 's') ? substr($word, 0, -1) : $word;
    }
}
