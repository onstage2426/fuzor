<?php

declare(strict_types=1);

namespace Fuzor;

/** How a query token matched the index, in QueryToken::$matchType (Index::inspectQuery()). */
enum MatchType: string
{
    /** The token itself is indexed. */
    case Exact = 'exact';

    /** The last token, typed as you go, matched indexed words that start with it. */
    case Prefix = 'prefix';

    /** Only typo derivations of the token are indexed (Config::$typoTolerance). */
    case Fuzzy = 'fuzzy';

    /** Nothing in the index matches the token. */
    case None = 'none';
}
