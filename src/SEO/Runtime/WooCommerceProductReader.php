<?php
/*
 * Reads one WooCommerce product or variation through guarded CRUD access and
 * projects a stable snapshot into SeoContext, LocalSeoAnalyzer, and the typed
 * provider payload boundary. It preserves decimal strings, never invents stock,
 * confirms variation parents, and returns closed status envelopes when APIs fail.
 */

declare(strict_types=1);

namespace MySeoForAll\SEO\Runtime;

use MySeoForAll\SEO\Analysis\LocalSeoAnalyzer;
use MySeoForAll\SEO\Context\ProviderPayloadBuilder;
use MySeoForAll\SEO\Context\SeoContext;

final class WooCommerceProductReader
{
    private $loader;
    private $parentLoader;
    private $currencyLoader;
    private $seoOverrideResolver;
    private array $seoProvenance = [];
    private LocalSeoAnalyzer $analyzer;
    private ProviderPayloadBuilder $builder;

    /**
     * Configure an optional test loader and pure projection services.
     * The loader may return an array or product-like object; production uses wc_get_product when available.
     * @param callable|mixed|null $loader
     * @return void
     */
    public function __construct(
        $loader = null,
        ?LocalSeoAnalyzer $analyzer = null,
        ?ProviderPayloadBuilder $builder = null,
        $parentLoader = null,
        $currencyLoader = null,
        $seoOverrideResolver = null
    ) {
        $this->loader = $loader;
        $this->analyzer = $analyzer ?? new LocalSeoAnalyzer();
        $this->builder = $builder ?? new ProviderPayloadBuilder();
        $this->parentLoader = $parentLoader;
        $this->currencyLoader = $currencyLoader;
        $this->seoOverrideResolver = $seoOverrideResolver;
    }

    /**
     * Read one simple product or variation and return both projections.
     * Parent absence and malformed commerce fields fail closed without fabricating values.
     * @param int $productId
     * @return array<string,mixed>
     */
    public function readProduct(int $productId): array
    {
        if ($productId <= 0) {
            return $this->envelope('invalid_source', 'invalid_product_id');
        }
        if (!is_callable($this->loader) && !function_exists('wc_get_product')) {
            return $this->envelope('unavailable', 'woocommerce_unavailable');
        }

        $raw = is_callable($this->loader) ? ($this->loader)($productId) : wc_get_product($productId);
        if ($raw === null || $raw === false) {
            return $this->envelope('not_found', 'product_not_found');
        }

        $source = $this->normalize($raw);
        $type = (string) ($source['resource_type'] ?? $source['type'] ?? '');
        if (!in_array($type, ['product', 'variation'], true)) {
            return $this->envelope('unsupported_type', 'unsupported_product_type');
        }
        $sourceId = $source['id'] ?? null;
        if (!$this->isExactPositiveId($sourceId)) {
            return $this->envelope('invalid_source', 'invalid_product_id', $type);
        }
        $sourceId = (int) $sourceId;
        if ($sourceId !== $productId) {
            return $this->envelope('invalid_source', 'invalid_product_source', $type);
        }

        if ($type === 'variation') {
            $parentId = (int) ($source['variation']['parent_id'] ?? $source['parent_id'] ?? 0);
            if ($parentId <= 0 || $parentId === $sourceId || !$this->parentExists($parentId, $source)) {
                return $this->envelope('invalid_source', 'parent_product_missing', 'variation');
            }
        }

        $this->seoProvenance = [];
        $source = $this->applySeoOverride($source, $productId);
        $contextSource = $this->contextSource($source, $type);
        $context = SeoContext::fromArray($contextSource, $type);
        $findings = $this->analyzer->analyze($context);
        $payload = null;
        $payloadStatus = 'accepted';
        $payloadReason = null;

        try {
            $payloadSource = $this->payloadSource($source, $type);
            $payload = $this->builder->build($payloadSource, $type, array_merge(['product_name' => 'woocommerce'], $this->seoProvenance));
        } catch (\InvalidArgumentException $exception) {
            $payloadStatus = 'rejected';
            $payloadReason = $this->safeReason($exception);
        }

        return $this->envelope('ok', null, $type, $context, $findings, $payload, $payloadStatus, $payloadReason);
    }

    /**
     * Convert a product-like value into a scalar-only source snapshot.
     * @param mixed $raw
     * @return array<string,mixed>
     */
    /**
     * Accept only a positive integer identity without coercing dirty strings or floats.
     * @param mixed $value
     * @return bool
     */
    private function isExactPositiveId($value): bool
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value)))
            && (int) $value > 0
            && (string) (int) $value === (string) $value;
    }

    private function normalize($raw): array
    {
        if (is_array($raw)) {
            $source = $raw;
            if (!isset($source['resource_type']) && isset($source['type'])) {
                $source['resource_type'] = $source['type'];
            }
            if (($source['resource_type'] ?? null) === 'variation') {
                $source['variation'] = $this->dropNulls([
                    'id' => $source['id'] ?? null,
                    'parent_id' => $source['parent_id'] ?? null,
                    'sku' => is_string($source['sku'] ?? null) && $source['sku'] !== '' ? $source['sku'] : null,
                    'attributes' => $this->attributes($source['attributes'] ?? null),
                ]);
            }
            return $this->dropNulls($source);
        }

        if (!is_object($raw)) {
            return [];
        }

        $read = static function (object $object, string $method, ...$args): mixed {
            if (!method_exists($object, $method)) {
                return null;
            }
            try {
                return $object->{$method}(...$args);
            } catch (\Throwable) {
                return null;
            }
        };

        $rawId = $read($raw, 'get_id');
        $rawType = $read($raw, 'get_type');
        $type = is_string($rawType) ? trim($rawType) : '';
        $type = $type === 'simple' ? 'product' : $type;
        if (!$this->isExactPositiveId($rawId)) {
            return ['resource_type' => $type, 'id' => $rawId];
        }
        $id = (int) $rawId;
        $parentId = (int) ($read($raw, 'get_parent_id') ?? 0);
        $manageStock = $read($raw, 'get_manage_stock');
        $stockQuantity = $read($raw, 'get_stock_quantity', 'edit');
        $stockStatus = (string) ($read($raw, 'get_stock_status', 'edit') ?? '');
        $price = $read($raw, 'get_price', 'edit');
        $name = (string) ($read($raw, 'get_name') ?? '');
        $description = (string) ($read($raw, 'get_description') ?? '');
        $shortDescription = (string) ($read($raw, 'get_short_description') ?? '');
        $source = [
            'resource_type' => $type,
            'id' => $id,
            'product_name' => $name,
            'description' => $description,
            'short_description' => $shortDescription,
            'sku' => $read($raw, 'get_sku'),
            'price' => $price,
            'manage_stock' => $manageStock,
            'stock_quantity' => $stockQuantity,
            'stock_status' => $stockStatus,
            'currency' => $this->readCurrency($raw, $read),
            'url' => $read($raw, 'get_permalink'),
            'parent_id' => $parentId,
            'attributes' => $read($raw, 'get_attributes'),
        ];

        if ($type === 'variation') {
            $variation = [
                'id' => $id,
                'parent_id' => $parentId,
                'sku' => is_string($source['sku'] ?? null) && $source['sku'] !== '' ? $source['sku'] : null,
                'attributes' => $this->attributes($source['attributes'] ?? null),
            ];
            $source['variation'] = $this->dropNulls($variation);
        }

        return $this->dropNulls($source);
    }

    /**
     * Create the analyzer projection with the context naming contract.
     * @param array<string,mixed> $source
     * @param string $type
     * @return array<string,mixed>
     */
    private function contextSource(array $source, string $type): array
    {
        $context = [
            'id' => $source['id'] ?? null,
            'title' => $source['product_name'] ?? '',
            'content' => $source['description'] ?? ($source['short_description'] ?? ''),
            'excerpt' => $source['short_description'] ?? '',
            'sku' => $source['sku'] ?? null,
            'price' => $this->priceValue($source),
            'stock' => $this->normalizedStock($source),
            'currency' => $source['currency'] ?? null,
            'availability' => $this->availability($source),
            'offer' => $this->offer($source),
            'url' => $source['url'] ?? null,
            'seo' => $this->seoFields($source['seo'] ?? null),
            'variation' => $type === 'variation' ? ($source['variation'] ?? null) : null,
        ];

        return $this->dropNulls($context);
    }

    /**
     * Create the strict provider projection and remove platform-only keys.
     * @param array<string,mixed> $source
     * @param string $type
     * @return array<string,mixed>
     */
    private function payloadSource(array $source, string $type): array
    {
        $payload = [
            'id' => $source['id'] ?? null,
            'product_name' => $source['product_name'] ?? '',
            'description' => $source['description'] ?? ($source['short_description'] ?? ''),
            'short_description' => $source['short_description'] ?? null,
            'sku' => $source['sku'] ?? null,
            'price' => $this->priceValue($source),
            'stock' => $this->normalizedStock($source),
            'attributes' => $this->attributes($source['attributes'] ?? null),
            'offer' => $this->offer($source),
            'url' => $source['url'] ?? null,
            'seo' => $this->seoFields($source['seo'] ?? null),
        ];
        if ($type === 'variation') {
            $payload['variation'] = $source['variation'] ?? [
                'id' => (int) ($source['id'] ?? 0),
                'parent_id' => (int) ($source['parent_id'] ?? 0),
            ];
        }
        return $this->dropNulls($payload);
    }

    /**
     * Preserve stock only when WooCommerce explicitly manages a non-negative integer quantity.
     * @param array<string,mixed> $source
     * @return int|null
     */
    /**
     * Preserve valid decimal strings while treating an empty price as missing.
     * @param array<string,mixed> $source
     * @return mixed
     */
    private function priceValue(array $source)
    {
        $price = $source['price'] ?? null;
        return $price === '' ? null : $price;
    }

    private function normalizedStock(array $source): ?int
    {
        if (($source['manage_stock'] ?? null) !== true) {
            return null;
        }
        $value = $source['stock_quantity'] ?? null;
        return is_int($value) && $value >= 0 ? $value : null;
    }

    /**
     * Map a known WooCommerce stock state into the closed availability vocabulary.
     * @param array<string,mixed> $source
     * @return string|null
     */
    private function availability(array $source): ?string
    {
        return [
            'instock' => 'in_stock',
            'outofstock' => 'out_of_stock',
            'onbackorder' => 'on_backorder',
        ][(string) ($source['stock_status'] ?? '')] ?? null;
    }

    /**
     * Build an offer only when decimal price, ISO currency, and availability are all valid.
     * @param array<string,mixed> $source
     * @return array<string,string>|null
     */
    private function offer(array $source): ?array
    {
        $price = $source['price'] ?? null;
        $currency = strtoupper((string) ($source['currency'] ?? ''));
        $availability = $this->availability($source);
        if (!is_string($price) || !preg_match('/^\d+(?:\.\d+)?$/', $price)
            || !preg_match('/^[A-Z]{3}$/', $currency) || $availability === null) {
            return null;
        }
        return ['price' => $price, 'currency' => $currency, 'availability' => $availability];
    }

    /**
     * Normalize WooCommerce attributes into the provider list schema.
     * @param mixed $value
     * @return list<array{name:string,value:string}>
     */
    private function attributes($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $name => $item) {
            if (is_array($item) && isset($item['name'], $item['value'])) {
                $result[] = ['name' => (string) $item['name'], 'value' => (string) $item['value']];
            } elseif (is_object($item) && method_exists($item, 'get_name') && method_exists($item, 'get_options')) {
                $itemName = (string) $item->get_name();
                $options = $item->get_options();
                foreach (is_array($options) ? $options : [] as $option) {
                    if (is_scalar($option)) {
                        $result[] = ['name' => $itemName, 'value' => (string) $option];
                    }
                }
            } elseif (is_scalar($item)) {
                $result[] = ['name' => (string) $name, 'value' => (string) $item];
            }
        }
        return $result;
    }

    /**
     * Read currency through the injected platform port, then the guarded platform function.
     * @param object $raw
     * @param callable $read
     * @return mixed
     */
    private function readCurrency(object $raw, callable $read)
    {
        if (is_callable($this->currencyLoader)) {
            return ($this->currencyLoader)();
        }
        return function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : null;
    }

    /**
     * Confirm a variation parent through an injected platform port or an explicit fixture flag.
     * @param int $parentId
     * @param array<string,mixed> $source
     * @return bool
     */
    private function parentExists(int $parentId, array $source): bool
    {
        if ($parentId <= 0) {
            return false;
        }
        $parent = is_callable($this->parentLoader)
            ? ($this->parentLoader)($parentId)
            : (is_callable($this->loader)
                ? ($this->loader)($parentId)
                : (function_exists('wc_get_product') ? wc_get_product($parentId) : null));
        if ($parent === null || $parent === false) {
            return false;
        }
        if (is_array($parent)) {
            $parentSource = $parent;
            $parentType = (string) ($parentSource['resource_type'] ?? $parentSource['type'] ?? '');
            $parentSourceId = $parentSource['id'] ?? null;
        } elseif (is_object($parent)) {
            $parentType = method_exists($parent, 'get_type') ? (string) $parent->get_type() : '';
            $parentSourceId = method_exists($parent, 'get_id') ? $parent->get_id() : null;
        } else {
            return false;
        }
        if ($parentType === 'simple') {
            $parentType = 'product';
        }
        return in_array($parentType, ['product', 'variable'], true)
            && (is_int($parentSourceId) || (is_string($parentSourceId) && ctype_digit($parentSourceId)))
            && (int) $parentSourceId === $parentId;
    }

    /** Apply only concrete, allowlisted Rank Math values returned by an injected resolver. */
    private function applySeoOverride(array $source, int $productId): array
    {
        if (!is_callable($this->seoOverrideResolver)) {
            return $source;
        }
        $resolved = ($this->seoOverrideResolver)($productId);
        if (!is_array($resolved)) {
            return $source;
        }
        foreach (['title', 'meta_description', 'canonical'] as $field) {
            $record = $resolved[$field] ?? null;
            if (is_array($record) && ($record['state'] ?? null) === 'value'
                && isset($record['value']) && is_string($record['value']) && $record['value'] !== '') {
                $source['seo'][$field] = $record['value'];
                $this->seoProvenance['seo.' . $field] = in_array($record['source'] ?? null, ['myseo_for_all', 'rank_math'], true) ? $record['source'] : 'rank_math';
            }
        }
        return $this->dropNulls($source);
    }

    /** Filter nested SEO values to the three public fields accepted by the provider schema. */
    private function seoFields($value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $result = [];
        foreach (['title', 'meta_description', 'canonical'] as $field) {
            if (isset($value[$field]) && is_string($value[$field]) && $value[$field] !== '') {
                $result[$field] = $value[$field];
            }
        }
        return $result === [] ? null : $result;
    }

    /**
     * Remove null values so optional provider fields are never sent as null.
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private function dropNulls(array $source): array
    {
        foreach ($source as $key => $value) {
            if ($value === null) {
                unset($source[$key]);
            } elseif (is_array($value)) {
                $source[$key] = $this->dropNulls($value);
            }
        }
        return $source;
    }

    /**
     * Return a closed envelope without raw commerce values or exception messages.
     * @param string $status
     * @param string|null $reason
     * @param string|null $resourceType
     * @param SeoContext|null $context
     * @param list<array<string,mixed>> $findings
     * @param array<string,mixed>|null $payload
     * @param string $payloadStatus
     * @param string|null $payloadReason
     * @return array<string,mixed>
     */
    private function envelope(
        string $status,
        ?string $reason,
        ?string $resourceType = null,
        ?SeoContext $context = null,
        array $findings = [],
        ?array $payload = null,
        string $payloadStatus = 'not_attempted',
        ?string $payloadReason = null
    ): array {
        return [
            'status' => $status,
            'reason' => $reason,
            'resource_type' => $resourceType,
            'seo_context' => $context,
            'findings' => $findings,
            'provider_payload' => $payload,
            'payload_status' => $payloadStatus,
            'payload_reason' => $payloadReason,
        ];
    }

    /**
     * Convert provider exceptions into stable non-sensitive categories.
     * @param \InvalidArgumentException $exception
     * @return string
     */
    private function safeReason(\InvalidArgumentException $exception): string
    {
        $message = strtolower($exception->getMessage());
        return str_contains($message, 'private') || str_contains($message, 'secret') ? 'privacy_policy' : 'payload_contract';
    }
}
