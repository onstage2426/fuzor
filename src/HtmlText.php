<?php

declare(strict_types=1);

namespace Fuzor;

/**
 * Converts between HTML and plain text for indexing and result formatting.
 *
 * toText() is used for SchemaConfig::$stripHtml. Plain strip_tags() is not enough for search: it glues
 * the words of adjacent blocks together ("<p>foo</p><p>bar</p>" → "foobar"), keeps the
 * contents of <script> and <style>, and leaves entities encoded, so "&amp;" and "&nbsp;"
 * would be indexed as the words "amp" and "nbsp".
 *
 * @internal
 */
final class HtmlText
{
    /** Elements whose content is never rendered as text. */
    private const string HIDDEN_ELEMENTS = 'script|style|template|noscript';

    /**
     * Elements that start a new line or cell when rendered, so their tags separate words.
     * Inline elements (a, b, em, span, …) are removed without a separator: "fo<b>o</b>" is one word.
     */
    private const string BLOCK_ELEMENTS = 'address|article|aside|blockquote|br|caption|dd|details|dialog|div|dl|dt'
        . '|fieldset|figcaption|figure|footer|form|h[1-6]|header|hgroup|hr|li|legend|main|nav|ol|option|p|pre'
        . '|section|summary|table|tbody|td|tfoot|th|thead|tr|ul';

    /**
     * Return the visible text of $html.
     *
     * Drops script/style/template/noscript content, turns block-level tags and <br> into
     * spaces, removes the remaining tags, decodes entities, removes soft hyphens, and collapses
     * whitespace. Entities are decoded after the tags are removed, so encoded markup such as
     * "&lt;b&gt;" survives as the literal text "<b>".
     */
    public static function toText(string $html): string
    {
        $text = preg_replace('#<(' . self::HIDDEN_ELEMENTS . ')\b[^>]*>.*?</\1\s*>#is', ' ', $html) ?? $html;
        $text = preg_replace('#</?(?:' . self::BLOCK_ELEMENTS . ')\b[^>]*>#i', ' ', $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00AD}", '', $text);
        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $text) ?? $text);
    }

    /**
     * Escape plain text for safe inclusion in HTML element content or a quoted attribute.
     *
     * Invalid UTF-8 is replaced rather than dropping the whole string (ENT_SUBSTITUTE).
     */
    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
