<?php
/*
 * Reads one WordPress post or page through guarded platform calls and projects
 * the snapshot into the local analyzer context and the provider payload boundary.
 * Missing APIs and malformed source objects become closed result envelopes instead
 * of fatal errors, while the source object is never modified or retained.
 */

declare(strict_types=1);

namespace MySeoForAll\SEO\Runtime;

use MySeoForAll\SEO\Analysis\LocalSeoAnalyzer;
use MySeoForAll\SEO\Context\ProviderPayloadBuilder;
use MySeoForAll\SEO\Context\SeoContext;

final class WordPressContentReader
{
    private $loader;
    private $permalinkLoader;
    private $seoOverrideResolver;
    private array $seoProvenance = [];
    private LocalSeoAnalyzer $analyzer;
    private ProviderPayloadBuilder $builder;

    /**
     * Configure an optional test loader and the two pure projection services.
     * The loader may return an array or object; when omitted, guarded WordPress APIs supply the snapshot.
     * @param callable|mixed|null $loader
     * @return void
     */
    public function __construct(
        $loader = null,
        ?LocalSeoAnalyzer $analyzer = null,
        ?ProviderPayloadBuilder $builder = null,
        $permalinkLoader = null,
        $seoOverrideResolver = null
    ) {
        $this->loader = $loader;
        $this->permalinkLoader = $permalinkLoader;
        $this->seoOverrideResolver = $seoOverrideResolver;
        $this->analyzer = $analyzer ?? new LocalSeoAnalyzer();
        $this->builder = $builder ?? new ProviderPayloadBuilder();
    }

    /**
     * Read a post or page and return both analyzer and provider projections.
     * Unsupported types, absent APIs and invalid records are represented by stable status and reason values.
     * @param int $postId
     * @return array<string,mixed>
     */
    public function readPost(int $postId): array
    {
        if ($postId <= 0) {
            return $this->envelope('invalid_source', 'invalid_post_id');
        }
        if (!is_callable($this->loader) && !function_exists('get_post')) {
            return $this->envelope('unavailable', 'wordpress_unavailable');
        }

        $raw = is_callable($this->loader) ? ($this->loader)($postId) : $this->load($postId);
        if ($raw === null || $raw === false) {
            return $this->envelope('not_found', 'post_not_found');
        }

        $source = $this->normalize($raw);
        if ($source === [] || !isset($source['id']) || !$this->isExactPositiveId($source['id']) || (int) $source['id'] !== $postId) {
            return $this->envelope('invalid_source', 'invalid_post_source');
        }
        $postType = (string) ($source['post_type'] ?? '');
        if (!in_array($postType, ['post', 'page'], true)) {
            return $this->envelope('unsupported_type', 'unsupported_post_type');
        }

        if (!isset($source['url'])) {
            if (is_callable($this->permalinkLoader)) {
                $source['url'] = ($this->permalinkLoader)($postId);
            } elseif (function_exists('get_permalink')) {
                $source['url'] = get_permalink($postId);
            }
        }
        if (!isset($source['title']) && function_exists('get_the_title')) {
            $source['title'] = get_the_title($postId);
        }

        $resourceType = $postType === 'page' ? 'page' : 'article';
        $source = $this->filterSource($source);
        $this->seoProvenance = [];
        $source = $this->applySeoOverride($source, $postId);
        $context = SeoContext::fromArray($source, $postType);
        $findings = $this->analyzer->analyze($context);
        $payload = null;
        $payloadStatus = 'accepted';
        $payloadReason = null;

        try {
            $payload = $this->builder->build(
                $this->payloadSource($source),
                $resourceType,
                array_merge(['title' => 'wordpress', 'content' => 'wordpress'], $this->seoProvenance)
            );
        } catch (\InvalidArgumentException $exception) {
            $payloadStatus = 'rejected';
            $payloadReason = $this->safeReason($exception);
        }

        return $this->envelope('ok', null, $resourceType, $context, $findings, $payload, $payloadStatus, $payloadReason);
    }

    /**
     * Load a WordPress post only when the required platform function exists.
     * @param int $postId
     * @return mixed
     */
    private function load(int $postId)
    {
        return function_exists('get_post') ? get_post($postId) : null;
    }

    /**
     * Accept only a positive integer identity without coercing dirty strings or floats.
     * @param mixed $value
     * @return bool
     */
    private function isExactPositiveId($value): bool
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0 && (string) (int) $value === (string) $value;
    }

    /**
     * Copy only scalar fields needed by the closed content contract.
     * @param mixed $raw
     * @return array<string,mixed>
     */
    private function normalize($raw): array
    {
        if (is_array($raw)) {
            $source = $raw;
        } elseif (is_object($raw)) {
            // Preserve the raw identity so readPost can reject dirty values before helpers run.
            $source = [
                'id' => $raw->ID ?? null,
                'post_type' => isset($raw->post_type) && is_string($raw->post_type) ? $raw->post_type : '',
                'title' => isset($raw->post_title) && is_string($raw->post_title) ? $raw->post_title : '',
                'content' => isset($raw->post_content) && is_string($raw->post_content) ? $raw->post_content : '',
                'excerpt' => isset($raw->post_excerpt) && is_string($raw->post_excerpt) ? $raw->post_excerpt : '',
            ];
            if (isset($raw->post_name) && is_string($raw->post_name)) {
                $source['slug'] = $raw->post_name;
            }
        } else {
            return [];
        }

        return $this->dropNulls($source);
    }

    /**
     * Project only provider-approved WordPress fields into the AI boundary.
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private function payloadSource(array $source): array
    {
        return $this->filterSource($source);
    }

    /** Keep only the closed content contract and recursively remove null values. */
    private function filterSource(array $source): array
    {
        $allowed = [
            'id', 'title', 'content', 'excerpt', 'meta_description', 'url', 'canonical',
            'images', 'categories', 'tags', 'internal_links', 'seo',
        ];
        $result = [];
        foreach ($allowed as $field) {
            if (!array_key_exists($field, $source)) {
                continue;
            }
            $value = $source[$field];
            if ($field === 'seo' && is_array($value)) {
                $nested = [];
                foreach (['title', 'meta_description', 'canonical'] as $nestedField) {
                    if (array_key_exists($nestedField, $value)) {
                        $nested[$nestedField] = $value[$nestedField];
                    }
                }
                $value = $nested;
            }
            $result[$field] = $value;
        }
        return $this->dropNulls($result);
    }

    /** Apply only concrete, allowlisted Rank Math values returned by an injected resolver. */
    private function applySeoOverride(array $source, int $postId): array
    {
        if (!is_callable($this->seoOverrideResolver)) {
            return $source;
        }
        $resolved = ($this->seoOverrideResolver)($postId);
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

    /** Recursively remove null values from a closed projection without changing caller-owned input. */
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
     * Return a closed envelope without exposing source data or exception messages.
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
     * Convert a provider exception into a stable non-sensitive category.
     * @param \InvalidArgumentException $exception
     * @return string
     */
    private function safeReason(\InvalidArgumentException $exception): string
    {
        $message = strtolower($exception->getMessage());
        return str_contains($message, 'private') || str_contains($message, 'secret') ? 'privacy_policy' : 'payload_contract';
    }
}
