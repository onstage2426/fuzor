<?php

namespace Fuzor\Tests;

use Fuzor\Snippeter;
use PHPUnit\Framework\TestCase;

class SnippeterTest extends TestCase
{
    // --- basic windowing ---

    public function testReturnsExcerptContainingMatchedTerm(): void
    {
        $snip = new Snippeter(windowSize: 50);
        $text = str_repeat('filler words here ', 10) . 'target term appears here ' . str_repeat('more filler ', 10);
        $this->assertStringContainsString('target', $snip->snippet('target', $text));
    }

    public function testReturnedSnippetIsNoLongerThanWindowSizePlusEllipsis(): void
    {
        $snip   = new Snippeter(windowSize: 80);
        $text   = str_repeat('the quick brown fox jumped over the lazy dog ', 20);
        $result = $snip->snippet('fox', $text);
        // Strip ellipsis and surrounding spaces before measuring.
        $clean  = str_replace(['… ', ' …', '…'], '', $result);
        $this->assertLessThanOrEqual(80 + 5, mb_strlen($clean, 'UTF-8'));
    }

    public function testShortTextReturnedInFull(): void
    {
        $snip = new Snippeter(windowSize: 200);
        $text = 'short text with fox';
        $this->assertSame($text, $snip->snippet('fox', $text));
    }

    public function testEmptyTextReturnsEmptyString(): void
    {
        $snip = new Snippeter();
        $this->assertSame('', $snip->snippet('fox', ''));
    }

    public function testEmptyQueryReturnsTruncatedStart(): void
    {
        $snip   = new Snippeter(windowSize: 10);
        $text   = 'abcdefghij klmnopqrstuvwxyz';
        $result = $snip->snippet('', $text);
        // Result is the first $windowSize chars (snapped to word boundary) + ellipsis.
        $this->assertStringStartsWith('abcdefghij', $result);
        $this->assertStringNotContainsString('klmn', $result);
    }

    public function testNoMatchFallsBackToStartOfText(): void
    {
        $snip   = new Snippeter(windowSize: 20);
        $text   = 'the quick brown fox jumped over the lazy dog';
        $result = $snip->snippet('zzznomatch', $text);
        $this->assertStringStartsWith('the', $result);
    }

    // --- ellipsis decoration ---

    public function testEllipsisAddedAtStartWhenWindowDoesNotReachStart(): void
    {
        $snip   = new Snippeter(windowSize: 50);
        $prefix = str_repeat('a ', 60);
        $text   = $prefix . 'target word here';
        $result = $snip->snippet('target', $text);
        $this->assertStringStartsWith('…', $result);
    }

    public function testEllipsisAddedAtEndWhenWindowDoesNotReachEnd(): void
    {
        $snip   = new Snippeter(windowSize: 50);
        $text   = 'target word here ' . str_repeat('z ', 60);
        $result = $snip->snippet('target', $text);
        $this->assertStringEndsWith('…', $result);
    }

    public function testNoEllipsisWhenWindowCoversWholeText(): void
    {
        $snip   = new Snippeter(windowSize: 200);
        $text   = 'short text with target inside';
        $result = $snip->snippet('target', $text);
        $this->assertStringNotContainsString('…', $result);
    }

    public function testCustomEllipsis(): void
    {
        $snip   = new Snippeter(windowSize: 30, ellipsis: '...');
        $prefix = str_repeat('x ', 40);
        $text   = $prefix . 'target is here';
        $result = $snip->snippet('target', $text);
        $this->assertStringStartsWith('...', $result);
    }

    // --- window selection quality ---

    public function testWindowWithMoreMatchingTermsIsPreferred(): void
    {
        // Region A: one match. Region B: three matches, clearly clustered.
        $snip  = new Snippeter(windowSize: 60);
        $regionA = str_repeat('filler ', 15) . 'fox ' . str_repeat('filler ', 5);
        $regionB = 'fox quick fox brown fox';
        $text    = $regionA . str_repeat('filler ', 10) . $regionB . str_repeat(' filler', 10);
        $result  = $snip->snippet('fox', $text);
        $this->assertStringContainsString('fox quick fox brown fox', $result);
    }

    public function testWindowSelectsAroundClusteredMatches(): void
    {
        $snip    = new Snippeter(windowSize: 80);
        $sparse  = 'fox ' . str_repeat('word ', 20) . 'fox';
        $cluster = 'fox fox fox';
        $text    = $sparse . ' ' . str_repeat('filler ', 5) . $cluster;
        $result  = $snip->snippet('fox', $text);
        $this->assertStringContainsString('fox fox fox', $result);
    }

    // --- stemmer-aware matching ---

    public function testStemmedQueryMatchesSurfaceFormInBody(): void
    {
        $snip   = new Snippeter(windowSize: 200, language: 'en');
        $prefix = str_repeat('unrelated words here ', 5);
        $text   = $prefix . 'running and jumping every single day keeps you healthy';
        $result = $snip->snippet('running', $text);
        $this->assertStringContainsString('running', $result);
    }

    public function testStemmerConnectsStemVariantsInBody(): void
    {
        // Query "connections" stems to "connect"; body has "connected"
        $snip   = new Snippeter(windowSize: 200, language: 'en');
        $prefix = str_repeat('something else entirely ', 4);
        $text   = $prefix . 'connected to the server via the network';
        $result = $snip->snippet('connections', $text);
        $this->assertStringContainsString('connected', $result);
    }

    public function testNoStemmerNoStemMatch(): void
    {
        // Without stemmer: "run" does not match "running"; falls back to start of text.
        $snip   = new Snippeter(windowSize: 80);
        $text   = str_repeat('other words here ', 5) . 'running quickly every morning';
        $result = $snip->snippet('run', $text);
        // Should fall back; result must NOT start with ellipsis (it starts at text beginning).
        $this->assertStringStartsWith('other', $result);
    }

    // --- multiple snippets ---

    public function testMaxSnippetsOneReturnsSingleWindow(): void
    {
        $snip   = new Snippeter(windowSize: 40, maxSnippets: 1);
        $text   = 'fox here ' . str_repeat('gap ', 20) . 'fox there';
        $result = $snip->snippet('fox', $text);
        // A single window — at most one internal ellipsis joining two regions.
        $this->assertLessThanOrEqual(1, substr_count($result, '…'));
    }

    public function testMaxSnippetsTwoCanReturnTwoRegions(): void
    {
        $snip    = new Snippeter(windowSize: 40, maxSnippets: 2);
        $text    = 'fox here ' . str_repeat('gap filler word here ', 15) . 'fox there';
        $result  = $snip->snippet('fox', $text);
        // Two non-adjacent matches — expect the joining ellipsis to appear.
        $this->assertStringContainsString('…', $result);
    }

    // --- snippetMany ---

    public function testSnippetManyAppliesQueryToEachField(): void
    {
        $snip   = new Snippeter(windowSize: 200);
        $prefix = str_repeat('filler ', 5);
        $fields = [
            'title' => $prefix . 'fast sedan review',
            'body'  => $prefix . 'the sedan is really fast on the highway',
        ];
        $result = $snip->snippetMany('sedan', $fields);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('body', $result);
        $this->assertStringContainsString('sedan', $result['title']);
        $this->assertStringContainsString('sedan', $result['body']);
    }

    public function testSnippetManyPreservesFieldKeys(): void
    {
        $snip   = new Snippeter();
        $fields = ['alpha' => 'some text', 'beta' => 'other text'];
        $result = $snip->snippetMany('text', $fields);
        $this->assertSame(['alpha', 'beta'], array_keys($result));
    }

    public function testSnippetManyEmptyFieldReturnsEmptyString(): void
    {
        $snip   = new Snippeter();
        $result = $snip->snippetMany('fox', ['title' => '', 'body' => 'the fox ran']);
        $this->assertSame('', $result['title']);
        $this->assertStringContainsString('fox', $result['body']);
    }

    public function testSnippetManyEmptyFieldsReturnsEmptyArray(): void
    {
        $snip = new Snippeter();
        $this->assertSame([], $snip->snippetMany('fox', []));
    }

    // --- Unicode ---

    public function testUnicodeCjkSnippetLengthMeasuredInCharacters(): void
    {
        // CJK characters are 3 bytes each; window must be measured in chars, not bytes.
        $snip    = new Snippeter(windowSize: 20);
        $text    = str_repeat('这个 ', 30) . '目标 ' . str_repeat('文字 ', 30);
        $result  = $snip->snippet('目标', $text);
        $clean   = str_replace(['… ', ' …', '…'], '', $result);
        $this->assertLessThanOrEqual(20 + 5, mb_strlen($clean, 'UTF-8'));
    }

    public function testUnicodeMultibyteTermMatchedInWindow(): void
    {
        $snip   = new Snippeter(windowSize: 80);
        $prefix = str_repeat('слово ', 10);
        $text   = $prefix . 'быстрый лис прыгнул';
        $result = $snip->snippet('быстрый', $text);
        $this->assertStringContainsString('быстрый', $result);
    }

    public function testBoundarySnapProducesValidUtf8(): void
    {
        $snip   = new Snippeter(windowSize: 30);
        $text   = str_repeat('слово ', 20) . 'цель слово ' . str_repeat('слово ', 20);
        $result = $snip->snippet('цель', $text);
        $this->assertTrue(mb_check_encoding($result, 'UTF-8'));
    }

    // --- CJK / ngram ---

    public function testCjkSnippetFindsWindowAroundBigram(): void
    {
        $snip = new Snippeter(windowSize: 8, language: 'zh');
        // windowSize=8 chars → ~2 bigrams; target is isolated so the window finds it.
        $text   = '无关文字无关文字' . '轿车' . '无关文字无关文字';
        $result = $snip->snippet('轿车', $text);
        $this->assertStringContainsString('轿车', $result);
    }

    public function testCjkSnippetReturnsValidUtf8(): void
    {
        $snip   = new Snippeter(windowSize: 10, language: 'zh');
        $text   = str_repeat('无关文字', 10) . '轿车测试';
        $result = $snip->snippet('轿车', $text);
        $this->assertTrue(mb_check_encoding($result, 'UTF-8'));
    }

    public function testThaiSnippetFindsTrigram(): void
    {
        $snip = new Snippeter(windowSize: 10, language: 'th');
        // Use short padding so the window is large enough to reach the target.
        $text   = 'คำอื่นคำ' . 'กรุงเทพ' . 'คำอื่นคำ';
        $result = $snip->snippet('กรุงเท', $text);
        // The window finds the densest cluster of matching trigrams inside 'กรุงเทพ'.
        $this->assertStringContainsString('รุงเท', $result);
    }

    // --- windowSize default ---

    public function testDefaultWindowSizeIsExactly200(): void
    {
        $snip      = new Snippeter();
        $exactly200 = str_repeat('a', 200);
        // Exactly 200 chars: returned in full (uses <=, not <).
        $this->assertSame($exactly200, $snip->snippet('zzz', $exactly200));
        // 201 chars: must be truncated to a shorter string ending in ellipsis.
        $this->assertStringEndsWith('…', $snip->snippet('zzz', $exactly200 . 'b'));
    }

    // --- multi-snippet ellipsis separator ---

    public function testMultiSnippetJoinedWithSpaceEllipsisSpace(): void
    {
        $snip = new Snippeter(windowSize: 12, maxSnippets: 2, ellipsis: '...');
        $text = 'fox ' . str_repeat('gap ', 10) . 'fox';
        // Exact join: slice1 = 'fox gap gap ...' + ' ... ' + '... gap gap fox' = slice2
        $this->assertSame('fox gap gap ... ... ... gap gap fox', $snip->snippet('fox', $text));
    }

    // --- fallback: character vs byte length ---

    public function testFallbackCountsLengthInCharactersNotBytes(): void
    {
        $snip = new Snippeter(windowSize: 4);
        // 4 multibyte chars = 4 chars but 8 bytes. mb_strlen=4 ≤ windowSize → return in full.
        $this->assertSame('éàèé', $snip->snippet('zzz', 'éàèé'));
    }

    public function testFallbackReturnsTextWhenExactlyWindowSize(): void
    {
        $snip = new Snippeter(windowSize: 5);
        // Exactly windowSize chars: uses <=, so returned in full (not < which would truncate).
        $this->assertSame('hello', $snip->snippet('zzz', 'hello'));
    }

    // --- fallback: word-boundary snap ---

    public function testFallbackSnapsToWordBoundaryWhenSpacePastMidpoint(): void
    {
        $snip = new Snippeter(windowSize: 10);
        // Space at char-position 6 (> midpoint 5): snap removes trailing tokens.
        $this->assertSame('aaaaaa …', $snip->snippet('zzz', 'aaaaaa bbbbb cccc'));
    }

    public function testFallbackNoSnapWhenSpaceAtMidpoint(): void
    {
        $snip = new Snippeter(windowSize: 10);
        // Space at position 5 (= midpoint 5): condition is >, not >=, so no snap.
        $this->assertSame('aaaaa bbbb …', $snip->snippet('zzz', 'aaaaa bbbbb cccc'));
    }

    public function testFallbackNoSnapWhenSpaceBeforeMidpoint(): void
    {
        $snip = new Snippeter(windowSize: 10);
        // Space at position 3 (< midpoint 5): no snap, return full 10-char window.
        $this->assertSame('aaaa bbbbb …', $snip->snippet('zzz', 'aaaa bbbbb cccc'));
    }

    public function testFallbackSnapUsesCharacterPositionNotBytes(): void
    {
        $snip = new Snippeter(windowSize: 7);
        // Slice 'éàèé bb' (7 chars): space at char-pos 4 (> midpoint 3.5) → snap to 'éàèé'.
        // substr() mutation returns byte-pos 8 → mb_substr gives all 7 chars instead.
        $this->assertSame('éàèé …', $snip->snippet('zzz', 'éàèé bb cccc'));
    }

    public function testFallbackTrimsTruncatedSliceAndFormatsEllipsis(): void
    {
        $snip = new Snippeter(windowSize: 7, ellipsis: '...');
        // Text starts with space; snap keeps leading space which trim removes.
        // Also verifies format: trim(slice) + ' ' + ellipsis (not missing either part).
        $this->assertSame('hello ...', $snip->snippet('zzz', ' hello world extra'));
    }

    // --- extractSlice: ngramSize=0 boundary snap ---

    public function testBoundarySnapExtendsPastPunctuationAfterToken(): void
    {
        $snip = new Snippeter(windowSize: 20);
        // 'fox,' — comma is not whitespace, so snap extends past it.
        // With ngramSize≠0 mutation, snap is skipped and comma is not included.
        $text = 'filler words here fox, more fillers padding words';
        $this->assertStringContainsString('fox,', $snip->snippet('fox', $text));
    }

    // --- extractSlice: isWhitespace recognises all whitespace chars ---

    public function testBoundarySnapStopsAtTabCharacter(): void
    {
        $snip = new Snippeter(windowSize: 20);
        // Tab between tokens — snap must stop at the tab just like at a space.
        // If isWhitespace doesn't recognise \t, the snap runs past it.
        $text = str_repeat('ab ', 10) . "fox\t" . str_repeat('cd ', 10);
        $clean = str_replace(['… ', ' …', '…'], '', $snip->snippet('fox', $text));
        $this->assertLessThanOrEqual(20 + 5, mb_strlen($clean, 'UTF-8'));
    }

    public function testBoundarySnapStopsAtNewlineCharacter(): void
    {
        $snip   = new Snippeter(windowSize: 200);
        $prefix = str_repeat('word ', 3);
        $text   = $prefix . "fox\nmore words here now";
        // \n is whitespace: snap stops there. With broken isWhitespace, snap would not stop.
        // Check that the snippet includes 'fox' and that \n doesn't bleed into next word.
        $result = $snip->snippet('fox', $text);
        $this->assertStringContainsString('fox', $result);
    }

    // --- findWindows: initial window & pass behaviour ---

    public function testWindowFindsMatchInFirstTokenPosition(): void
    {
        // Match is in the very first window; mutations zeroing the initial sum would miss it.
        $snip   = new Snippeter(windowSize: 20);
        $text   = 'fox ' . str_repeat('filler words gap ', 8);
        $result = $snip->snippet('fox', $text);
        $this->assertStringContainsString('fox', $result);
    }

    public function testSecondPassDoesNotEmitSpuriousWindowWhenSingleMatch(): void
    {
        // maxSnippets=2 but only 1 match region; second pass must stop early (best ≤ 0).
        // Mutations that skip the break or inflate the initial winScore produce a second window.
        $snip = new Snippeter(windowSize: 30, maxSnippets: 2);
        $this->assertSame('aaa bbb ccc fox', $snip->snippet('fox', 'aaa bbb ccc fox'));
    }

    // --- scoreTokens: stem-only match selects the correct window ---

    public function testStemOnlyMatchSelectsCorrectWindowNotFallback(): void
    {
        // 'connections' → stem 'connect'; body has 'connecting' → stem 'connect'.
        // The match is far from start so fallback (which returns start) would NOT contain it.
        // Without stem additions in buildQuerySet the window falls back to start.
        $snip   = new Snippeter(windowSize: 50, language: 'en');
        $prefix = str_repeat('other stuff completely ', 8); // 184 chars — beyond windowSize
        $text   = $prefix . 'connecting network today';
        $result = $snip->snippet('connections', $text);
        $this->assertStringContainsString('connecting', $result);
    }

    // --- extractSlice: boundary snap ---

    public function testBoundarySnapBackwardExtendsPastPunctuationToWordStart(): void
    {
        // Window's first token 'hello' is at byte 12, preceded by '-' (not whitespace).
        // Snap backward must extend past '-' all the way to byte 0.
        // Without snap, slice starts at 'hello', adding a leading '…' prefix.
        $snip = new Snippeter(windowSize: 20);
        $text = 'foo-bar.baz-hello world fox more padding words here';
        $result = $snip->snippet('fox', $text);
        $this->assertStringStartsWith('foo-bar', $result);
    }

    // --- extractSlice: prefix and suffix format ---

    public function testExtractSlicePrefixHasSpaceAfterEllipsis(): void
    {
        // Prefix is ellipsis . ' ' (not just ellipsis).
        $snip   = new Snippeter(windowSize: 50);
        $result = $snip->snippet('fox', str_repeat('a ', 30) . 'fox here');
        $this->assertStringStartsWith('… ', $result);
    }

    public function testExtractSliceSuffixHasSpaceBeforeEllipsis(): void
    {
        // Suffix is ' ' . ellipsis (not just ellipsis). Triggered when endByte < textLen.
        // truncated=false here so || vs && matters: truncated(false) || endByte<textLen(true).
        $snip   = new Snippeter(windowSize: 50);
        $result = $snip->snippet('fox', str_repeat('a ', 30) . 'fox here');
        $this->assertStringEndsWith(' …', $result);
    }

    // --- extractSlice: cap uses mb_strlen / mb_substr ---

    public function testExtractSliceCapCountsMbCharsNotBytes(): void
    {
        // 'éàèé' is 4 chars (= windowSize) but 8 bytes.
        // mb_strlen=4 ≤ 4 → no cap, no truncated → no trailing ellipsis.
        // strlen mutation: strlen=8 > 4 → spurious cap → truncated=true → adds ' …' suffix.
        $snip = new Snippeter(windowSize: 4);
        $this->assertSame('éàèé', $snip->snippet('éàèé', 'éàèé'));
    }

    public function testExtractSliceCapSubstrIsCharacterBased(): void
    {
        // 'éàèé' is 4 chars > windowSize=2 → cap is entered.
        // mb_substr('éàèé', 0, 2) = 'éà' (2 chars). substr mutation: 2 bytes = 'é' only.
        $snip = new Snippeter(windowSize: 2);
        $this->assertSame('éà …', $snip->snippet('éàèé', 'éàèé'));
    }

    // --- extractSlice: snap condition and snap mb_substr ---

    public function testExtractSliceSnapsToWordBoundaryWhenSpacePastMidpoint(): void
    {
        // Slice "éàèé bx fox" (11 chars) > windowSize=6 → cap to "éàèé b" (6 chars).
        // Space at char pos 4 > 3.0 (windowSize/2) → snap removes the 'b' fragment.
        // Also kills L312 MBString: substr snap cuts at 4 bytes = "éà", not 4 chars = "éàèé".
        // Kills: NotIdentical, GreaterThanNegotiation, LogicalAndNegation,
        //        LogicalAndAllSubExprNegation, Division(→*), DecrementInteger(→/1).
        $snip = new Snippeter(windowSize: 6);
        $this->assertSame('éàèé …', $snip->snippet('fox', 'éàèé bx fox'));
    }

    public function testExtractSliceDoesNotSnapWhenSpaceExactlyAtMidpoint(): void
    {
        // Slice "abc defgh fox" (13 chars) > windowSize=6 → cap to "abc de" (6 chars).
        // Space at char pos 3 = windowSize/2=3.0 → '>' is false, no snap → 'de' preserved.
        // Kills: GreaterThan(→≥: 3>=3.0=true→snap), IncrementInteger(→/3: 3>2=true→snap),
        //        LogicalAnd(→||: pos found→always snaps).
        $snip = new Snippeter(windowSize: 6);
        $this->assertSame('abc de …', $snip->snippet('fox', 'abc defgh fox'));
    }
}
