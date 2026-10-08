<?php

declare(strict_types=1);

namespace Fuzor;

/**
 * Which documents a multi-word search() returns, and how it ranks them first.
 *
 * Documents are grouped by how many query words they match in a fixed order (the "words"
 * bucket); a bigger bucket always ranks first, before sort fields and relevance.
 */
enum MatchingStrategy: string
{
    /**
     * Documents with every word first, then those missing words from the end of the query:
     * "big fat cat", then "big fat", then "big". A document must contain the first word.
     */
    case Last = 'last';

    /** Only documents that contain every word. */
    case All = 'all';

    /**
     * Like Last, but words are dropped most frequent first: a document must contain the
     * rarest word, and keeping rarer words ranks higher.
     */
    case Frequency = 'frequency';
}
