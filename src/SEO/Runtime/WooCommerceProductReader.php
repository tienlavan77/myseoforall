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
        ?ProviderPayloadBuilder $builder = null
    ) {
        $this->loader = $loader;
        $this->analyzer = $analyzer ?? new LocalSeoAnalyzer();
        $this->builder = $builder ?? new ProviderPayloadBuilder();
    }

    /**
     * Read one simple product or variation and return both projections.
     * Parent absence and malformed commerce fields fail closed without fabricating values.
     * @param int $productId
     * @return array<string,mixed>
     */
    public function readProduct(int $productId): array
    {
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
        $sourceId = (int) ($source['id'] ?? 0);
        if ($sourceId <= 0) {
            return $this->envelope('invalid_source', 'invalid_product_id', $type);
        }

        if ($type === 'variation') {
            $parentId = (int) ($source['variation']['parent_id'] ?? $source['parent_id'] ?? 0);
            if ($parentId <= 0 || $parentId === $sourceId || !$this->parentExists($parentId, $source)) {
                return $this->envelope('invalid_source', 'parent_product_missing', 'variation');
            }
        }

        $contextSource = $this->contextSource($source, $type);
        $context = SeoContext::fromArray($contextSource, $type);
        $findings = $this->analyzer->analyze($context);
        $payload = null;
        $payloadStatus = 'accepted';
        $payloadReason = null;

        try {
            $payloadSource = $this->payloadSource($source, $type);
            $payload = $this->builder->build($payloadSource, $type, ['product_name' => 'woocommerce']);
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
    private function normalize($raw): array
    {
        if (is_array($raw)) {
            $source = $raw;
            if (!isset($source['resource_type']) && isset($source['type'])) {
                $source['resource_type'] = $source['type'];
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

        $rawType = $read($raw, 'get_type');
        $type = is_string($rawType) ? trim($rawType) : '';
        $type = $type === 'simple' ? 'product' : $type;
        $id = (int) ($read($raw, 'get_id') ?? 0);
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
            'currency' => $read($raw, 'get_woocommerce_currency')
                ?? (function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : null),
            'url' => $read($raw, 'get_permalink'),
            'parent_id' => $parentId,
            'attributes' => $read($raw, 'get_attributes'),
        ];

        if ($type === 'variation') {
            $source['variation'] = [
                'id' => $id,
                'parent_id' => $parentId,
                'sku' => $source['sku'],
                'attributes' => $source['attributes'],
            ];
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
            } elseif (is_scalar($item)) {
                $result[] = ['name' => (string) $name, 'value' => (string) $item];
            }
        }
        return $result;
    }

    /**
     * Confirm a variation parent through the platform API or an explicit fixture flag.
     * @param int $parentId
     * @param array<string,mixed> $source
     * @return bool
     */
    private function parentExists(int $parentId, array $source): bool
    {
        if ($parentId <= 0) {
            return false;
        }
        if (array_key_exists('parent_exists', $source)) {
            return $source['parent_exists'] === true;
        }
        if (!function_exists('wc_get_product')) {
            return false;
        }
        $parent = wc_get_product($parentId);
        return $parent !== false && $parent !== null;
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
