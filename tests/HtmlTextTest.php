<?php

namespace Fuzor\Tests;

use Fuzor\HtmlText;
use PHPUnit\Framework\TestCase;

class HtmlTextTest extends TestCase
{
    public function testPlainTextIsUnchanged(): void
    {
        $this->assertSame('Plain text, no markup.', HtmlText::toText('Plain text, no markup.'));
    }

    public function testBlockTagsSeparateWords(): void
    {
        $this->assertSame('foo bar', HtmlText::toText('<p>foo</p><p>bar</p>'));
        $this->assertSame('one two three', HtmlText::toText('<ul><li>one</li><li>two</li></ul><div>three</div>'));
        $this->assertSame('a b', HtmlText::toText('a<br>b'));
        $this->assertSame('a b', HtmlText::toText('a<BR />b'));
        $this->assertSame(
            'cell one cell two',
            HtmlText::toText('<table><tr><td>cell one</td><td>cell two</td></tr></table>'),
        );
        $this->assertSame('Title body', HtmlText::toText('<h2 class="x">Title</h2>body'));
    }

    public function testInlineTagsDoNotSplitWords(): void
    {
        $this->assertSame('bold word', HtmlText::toText('<b>bo</b>ld <a href="/w">word</a>'));
        $this->assertSame('foobar', HtmlText::toText('fo<em>ob</em>ar'));
    }

    public function testBlockNamePrefixDoesNotMatchInlineTag(): void
    {
        // <b> and <p> are different elements from <body>/<picture>/<param>; only whole names count.
        $this->assertSame('ab', HtmlText::toText('a<picture>b</picture>'));
    }

    public function testHiddenElementContentIsDropped(): void
    {
        $html = '<style>.x{color:red}</style>visible<script type="text/javascript">alert("hidden")</script>'
            . '<template><p>tpl</p></template><noscript>enable js</noscript> text';

        $this->assertSame('visible text', HtmlText::toText($html));
    }

    public function testEntitiesAreDecoded(): void
    {
        $this->assertSame('Fit & Flare café', HtmlText::toText('Fit &amp; Flare caf&eacute;'));
        $this->assertSame('"quoted" it\'s', HtmlText::toText('&quot;quoted&quot; it&#039;s'));
        $this->assertSame('a b', HtmlText::toText('a&nbsp;b'));
    }

    public function testEncodedMarkupSurvivesAsText(): void
    {
        $this->assertSame('use the <b> tag', HtmlText::toText('use the &lt;b&gt; tag'));
    }

    public function testSoftHyphensAreRemoved(): void
    {
        $this->assertSame('hyphenation', HtmlText::toText('hyphen&shy;ation'));
    }

    public function testWhitespaceIsCollapsedAndTrimmed(): void
    {
        $this->assertSame('a b c', HtmlText::toText("  <p>a</p>\n\n\t<p> b </p>  c  "));
    }
}
