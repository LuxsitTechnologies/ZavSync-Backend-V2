<?php

namespace App\Services\Outreach;

use App\Exceptions\OutreachException;
use DOMDocument;
use DOMElement;

class TemplateRenderer
{
    /** @var array<int, string> */
    public const VARIABLES = ['contact.first_name', 'contact.last_name', 'contact.email', 'account.name', 'lead.name', 'deal.name', 'sender.name', 'company.name'];

    /** @param array<string, string|null> $values */
    public function render(string $content, array $values, bool $html = false): string
    {
        preg_match_all('/\{\{\s*([a-z0-9_.]+)\s*\}\}/', $content, $matches);
        $variables = array_values(array_unique($matches[1] ?? []));
        $unknown = array_values(array_diff($variables, self::VARIABLES));
        if ($unknown !== []) {
            throw new OutreachException('UNKNOWN_TEMPLATE_VARIABLE', 'Unknown template variable: '.$unknown[0].'.');
        }

        return preg_replace_callback('/\{\{\s*([a-z0-9_.]+)\s*\}\}/', function (array $match) use ($values, $html): string {
            $value = (string) ($values[$match[1]] ?? '');

            return $html ? e($value) : $value;
        }, $content) ?? $content;
    }

    /** @return array<int, string> */
    public function variables(string ...$contents): array
    {
        preg_match_all('/\{\{\s*([a-z0-9_.]+)\s*\}\}/', implode("\n", $contents), $matches);
        $variables = array_values(array_unique($matches[1] ?? []));
        $unknown = array_values(array_diff($variables, self::VARIABLES));
        if ($unknown !== []) {
            throw new OutreachException('UNKNOWN_TEMPLATE_VARIABLE', 'Unknown template variable: '.$unknown[0].'.');
        }

        return $variables;
    }

    public function sanitizeHtml(string $html): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><div id="outreach-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $allowed = ['div', 'p', 'br', 'strong', 'em', 'b', 'i', 'u', 'ul', 'ol', 'li', 'a', 'h1', 'h2', 'h3', 'blockquote'];
        $nodes = iterator_to_array($document->getElementsByTagName('*'));
        foreach (array_reverse($nodes) as $node) {
            if (! $node instanceof DOMElement || $node->getAttribute('id') === 'outreach-root') {
                continue;
            }
            if (! in_array(mb_strtolower($node->tagName), $allowed, true)) {
                $node->parentNode?->removeChild($node);

                continue;
            }
            foreach (iterator_to_array($node->attributes) as $attribute) {
                if ($node->tagName !== 'a' || $attribute->name !== 'href') {
                    $node->removeAttribute($attribute->name);
                }
            }
            if ($node->tagName === 'a' && $node->hasAttribute('href') && ! preg_match('/^(https?:|mailto:)/i', $node->getAttribute('href'))) {
                $node->removeAttribute('href');
            }
        }
        $root = $document->getElementById('outreach-root');
        if ($root === null) {
            return '';
        }

        return collect(iterator_to_array($root->childNodes))->map(fn ($node): string => $document->saveHTML($node) ?: '')->implode('');
    }
}
