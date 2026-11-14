<?php

declare(strict_types=1);

namespace Fuzor;

/**
 * Highlights search terms inside document text.
 *
 * Matching is Unicode-aware and case-insensitive, following the same tokenisation
 * as the index. When $asYouType is true (the default) the last token is matched as
 * a word prefix — "merc" highlights the full word "Mercedes". Stemming is
 * intentionally not applied: raw query terms are highlighted, not their stems. Index terms
 * that matched in another way (a typo, a synonym) are passed to highlightMany() by the search.
 *
 * By default the text is returned as given with tags inserted, so the output is only safe
 * to render as HTML when the input text is trusted. With $escape the output is safe HTML:
 * the text between and inside matches is escaped, the open/close tags are inserted verbatim.
 */
final readonly class Highlighter
{
    /** A word as the patterns bound it; combining marks belong to the letter before them. */
    private const string WORD_CHARS = '[\p{L}\p{N}\p{Pc}\p{M}]';

    /**
     * @param string  $open      Opening tag placed before each match.
     * @param string  $close     Closing tag placed after each match.
     * @param bool    $asYouType When true, the last query token is matched as a word prefix.
     * @param ?Stemmer $stemmer   The index's stemmer: index terms passed to highlightMany() are stems then.
     * @param bool    $escape    Treat the input as plain text and return safe HTML (see class docblock).
     *
     * @internal Use Index::highlighter(), which passes the index's stemmer.
     */
    public function __construct(
        private string $open = '<mark>',
        private string $close = '</mark>',
        private bool $asYouType = true,
        private ?Stemmer $stemmer = null,
        private bool $escape = false,
    ) {
    }

    /**
     * Highlight search terms from $phrase inside $text.
     *
     * @param  string $phrase Raw search phrase (will be tokenised).
     * @param  string $text   Text to highlight.
     * @return string         $text with matched terms wrapped in open/close tags.
     */
    public function highlight(string $phrase, string $text): string
    {
        $pattern = $this->buildPattern(Tokenizer::tokenize($phrase, false));
        if ($pattern === null) {
            return $this->escape ? HtmlText::escape($text) : $text;
        }
        return $this->apply($pattern, $text);
    }

    /**
     * Highlight search terms in multiple fields at once.
     *
     * Builds the regex pattern once and applies it to every field, so this is
     * more efficient than calling highlight() in a loop.
     *
     * @param  string                $phrase Raw search phrase (will be tokenised).
     * @param  array<string, string> $fields Field name → text pairs.
     * @param  list<string>          $terms  @internal Index terms that also matched the query (typo
     *                                       derivations, synonyms): marked like the query's words, on
     *                                       a stemmed index by their stem. CJK and Thai terms are
     *                                       left out: their n-grams have no word boundaries to test.
     * @return array<string, string>         Same keys, with highlights applied.
     */
    public function highlightMany(string $phrase, array $fields, array $terms = []): array
    {
        $tokens = Tokenizer::tokenize($phrase, false);
        $isTerm = null;
        $terms  = array_values(array_filter($terms, fn(string $term): bool => !Tokenizer::isNgramToken($term)));
        if ($terms !== []) {
            if ($this->stemmer !== null) {
                $isTerm = self::stemTest($terms, $this->stemmer);
            } else {
                // Unstemmed terms are the words themselves. The query's own stay last: the
                // last one is the word being typed.
                $tokens = [...$terms, ...$tokens];
            }
        }
        $pattern = $this->buildPattern($tokens, $isTerm !== null);
        if ($pattern === null) {
            return $this->escape ? array_map(HtmlText::escape(...), $fields) : $fields;
        }
        return array_map(fn(string $text): string => $this->apply($pattern, $text, $isTerm), $fields);
    }

    /**
     * Whether a word of the text has one of $terms as its stem. Index terms are stems there, so
     * they cannot be matched as text.
     *
     * @param  list<string> $terms
     * @return \Closure(string): bool
     */
    private static function stemTest(array $terms, Stemmer $stemmer): \Closure
    {
        $set = array_fill_keys($terms, true);
        return fn(string $word): bool => isset($set[$stemmer->stem(mb_strtolower($word, 'UTF-8'))]);
    }

    /**
     * Build a compiled regex from a token list.
     *
     * The last token becomes a prefix alternative (word-start anchor + [\p{L}\p{N}\p{Pc}]*)
     * when $asYouType is true. Prefix alternative is placed first in the alternation so it
     * takes precedence over any shorter exact token that shares the same word start.
     *
     * Returns null when the token list is empty. With $everyWord the query's matches are the
     * group `q` and any other word matches too, for apply() to test against the terms.
     *
     * @param  list<string> $tokens
     */
    private function buildPattern(array $tokens, bool $everyWord = false): ?string
    {
        if ($tokens === []) {
            return null;
        }

        // Separate tokens by script type so each group gets the right boundary style.
        $ngramAlts = [];
        $asciiAlts = [];

        foreach ($tokens as $token) {
            /** @infection-ignore-all tokenizer strips all regex special chars from tokens; preg_quote is defensive but untestable via public API */
            $quoted = preg_quote($token, '/');
            if (Tokenizer::isNgramToken($token)) {
                $ngramAlts[] = $quoted;
            } else {
                $asciiAlts[] = $quoted;
            }
        }

        // The last word is typed as a prefix, unless it is CJK or Thai (n-grams have no word end).
        if ($this->asYouType && $asciiAlts !== [] && !Tokenizer::isNgramToken($tokens[count($tokens) - 1])) {
            $lastIdx = count($asciiAlts) - 1;
            $asciiAlts[$lastIdx] .= self::WORD_CHARS . '*';
            /** @infection-ignore-all word-boundary lookahead makes alternation order irrelevant; moving prefix first is style only */
            array_unshift($asciiAlts, array_splice($asciiAlts, $lastIdx, 1)[0]);
        }

        $parts = [];

        // CJK/Thai tokens: plain substring match — word boundaries would block all matches
        // because every character in these scripts is \p{L}.
        if ($ngramAlts !== []) {
            /** @infection-ignore-all non-capturing group wrapper has no effect on match results; alternation works identically without it */
            $parts[] = '(?:' . implode('|', $ngramAlts) . ')';
        }

        // ASCII/Latin tokens: Unicode-aware word boundaries prevent partial-word highlights.
        if ($asciiAlts !== []) {
            $parts[] = '(?<!' . self::WORD_CHARS . ')(?:' . implode('|', $asciiAlts) . ')(?!' . self::WORD_CHARS . ')';
        }

        $query = implode('|', $parts);
        return $everyWord ? '/(?<q>' . $query . ')|' . self::WORD_CHARS . '+/iu' : '/' . $query . '/iu';
    }

    /**
     * Apply a compiled pattern to $text, wrapping each match in open/close tags.
     *
     * When escaping, the pattern still runs on the raw text and each unmatched and matched
     * piece is escaped on its own. Escaping first and matching afterwards would be wrong:
     * the tokenizer drops punctuation, so a query token such as "amp" would match inside
     * the "&amp;" produced for a literal "&".
     *
     * @param (\Closure(string): bool)|null $isTerm Set when the pattern matches every word (see
     *                                              buildPattern()): keeps the group `q` and its terms.
     */
    private function apply(string $pattern, string $text, ?\Closure $isTerm = null): string
    {
        if (!$this->escape) {
            return preg_replace_callback(
                $pattern,
                fn(array $m): string => $isTerm === null || ($m['q'] ?? '') !== '' || $isTerm($m[0])
                    ? $this->open . $m[0] . $this->close
                    : $m[0],
                $text
            ) ?? $text;
        }
        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) === false) {
            return HtmlText::escape($text);
        }
        $html   = '';
        $cursor = 0;
        foreach ($matches[0] as $i => [$match, $offset]) {
            if ($isTerm !== null && $matches['q'][$i][0] === '' && !$isTerm($match)) {
                continue;
            }
            $html  .= HtmlText::escape(substr($text, $cursor, $offset - $cursor))
                . $this->open . HtmlText::escape($match) . $this->close;
            $cursor = $offset + strlen($match);
        }
        return $html . HtmlText::escape(substr($text, $cursor));
    }
}
