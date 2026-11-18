<?php
/**
 * Provider payload boundary accepts plain application data, keeps the shared allowlist, and rejects unsafe nesting, oversized text, and excessive list depth before providers receive data.
 */

declare(strict_types=1);

namespace MySeoForAll\SEO\Context;

// Defines ProviderPayloadBuilder class behavior for ProviderPayloadBuilder.php.
final class ProviderPayloadBuilder
{
    public const SCHEMA_VERSION = 'provider-payload-1';
    private const MAX_DEPTH = 3;
    private const MAX_BYTES = 12000;
    private const MAX_STRING_LENGTH = 2000;
    private const MAX_LIST_ITEMS = 50;

    /** Normalize an allowlisted context and reject unknown fields, unsafe nesting, or oversized values. */
    /**
     * @param array<string, mixed> $source
     * @param array<string, string> $provenance Field-to-source labels.
     * @return array<string, mixed>
     */
    public function build(array $source, string $type = 'generic', array $provenance = []): array
    {
        $type = trim($type);
        if (!in_array($type, ['generic', 'article', 'page', 'product', 'variation'], true)) {
            throw new \InvalidArgumentException('Unsupported provider payload type.');
        }
        if (array_key_exists('type', $source)) {
            throw new \InvalidArgumentException('Provider payload resource type is controlled by the builder.');
        }

        $this->validateResourceShape($source, $type);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'type' => $type,
        ];

        foreach ($source as $key => $value) {
            if (!is_string($key) || !$this->isAllowedField($key)) {
                throw new \InvalidArgumentException('Provider payload contains a forbidden field.');
            }
            $payload[$key] = $this->normalizeField($key, $value, 0);
        }

        if ($provenance !== []) {
            $payload['provenance'] = $this->normalizeProvenance($provenance);
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || strlen($encoded) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Provider payload exceeds the configured size limit.');
        }

        return $payload;
    }

    /** Determine whether a top-level field belongs to the shared provider payload contract. */
    private function isAllowedField(string $field): bool
    {
        return in_array($field, [
            'id', 'title', 'content', 'excerpt', 'meta_description', 'url', 'canonical',
            'images', 'categories', 'tags', 'attributes', 'sku', 'price', 'stock',
            'offer', 'variation', 'internal_links', 'headings', 'seo', 'type',
            'language', 'product_name', 'short_description', 'description', 'source',
            'provenance',
        ], true);
    }

    /**
     * Validate resource-specific scalar and relationship semantics before normalization.
     *
     * @param array<string, mixed> $source
     */
    private function validateResourceShape(array $source, string $type): void
    {
        $stringFields = [
            'id', 'title', 'content', 'excerpt', 'meta_description', 'url', 'canonical',
            'sku', 'language', 'product_name', 'short_description', 'description',
        ];
        foreach ($stringFields as $field) {
            if (array_key_exists($field, $source) && !is_string($source[$field]) && !is_int($source[$field])) {
                throw new \InvalidArgumentException("Provider field {$field} must be text-like.");
            }
        }
        foreach (['images', 'categories', 'tags', 'attributes', 'internal_links', 'headings'] as $field) {
            if (array_key_exists($field, $source) && !is_array($source[$field])) {
                throw new \InvalidArgumentException("Provider field {$field} must be a list or object.");
            }
        }
        foreach (['price', 'stock'] as $field) {
            if (array_key_exists($field, $source) && !is_int($source[$field]) && !is_float($source[$field]) && !is_string($source[$field])) {
                throw new \InvalidArgumentException("Provider field {$field} has an invalid scalar type.");
            }
        }
        if ($type === 'variation' && array_key_exists('variation', $source) && !is_array($source['variation'])) {
            throw new \InvalidArgumentException('Variation relationship must be structured data.');
        }
        if (in_array($type, ['product', 'variation'], true)
            && array_key_exists('product_name', $source)
            && trim((string) $source['product_name']) === '') {
            throw new \InvalidArgumentException('Product resource name cannot be empty.');
        }
    }

    /**
     * Normalize field provenance deterministically and expose no arbitrary nested data.
     *
     * @param array<string, string> $provenance
     * @return list<array{field:string,source:string}>
     */
    private function normalizeProvenance(array $provenance): array
    {
        ksort($provenance);
        $result = [];
        foreach ($provenance as $field => $source) {
            if (!is_string($field) || !is_string($source) || trim($field) === '' || trim($source) === '') {
                throw new \InvalidArgumentException('Provider provenance must contain non-empty strings.');
            }
            if (in_array(strtolower($source), ['api_key', 'token', 'secret', 'customer', 'order'], true)) {
                throw new \InvalidArgumentException('Provider provenance contains a forbidden sensitive source.');
            }
            $result[] = ['field' => $field, 'source' => $source];
        }
        return $result;
    }

    /** Normalize one field while enforcing scalar types, depth, text length, and list limits. */
    private function normalizeField(string $field, mixed $value, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException('Provider payload exceeds the nesting limit.');
        }
        if (is_string($value)) {
            if (strlen($value) > self::MAX_STRING_LENGTH) {
                throw new \InvalidArgumentException('Provider payload contains an oversized text value.');
            }
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException('Provider payload contains an unsupported value type.');
        }

        if (count($value) > self::MAX_LIST_ITEMS) {
            throw new \InvalidArgumentException('Provider payload contains too many list items.');
        }
        if ($this->isList($value)) {
            return $this->normalizeList($field, $value, $depth + 1);
        }

        $allowed = $this->nestedFields($field);
        if ($allowed === []) {
            throw new \InvalidArgumentException('Provider payload contains an arbitrary nested object.');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException('Provider payload contains a forbidden nested field.');
            }
            $result[$key] = $this->normalizeField($key, $item, $depth + 1);
        }
        return $result;
    }

    /** Normalize structured lists and reject nested values outside each field-specific item schema. */
    private function normalizeList(string $field, array $value, int $depth): array
    {
        $result = [];
        foreach ($value as $item) {
            if (in_array($field, ['categories', 'tags'], true)) {
                if (!is_scalar($item) && $item !== null) {
                    throw new \InvalidArgumentException('Provider taxonomy values must be scalar.');
                }
                $result[] = $item;
                continue;
            }
            $result[] = $this->normalizeField($field, $item, $depth);
        }
        return $result;
    }

    /** Return the exact nested keys permitted for each structured provider payload field. */
    private function nestedFields(string $field): array
    {
        return match ($field) {
            'images' => ['src', 'alt'],
            'headings' => ['level', 'text'],
            'internal_links' => ['url', 'anchor'],
            'offer' => ['price', 'currency', 'availability'],
            'variation' => ['id', 'sku', 'attributes'],
            'seo' => ['title', 'meta_description', 'canonical'],
            'provenance' => ['source', 'field'],
            default => [],
        };
    }

    /** Identify sequential arrays so structured list items cannot masquerade as arbitrary objects. */
    private function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }
}
