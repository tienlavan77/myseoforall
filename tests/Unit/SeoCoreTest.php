<?php

/*
 * These tests protect the SEO context allowlist, deterministic local findings,
 * and Rank Math integration status boundary. They run without WordPress,
 * WooCommerce, network access, credentials, or persisted request data.
 */

declare(strict_types=1);

namespace MySeoForAll\Tests\Unit;

use MySeoForAll\Integrations\RankMath\RankMathDetector;
use MySeoForAll\SEO\Analysis\LocalSeoAnalyzer;
use MySeoForAll\SEO\Context\SeoContext;
use PHPUnit\Framework\TestCase;

// Defines SeoCoreTest class behavior for SeoCoreTest.php.
final class SeoCoreTest extends TestCase
{
    /**
     * Ensure context creation excludes secrets and arbitrary platform fields.
     */
    public function testContextCopiesAllowlistOnly(): void
    {
        $context = SeoContext::fromArray([
            'title' => 'A useful title',
            'content' => 'Content',
            'customer_email' => 'private@example.test',
            'api_key' => 'secret',
            'nested' => ['token' => 'secret'],
        ], 'post');

        $values = $context->toArray();

        self::assertSame(1, $values['version']);
        self::assertSame('post', $values['type']);
        self::assertSame('A useful title', $values['title']);
        self::assertArrayNotHasKey('customer_email', $values);
        self::assertArrayNotHasKey('api_key', $values);
        self::assertArrayNotHasKey('nested', $values);
    }

    /**
     * Ensure local analysis returns stable severity, code, field, and evidence.
     */
    public function testAnalyzerReturnsStableFindingsWithoutNetwork(): void
    {
        $context = SeoContext::fromArray([
            'content' => '<p>Body</p>',
            'url' => 'https://example.test/item',
            'images' => [['src' => 'image.jpg', 'alt' => '']],
            'internal_links' => [],
        ], 'post');

        $findings = (new LocalSeoAnalyzer())->analyze($context);
        $codes = array_column($findings, 'code');

        self::assertContains('title_missing', $codes);
        self::assertContains('meta_description_missing', $codes);
        self::assertContains('canonical_missing', $codes);
        self::assertContains('image_alt_missing', $codes);
        self::assertContains('internal_links_missing', $codes);

        foreach ($findings as $finding) {
            self::assertContains($finding['severity'], ['blocker', 'warning', 'info']);
            self::assertNotSame('', $finding['field']);
            self::assertArrayHasKey('evidence', $finding);
        }
    }

    /**
     * Ensure Rank Math detection remains safe when its plugin is unavailable.
     */
    public function testRankMathDetectorDoesNotRequireDependency(): void
    {
        $status = (new RankMathDetector())->status(false);

        self::assertFalse($status['installed']);
        self::assertFalse($status['active']);
        self::assertSame('unknown', $status['ownership']);

        $active = (new RankMathDetector())->status(true, '1.0.0');
        self::assertSame('rank_math', $active['ownership']);
        self::assertSame('1.0.0', $active['version']);
    }

    /** Verify the shared provider boundary rejects nested secrets and oversized values. */
    public function testProviderPayloadBuilderEnforcesNestedLimits(): void
    {
        $builder = new \MySeoForAll\SEO\Context\ProviderPayloadBuilder();
        $payload = $builder->build([
            'title' => 'Safe title',
            'content' => 'Body',
            'images' => [['src' => 'image.jpg', 'alt' => 'Safe image']],
        ], 'article');

        self::assertSame('provider-payload-2', $payload['schema_version']);
        self::assertSame('Safe title', $payload['title']);

        $this->expectException(\InvalidArgumentException::class);
        $builder->build(['images' => [['src' => 'x', 'token' => 'secret']]], 'article');
    }

    /** Verify findings expose remediation and are returned in deterministic severity order. */
    public function testAnalyzerFindingsIncludeFixAndSeverityOrder(): void
    {
        $findings = (new LocalSeoAnalyzer())->analyze(SeoContext::fromArray([
            'content' => '<p>Body</p>',
        ], 'post'));

        self::assertNotEmpty($findings);
        self::assertSame('blocker', $findings[0]['severity']);
        foreach ($findings as $finding) {
            self::assertArrayHasKey('suggested_fix', $finding);
            self::assertNotSame('', $finding['suggested_fix']);
        }
    }

    /** Verify provider payload text and list limits fail before data can leave the plugin. */
    public function testProviderPayloadBuilderRejectsOversizedValues(): void
    {
        $builder = new \MySeoForAll\SEO\Context\ProviderPayloadBuilder();

        $this->expectException(\InvalidArgumentException::class);
        $builder->build(['title' => str_repeat('x', 2001)], 'article');
    }

    /** Verify ownership remains unknown or conflicted and never enables public writes implicitly. */
    public function testRankMathOwnershipRegistryRequiresExplicitState(): void
    {
        $registry = new \MySeoForAll\Integrations\RankMath\OwnershipRegistry();
        $unknown = $registry->resolve(['active' => false, 'compatible' => false]);
        $conflict = $registry->resolve(['active' => true, 'compatible' => true], ['rank_math', 'other_seo']);

        self::assertSame('unknown', $unknown['state']);
        self::assertSame('unknown', $unknown['ownership']['title_meta']);
        self::assertSame('conflict', $conflict['state']);
        self::assertFalse($conflict['write_allowed']);
    }

    /** Verify each supported resource keeps a builder-owned type and deterministic provenance. */
    public function testProviderPayloadBuilderSupportsTypedResourceFixtures(): void
    {
        $builder = new \MySeoForAll\SEO\Context\ProviderPayloadBuilder();
        $fixtures = [
            'article' => ['title' => 'Article', 'content' => 'Body'],
            'page' => ['title' => 'Page', 'content' => 'Body'],
            'product' => ['product_name' => 'Widget', 'price' => '10.00'],
            'variation' => ['product_name' => 'Widget blue', 'variation' => ['id' => 2, 'parent_id' => 1, 'sku' => 'BLUE']],
        ];

        foreach ($fixtures as $type => $source) {
            $payload = $builder->build($source, $type);
            self::assertSame($type, $payload['type']);
            self::assertNotEmpty($payload['provenance']);
            $builder->validatePayload($payload);
        }
    }

    /** Verify builder-owned resource type overrides and sensitive provenance are rejected. */
    public function testProviderPayloadBuilderRejectsContractViolations(): void
    {
        $builder = new \MySeoForAll\SEO\Context\ProviderPayloadBuilder();

        $this->expectException(\InvalidArgumentException::class);
        $builder->build(['type' => 'product'], 'article');
    }
}
