<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Notification;

/**
 * The plain text part of a mail, made from its HTML (C-9).
 *
 * Symfony's DefaultHtmlToTextConverter only strips tags. On the first real
 * confirmation (2026-09-25) that glued table cells together ("Net
 * total€11.59", "Account holderMichael Morgenbesser") and — worse — dropped
 * every link target: a reader without HTML got "View your order" and no
 * address to go to. The alternative, LeagueHtmlToMarkdownConverter, needs a
 * package Consho does not otherwise want.
 *
 * So: cells separated, rows and blocks broken into lines, links kept as
 * "text: URL", whitespace collapsed. Nothing cleverer — the HTML part is the
 * one that is designed.
 */
final class MailText
{
    public static function fromHtml(string $html): string
    {
        // A link keeps its target: "text: https://…", or the URL alone when
        // the text already is it.
        $text = preg_replace_callback(
            '#<a\s[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
            static function (array $m): string {
                $url = html_entity_decode($m[2], \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
                $label = trim(strip_tags($m[3]));

                // mailto:/tel: add nothing to a label that is the address.
                $bare = preg_replace('#^(mailto|tel):#i', '', $url) ?? $url;

                return '' === $label || $label === $url || $label === $bare ? $bare : $label.': '.$url;
            },
            $html,
        ) ?? $html;

        $text = preg_replace('#<(style|script|head)\b.*?</\1>#is', '', $text) ?? $text;

        // Whitespace around table tags is source formatting, not content: a
        // template that puts each cell on its own line would otherwise break
        // every row into as many lines.
        $text = preg_replace('#\s*(</?(?:table|thead|tbody|tfoot|tr|td|th)\b[^>]*>)\s*#i', '$1', $text) ?? $text;
        $text = preg_replace('#</t[dh]>#i', ' | ', $text) ?? $text;
        $text = preg_replace('#<br\s*/?>|</tr>#i', "\n", $text) ?? $text;
        $text = preg_replace('#<hr\b[^>]*>|</(p|div|h[1-6]|caption|table|ul|ol|li)>#i', "\n\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        $lines = [];

        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $line) {
            $line = trim(preg_replace('/[ \t]+/', ' ', $line) ?? $line);
            // The separator after the last cell of a row.
            $lines[] = rtrim(preg_replace('/\s*\|$/', '', $line) ?? $line);
        }

        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)) ?? '')."\n";
    }
}
