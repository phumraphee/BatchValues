<?php
namespace BatchValues\Operation;

/**
 * Plain text from HTML (1.1.0): HTML values (and, if asked, XML values or
 * Text values with tags pasted in) keep only their words, as the data type
 * Text. Paragraphs and line breaks become new lines; the entities are
 * written as the characters they stand for.
 */
class HtmlText extends AbstractTextOperation
{
    /** Tags that end a line. */
    const BLOCKS = 'p|div|li|ul|ol|h[1-6]|tr|table|blockquote|pre|dt|dd|section|article|header|footer|figure|figcaption|address';

    public function name(): string
    {
        return 'html_text';
    }

    public function label(): string
    {
        return 'Plain text from HTML'; // @translate
    }

    public function help(): string
    {
        return 'Keep only the words of HTML values, as Text: tags out, paragraphs as new lines.'; // @translate
    }

    public function allowsAllProperties(): bool
    {
        return true;
    }

    public function parse(array $post, array $ctx): array
    {
        $params = [
            'xml' => !empty($post['html_xml']),
            'literal' => !empty($post['html_literal']),
            'lines' => !array_key_exists('html_lines', $post) || !empty($post['html_lines']),
        ];
        $which = ['HTML']; // @translate
        if ($params['xml']) {
            $which[] = 'XML';
        }
        if ($params['literal']) {
            $which[] = $this->translate('Text with tags');
        }
        $params['display'] = [
            ['label' => 'Values', 'text' => implode(', ', $which)], // @translate
            ['label' => 'Paragraphs', 'text' => $params['lines'] ? 'New lines' : 'Spaces'], // @translate
        ];
        return ['params' => $params, 'errors' => []];
    }

    protected function editRow(array $row, array $params): ?array
    {
        $type = (string) $row['type'];
        $text = (string) $row['value'];
        $concerned = $type === 'html'
            || ($type === 'xml' && !empty($params['xml']))
            // Text with tags pasted in.
            || ($type === 'literal' && !empty($params['literal']) && self::hasTags($text));
        if (!$concerned) {
            return null;
        }
        $plain = self::plainText($text, !array_key_exists('lines', $params) || !empty($params['lines']));
        if ($plain === '') {
            return ['note' => $this->translate('A value would be left empty: kept as it is.')];
        }
        if ($type === 'literal' && $plain === $text) {
            return ['note' => null];
        }
        return ['columns' => ['type' => 'literal', 'value' => $plain]];
    }

    public static function hasTags(string $text): bool
    {
        return (bool) preg_match('~<(?:[a-z][a-z0-9]*\b[^<>]*|/[a-z][a-z0-9]*\s*)>|&(?:[a-z]+|#\d+|#x[0-9a-f]+);~i', $text);
    }

    /**
     * The words of an HTML (or XML) text.
     */
    public static function plainText(string $html, bool $lines = true): string
    {
        $break = $lines ? "\n" : ' ';
        // What is never read: scripts, styles, comments.
        $html = preg_replace('~<(script|style)\b[^>]*>.*?</\1\s*>~is', ' ', $html);
        $html = preg_replace('~<!--.*?-->~s', ' ', $html);
        $html = preg_replace('~<br\s*/?>~i', $break, $html);
        $html = preg_replace('~</?(?:' . self::BLOCKS . ')\b[^>]*>~i', $break, $html);
        $html = preg_replace('~<(?:td|th)\b[^>]*>~i', ' ', $html);
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\u{00A0}", "\r\n", "\r"], [' ', "\n", "\n"], $text);
        $out = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim(preg_replace('/[ \t\x{200B}]+/u', ' ', $line));
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return implode($break, $out);
    }
}
