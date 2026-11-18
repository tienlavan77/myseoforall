<?php
/**
 * Enforces offline provider input limits and privacy checks independently of a provider.
 * The validator rejects unknown fields, incorrect types, unsafe text patterns and excessive
 * nesting before copying only schema-approved values into a new payload.
 */
declare(strict_types=1);

namespace MySeoForAll\SEO\Context;

/** Validates closed resource schemas without casting values or retaining source data. */
final class ProviderPayloadRules
{
    public const MAX_DEPTH = 4;
    public const MAX_BYTES = 12000;
    public const MAX_TEXT_LENGTH = 2000;
    public const MAX_LIST_ITEMS = 50;

    /** Bound traversal before schema validation, including objects, cycles and invalid Unicode. */
    public function inspect(mixed $value, int $depth = 0): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException('Provider payload exceeds the nesting limit.');
        }
        if (is_array($value)) {
            if (count($value) > self::MAX_LIST_ITEMS) {
                throw new \InvalidArgumentException('Provider payload contains too many items.');
            }
            foreach ($value as $item) {
                $this->inspect($item, $depth + 1);
            }
        } elseif (is_string($value)) {
            if (strlen($value) > self::MAX_BYTES || preg_match('//u', $value) !== 1
                || preg_match_all('/./us', $value) > self::MAX_TEXT_LENGTH) {
                throw new \InvalidArgumentException('Provider payload contains invalid or oversized text.');
            }
            $this->rejectPrivateText($value);
        } elseif (!is_int($value)) {
            throw new \InvalidArgumentException('Provider payload contains an unsupported value type.');
        }
    }

    /** Check the serialized byte budget without storing or exposing the encoded data. */
    public function checkBytes(array $value): void
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || strlen($encoded) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Provider payload exceeds the configured size limit.');
        }
    }

    /** Copy an exact schema node, recursively validating every list item and object field. */
    public function copy(mixed $value, array $schema): mixed
    {
        $kind = $schema['kind'];
        if ($kind === 'object') {
            if (!is_array($value) || ($value !== [] && $this->isList($value))
                || array_diff(array_keys($value), array_keys($schema['fields'])) !== []
                || array_diff($schema['required'], array_keys($value)) !== []) {
                throw new \InvalidArgumentException('Provider object has missing or forbidden fields.');
            }
            $result = [];
            foreach ($schema['fields'] as $key => $field) {
                if (array_key_exists($key, $value)) {
                    $result[$key] = $this->copy($value[$key], $field);
                }
            }
            return $result;
        }
        if ($kind === 'list') {
            if (!is_array($value) || !$this->isList($value)) {
                throw new \InvalidArgumentException('Provider field must be a sequential list.');
            }
            return array_map(fn ($item) => $this->copy($item, $schema['item']), $value);
        }
        if (in_array($kind, ['positive_int', 'stock', 'heading_level'], true)) {
            if (!is_int($value) || ($kind === 'stock' ? $value < 0 : $value < 1)
                || ($kind === 'heading_level' && $value > 6)) {
                throw new \InvalidArgumentException('Provider integer field is outside its schema.');
            }
            return $value;
        }
        if (!is_string($value) || (($schema['nonempty'] ?? false) && trim($value) === '')) {
            throw new \InvalidArgumentException('Provider text field is outside its schema.');
        }
        $valid = match ($kind) {
            'text' => true,
            'url' => $value === '' || $this->isPublicUrl($value),
            'decimal' => preg_match('/^\d+(?:\.\d+)?$/D', $value) === 1,
            'currency' => preg_match('/^[A-Z]{3}$/D', $value) === 1,
            'language' => preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D', $value) === 1,
            'availability' => in_array($value, ['in_stock', 'out_of_stock', 'on_backorder'], true),
            default => false,
        };
        if (!$valid) {
            throw new \InvalidArgumentException('Provider scalar field is outside its schema.');
        }
        return $value;
    }

    /** Reject recognizable personal contacts and credentials inside every accepted text field. */
    private function rejectPrivateText(string $value): void
    {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        for ($i = 0; $i < 3; $i++) {
            $decoded = rawurldecode($decoded);
        }
        $patterns = [
            '~https?://[^\s/?#]*@~i',
            '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',
            '/(?:api[_ -]?key|access[_ -]?token|refresh[_ -]?token|password|secret|token|customer[_ -]?\w*|order[_ -]?(?:id|email|phone))\s*[=:]\s*\S+/i',
            '/\bBearer\s+\S+|\bsk-(?:ant-)?[A-Za-z0-9_-]{8,}|-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/i',
            '/(?:tel:|phone\s*[=:])\s*\+?[\d ()-]{7,}/i',
            '/\+\d[\d ()-]{8,}\d/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $decoded) === 1) {
                throw new \InvalidArgumentException('Provider payload contains forbidden private text.');
            }
        }
    }

    /** Accept absolute HTTP URLs with no embedded credentials; this never fetches a URL. */
    private function isPublicUrl(string $value): bool
    {
        $parts = parse_url($value);
        return filter_var($value, FILTER_VALIDATE_URL) !== false && is_array($parts)
            && in_array(strtolower($parts['scheme'] ?? ''), ['https', 'http'], true)
            && !isset($parts['user']) && !isset($parts['pass']);
    }

    /** Distinguish sequential lists from associative objects on supported PHP 8 versions. */
    private function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }
}
