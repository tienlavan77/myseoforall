<?php
/**
 * Provider payload boundary accepts plain application data, keeps the shared allowlist, and rejects unsafe nesting, oversized text, and excessive list depth before providers receive data.
 */

declare(strict_types=1);

namespace MySeoForAll\SEO\Context;

// Defines ProviderPayloadBuilder class behavior for ProviderPayloadBuilder.php.
final class ProviderPayloadBuilder
{
    public const SCHEMA_VERSION = 'provider-payload-2';
    private const SOURCES = ['wordpress', 'woocommerce', 'rank_math', 'myseo_for_all', 'editor'];

    /**
     * @param array<string, mixed> $source
     * @param array<string, string> $provenance Field-to-source labels.
     * @return array<string, mixed>
     */
    public function build(array $source, string $type = 'article', array $provenance = []): array
    {
        $rules = new ProviderPayloadRules();
        $schema = ProviderPayloadSchema::forType($type);
        $rules->inspect($source);
        $rules->inspect($provenance);
        $rules->checkBytes($source);
        $data = $rules->copy($source, $schema);
        $this->validateRelationship($data, $type);
        $labels = $this->normalizeProvenance($provenance, $data, $type);
        foreach (['seo.canonical', 'canonical', 'url'] as $candidate) {
            $value = $candidate === 'seo.canonical' ? ($data['seo']['canonical'] ?? '') : ($data[$candidate] ?? '');
            if ($value !== '') {
                $data['canonical'] = $value;
                $labels['canonical'] = $labels[$candidate];
                break;
            }
        }
        ksort($data);
        $payload = ['schema_version' => self::SCHEMA_VERSION, 'type' => $type] + $data;
        ksort($labels);
        $payload['provenance'] = [];
        foreach ($labels as $field => $label) {
            $payload['provenance'][] = ['field' => $field, 'source' => $label];
        }
        $rules->checkBytes($payload);
        return $payload;
    }

    /**
     * Validate a built payload at the provider boundary by rebuilding the same closed contract.
     * Metadata cannot bypass field validation, limits, provenance, or canonical precedence.
     */
    public function validatePayload(array $payload): void
    {
        (new ProviderPayloadRules())->inspect($payload);
        if (($payload['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || !is_string($payload['type'] ?? null) || !is_array($payload['provenance'] ?? null)) {
            throw new \InvalidArgumentException('Provider requires a built typed payload.');
        }
        $rows = $payload['provenance'];
        if ($rows !== [] && array_keys($rows) !== range(0, count($rows) - 1)) {
            throw new \InvalidArgumentException('Provider provenance must be a list.');
        }
        $labels = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_keys($row) !== ['field', 'source']
                || !is_string($row['field']) || !is_string($row['source'])
                || array_key_exists($row['field'], $labels)) {
                throw new \InvalidArgumentException('Provider provenance is malformed.');
            }
            $labels[$row['field']] = $row['source'];
        }
        $source = $payload;
        unset($source['schema_version'], $source['type'], $source['provenance']);
        if ($this->build($source, $payload['type'], $labels) !== $payload) {
            throw new \InvalidArgumentException('Provider payload is not in canonical built form.');
        }
    }

    /** Require a variation to identify a different parent and keep its own identifiers consistent. */
    private function validateRelationship(array $data, string $type): void
    {
        if ($type !== 'variation') {
            return;
        }
        $variation = $data['variation'];
        if ($variation['id'] === $variation['parent_id']
            || (isset($data['id']) && $data['id'] !== $variation['id'])
            || (isset($data['sku'], $variation['sku']) && $data['sku'] !== $variation['sku'])) {
            throw new \InvalidArgumentException('Provider variation relationship is inconsistent.');
        }
    }

    /**
     * Bind provenance only to fields present in the validated source with approved source labels.
     * Default labels describe editorial or commerce data; SEO overrides retain their own source.
     */
    private function normalizeProvenance(array $provenance, array $data, string $type): array
    {
        $labels = [];
        $default = in_array($type, ['product', 'variation'], true) ? 'woocommerce' : 'wordpress';
        foreach ($data as $field => $value) {
            $labels[$field] = $default;
            if ($field === 'seo') {
                foreach ($value as $nested => $_value) {
                    $labels['seo.' . $nested] = $default;
                }
            }
        }
        foreach ($provenance as $field => $label) {
            if (!is_string($field) || !array_key_exists($field, $labels)
                || !is_string($label) || !in_array($label, self::SOURCES, true)) {
                throw new \InvalidArgumentException('Provider provenance references a forbidden field or source.');
            }
            $labels[$field] = $label;
        }
        return $labels;
    }
}
