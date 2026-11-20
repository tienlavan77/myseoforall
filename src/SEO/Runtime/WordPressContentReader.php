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
        ?ProviderPayloadBuilder $builder = null
    ) {
        $this->loader = $loader;
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
        if ($source === [] || (isset($source['id']) && (int) $source['id'] !== $postId)) {
            return $this->envelope('invalid_source', 'invalid_post_source');
        }
        $postType = (string) ($source['post_type'] ?? '');
        if (!in_array($postType, ['post', 'page'], true)) {
            return $this->envelope('unsupported_type', 'unsupported_post_type');
        }

        $resourceType = $postType === 'page' ? 'page' : 'article';
        $context = SeoContext::fromArray($source, $postType);
        $findings = $this->analyzer->analyze($context);
        $payload = null;
        $payloadStatus = 'accepted';
        $payloadReason = null;

        try {
            $payload = $this->builder->build(
                $this->payloadSource($source),
                $resourceType,
                ['title' => 'wordpress', 'content' => 'wordpress']
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
     * Copy only scalar fields needed by the closed content contract.
     * @param mixed $raw
     * @return array<string,mixed>
     */
    private function normalize($raw): array
    {
        if (is_array($raw)) {
            $source = $raw;
        } elseif (is_object($raw)) {
            $source = [
                'id' => isset($raw->ID) ? (int) $raw->ID : null,
                'post_type' => isset($raw->post_type) ? (string) $raw->post_type : '',
                'title' => isset($raw->post_title) ? (string) $raw->post_title : '',
                'content' => isset($raw->post_content) ? (string) $raw->post_content : '',
                'excerpt' => isset($raw->post_excerpt) ? (string) $raw->post_excerpt : '',
            ];
            // Slug is intentionally omitted because it is outside the provider payload schema.
        } else {
            return [];
        }

        if (!isset($source['url']) && isset($source['id']) && function_exists('get_permalink')) {
            $source['url'] = get_permalink((int) $source['id']);
        }
        if (!isset($source['title']) && isset($source['id']) && function_exists('get_the_title')) {
            $source['title'] = get_the_title((int) $source['id']);
        }

        foreach ($source as $key => $value) {
            if ($value === null) {
                unset($source[$key]);
            }
        }

        return $source;
    }

    /**
     * Project only provider-approved WordPress fields into the AI boundary.
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private function payloadSource(array $source): array
    {
        $allowed = [
            'id', 'title', 'content', 'excerpt', 'meta_description', 'url', 'canonical',
            'images', 'categories', 'tags', 'internal_links', 'seo', 'language', 'headings',
        ];
        $payload = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $source)) {
                $payload[$field] = $source[$field];
            }
        }
        return $payload;
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
