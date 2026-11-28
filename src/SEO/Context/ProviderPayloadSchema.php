<?php
/**
 * Defines the public editorial and commerce data allowed into offline AI providers.
 * Required fields, exact scalar kinds, and closed nested schemas prevent private records
 * and arbitrary platform arrays from crossing the shared payload boundary.
 */
declare(strict_types=1);

namespace MySeoForAll\SEO\Context;

/** Supplies resource-specific schemas to the shared provider payload validator. */
final class ProviderPayloadSchema
{
    /** Return required fields and exact nested types for one supported public resource. */
    public static function forType(string $type): array
    {
        if (!in_array($type, ['article', 'page', 'product', 'variation'], true)) {
            throw new \InvalidArgumentException('Unsupported provider payload type.');
        }
        $text = ['kind' => 'text'];
        $name = ['kind' => 'text', 'nonempty' => true];
        $url = ['kind' => 'url'];
        $id = ['kind' => 'positive_int'];
        $attributes = self::items(self::object(['name' => $name, 'value' => $text], ['name', 'value']));
        $fields = [
            'id' => $id, 'title' => $text, 'content' => $text, 'excerpt' => $text,
            'meta_description' => $text, 'url' => $url, 'canonical' => $url,
            'language' => ['kind' => 'language'],
            'categories' => self::items($name), 'tags' => self::items($name),
            'images' => self::items(self::object(['src' => $name, 'alt' => $text], ['src', 'alt'])),
            'headings' => self::items(self::object(
                ['level' => ['kind' => 'heading_level'], 'text' => $text], ['level', 'text']
            )),
            'internal_links' => self::items(self::object(['url' => $url, 'anchor' => $text], ['url', 'anchor'])),
            'seo' => self::object(['title' => $text, 'meta_description' => $text, 'canonical' => $url]),
        ];
        if ($type === 'article' || $type === 'page') {
            $fields['title'] = $name;
            return self::object($fields, ['title', 'content']);
        }
        $fields += [
            'product_name' => $name, 'short_description' => $text, 'description' => $text,
            'sku' => $text, 'price' => ['kind' => 'decimal'], 'stock' => ['kind' => 'stock'],
            'attributes' => $attributes,
            'offer' => self::object([
                'price' => ['kind' => 'decimal'], 'currency' => ['kind' => 'currency'],
                'availability' => ['kind' => 'availability'],
            ], ['price', 'currency', 'availability']),
        ];
        if ($type === 'variation') {
            $fields['variation'] = self::object([
                'id' => $id, 'parent_id' => $id, 'sku' => $text, 'attributes' => $attributes,
            ], ['id', 'parent_id']);
        }
        return self::object($fields, $type === 'variation' ? ['product_name', 'variation'] : ['product_name']);
    }

    /** Describe a closed object so optional fields cannot introduce arbitrary data. */
    private static function object(array $fields, array $required = []): array
    {
        return ['kind' => 'object', 'fields' => $fields, 'required' => $required];
    }

    /** Describe a sequential list whose items all obey the specified schema. */
    private static function items(array $item): array
    {
        return ['kind' => 'list', 'item' => $item];
    }
}
