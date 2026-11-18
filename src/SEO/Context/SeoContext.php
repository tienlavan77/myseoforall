<?php

/*
 * This value object defines the versioned SEO context shared by local analysis,
 * integrations, and future AI adapters. It copies only approved scalar and
 * nested values from a caller-owned array, so platform objects and secret-bearing
 * fields cannot pass through the context boundary accidentally.
 */

declare(strict_types=1);

namespace MySeoForAll\SEO\Context;

// Defines SeoContext class behavior for SeoContext.php.
final class SeoContext
{
    public const VERSION = 1;

    /** Builds a normalized context by copying only fields allowed by the SEO boundary. */
    public static function fromArray(array $source, string $type = 'unknown'): self
    {
        $allowed = [
            'id', 'title', 'content', 'excerpt', 'meta_description', 'url',
            'canonical', 'images', 'categories', 'tags', 'attributes', 'sku',
            'price', 'stock', 'offer', 'variation', 'internal_links', 'seo',
        ];

        $values = [
            'version' => self::VERSION,
            'type' => self::normalizeScalar($type),
        ];

        foreach ($allowed as $key) {
            if (array_key_exists($key, $source)) {
                $values[$key] = self::copyAllowedValue($source[$key]);
            }
        }

        return new self($values);
    }

    /** Stores filtered context values behind the value object boundary. */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    /** Returns the normalized context for analyzers and provider payload builders. */
    public function toArray(): array
    {
        return $this->values;
    }

    /** Reads one normalized field without exposing the internal array by reference. */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    /** Converts a context type into a stable scalar label without WordPress helpers. */
    private static function normalizeScalar(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** Recursively copies scalar data while preserving safe array keys and values. */
    private static function copyAllowedValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return is_scalar($value) || $value === null ? $value : (string) $value;
        }

        $copy = [];
        foreach ($value as $key => $item) {
            if (is_int($key) || is_string($key)) {
                $copy[$key] = self::copyAllowedValue($item);
            }
        }

        return $copy;
    }

    /** @var array<string, mixed> */
    private array $values;
}
