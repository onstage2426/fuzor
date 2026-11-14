<?php

declare(strict_types=1);

namespace Fuzor\Tests\Support;

use Fuzor\Stemmer;

/**
 * A full English stemmer for the tests (Fuzor ships no stemmers), as a fixed table: the stems a
 * Snowball English stemmer gives for the words the tests use. Other words are left unchanged; a
 * test that needs another stemmed word adds it here.
 *
 * It covers what a full stemmer does that a plural stemmer does not: stems that are not a prefix
 * of the word (hiking: hike), stems that are not words (strawberries: strawberri) and endings
 * other than plurals (running: run, connection: connect).
 */
final class EnglishStemmer implements Stemmer
{
    private const array STEMS = [
        'boots'        => 'boot',
        'cable'        => 'cabl',
        'cars'         => 'car',
        'climbing'     => 'climb',
        'completely'   => 'complet',
        'conection'    => 'conect',
        'connected'    => 'connect',
        'connecting'   => 'connect',
        'connection'   => 'connect',
        'connections'  => 'connect',
        'coupe'        => 'coup',
        'else'         => 'els',
        'entirely'     => 'entir',
        'errors'       => 'error',
        'every'        => 'everi',
        'formals'      => 'formal',
        'gloves'       => 'glove',
        'healthy'      => 'healthi',
        'hikes'        => 'hike',
        'hiking'       => 'hike',
        'important'    => 'import',
        'jumping'      => 'jump',
        'kayaking'     => 'kayak',
        'keeps'        => 'keep',
        'nearby'       => 'nearbi',
        'only'         => 'onli',
        'paddli'       => 'padd',
        'paddling'     => 'paddl',
        'poles'        => 'pole',
        'quickly'      => 'quick',
        'running'      => 'run',
        'shirts'       => 'shirt',
        'shirtzs'      => 'shirtz',
        'shoes'        => 'shoe',
        'single'       => 'singl',
        'sleeves'      => 'sleev',
        'something'    => 'someth',
        'strawberries' => 'strawberri',
        'strawbery'    => 'strawberi',
        'trekking'     => 'trekk',
        'trousers'     => 'trouser',
        'unrelated'    => 'unrel',
        'words'        => 'word',
        'wordy'        => 'wordi',
    ];

    public function id(): string
    {
        return 'english-table@1';
    }

    public function stem(string $word): string
    {
        return self::STEMS[$word] ?? $word;
    }
}
