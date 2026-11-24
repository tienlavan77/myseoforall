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

        self::assertSame('unknown', $status['installation']);
        self::assertSame('inactive', $status['activation']);
        self::assertSame('unavailable', $status['version_state']);
        self::assertSame('unknown', $status['compatibility']);
        self::assertArrayNotHasKey('ownership', $status);

        $active = (new RankMathDetector())->status(true, '1.0.0');
        self::assertSame('unknown', $active['installation']);
        self::assertSame('active', $active['activation']);
        self::assertSame('1.0.0', $active['version']);
        self::assertSame('known', $active['version_state']);
        self::assertSame('unknown', $active['compatibility']);
        self::assertArrayNotHasKey('ownership', $active);
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
        $unknown = $registry->resolve(['active' => true, 'compatible' => true], ['rank_math']);

        self::assertSame('unknown', $unknown['outputs']['title_meta']['owner']);
        self::assertSame('unknown', $unknown['outputs']['title_meta']['state']);
        self::assertSame('no_valid_claim', $unknown['outputs']['title_meta']['reason']);
        self::assertSame('possible', $unknown['outputs']['title_meta']['duplicate_risk']);
        self::assertFalse($unknown['outputs']['title_meta']['write_allowed']);
        self::assertFalse($unknown['outputs']['ai_draft']['frontend_owned']);
        self::assertFalse($unknown['outputs']['ai_draft']['publish_capability']);
        self::assertFalse($unknown['write_allowed']);

        $conflict = $registry->resolve([
            'observations' => [
                [
                    'observation_id' => 'obs-rm-sitemap',
                    'producer' => 'rank_math',
                    'output' => 'sitemap',
                    'assertion' => 'claim',
                    'can_emit' => true,
                    'source' => 'fixture:rm',
                    'evidence_state' => 'observed',
                ],
                [
                    'observation_id' => 'obs-other-sitemap',
                    'producer' => 'other_seo',
                    'output' => 'sitemap',
                    'assertion' => 'claim',
                    'can_emit' => true,
                    'source' => 'fixture:other',
                    'evidence_state' => 'observed',
                ],
            ],
        ]);
        self::assertSame('conflict', $conflict['outputs']['sitemap']['state']);
        self::assertSame('blocked', $conflict['outputs']['sitemap']['duplicate_risk']);
        self::assertSame('overlapping_claims', $conflict['outputs']['sitemap']['reason']);
        self::assertSame('unknown', $conflict['outputs']['title_meta']['state']);
        self::assertFalse($conflict['outputs']['sitemap']['write_allowed']);
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

    /** Verify URL, heading and link findings use bounded deterministic evidence. */
    public function testAnalyzerValidatesUrlsHeadingsAndLinks(): void
    {
        $context = SeoContext::fromArray([
            'title' => str_repeat('T', 30),
            'meta_description' => str_repeat('M', 70),
            'content' => '<h1>Main</h1><h2>Section</h2><h4>Skipped</h4><!-- <h1>fake</h1> -->',
            'url' => 'https://example.test/item',
            'canonical' => 'not-a-url',
            'internal_links' => [
                ['url' => 'https://other.test/page', 'anchor' => ''],
            ],
        ], 'post');

        $findings = (new LocalSeoAnalyzer())->analyze($context);
        $codes = array_column($findings, 'code');

        self::assertContains('canonical_invalid', $codes);
        self::assertContains('heading_hierarchy', $codes);
        self::assertContains('internal_link_anchor_missing', $codes);
        self::assertContains('internal_links_external_only', $codes);

        foreach ($findings as $finding) {
            self::assertSame(['code', 'severity', 'message', 'field', 'evidence', 'suggested_fix'], array_keys($finding));
            self::assertIsNotString($finding['evidence']);
        }
    }

    /** Verify offer and variation checks distinguish missing, invalid and conflicting data. */
    /** Verify every analyzer finding code is covered by the deterministic registry. */
    public function testAnalyzerFindingCodesAreRegistered(): void
    {
        $context = SeoContext::fromArray([
            'content' => '<h1>Body</h1>',
            'meta_description' => ['invalid'],
        ], 'post');
        $findings = (new LocalSeoAnalyzer())->analyze($context);
        $reflection = new \ReflectionClass(LocalSeoAnalyzer::class);
        $registered = array_keys($reflection->getConstant('CODE_ORDER'));
        foreach ($findings as $finding) {
            self::assertContains($finding['code'], $registered, $finding['code']);
        }
        self::assertContains('meta_description_invalid', $registered);
    }

    public function testAnalyzerChecksOfferAndVariationSemantics(): void
    {
        $context = SeoContext::fromArray([
            'title' => str_repeat('T', 30),
            'content' => '<h1>Product</h1>',
            'id' => 5,
            'sku' => 'SKU',
            'price' => '10.00',
            'stock' => 0,
            'offer' => ['price' => '12.00', 'currency' => 'USD', 'availability' => 'in_stock'],
            'variation' => ['id' => 4, 'parent_id' => 4],
        ], 'variation');

        $codes = array_column((new LocalSeoAnalyzer())->analyze($context), 'code');

        self::assertContains('meta_description_missing', $codes);
        self::assertContains('offer_mismatch', $codes);
        self::assertContains('variation_relationship_invalid', $codes);
        self::assertContains('variation_id_conflict', $codes);
        self::assertNotContains('product_fields_missing', $codes);

        $matching = SeoContext::fromArray([
            'id' => 4,
            'content' => '<h1>Product</h1>',
            'sku' => 'SKU',
            'price' => '10.00',
            'stock' => 0,
            'variation' => ['id' => 4, 'parent_id' => 9],
        ], 'variation');
        $matchingCodes = array_column((new LocalSeoAnalyzer())->analyze($matching), 'code');
        self::assertNotContains('variation_id_conflict', $matchingCodes);
    }

    /** Verify Unicode title and meta lengths are measured as characters at inclusive boundaries. */
    /** Verify product commerce fields distinguish missing values from invalid types and formats. */
    public function testAnalyzerValidatesProductCommerceFieldTypes(): void
    {
        $context = SeoContext::fromArray([
            'title' => str_repeat('T', 30),
            'content' => '<h1>Product</h1>',
            'sku' => 123,
            'price' => '10.0x',
            'stock' => '4',
        ], 'product');

        $codes = array_column((new LocalSeoAnalyzer())->analyze($context), 'code');

        self::assertNotContains('product_fields_missing', $codes);
        self::assertContains('product_fields_invalid', $codes);
    }

    public function testAnalyzerUsesUnicodeLengthBoundaries(): void
    {
        $title = str_repeat('ế', 30);
        $meta = str_repeat('đ', 70);
        $context = SeoContext::fromArray([
            'title' => $title,
            'meta_description' => $meta,
            'content' => '<h1>Unicode</h1>',
        ], 'post');

        $codes = array_column((new LocalSeoAnalyzer())->analyze($context), 'code');

        self::assertNotContains('title_length', $codes);
        self::assertNotContains('meta_description_length', $codes);
    }

    /** Verify missing, null and invalid scalar types remain distinct analyzer outcomes. */
    public function testAnalyzerInputContractDistinguishesNullAndInvalidTypes(): void
    {
        $missing = array_column((new LocalSeoAnalyzer())->analyze(SeoContext::fromArray([
            'content' => '<h1>Body</h1>',
        ], 'post')), 'code');
        self::assertContains('title_missing', $missing);

        $invalid = array_column((new LocalSeoAnalyzer())->analyze(SeoContext::fromArray([
            'title' => ['bad'],
            'meta_description' => ['bad'],
            'content' => ['bad'],
        ], 'post')), 'code');
        self::assertContains('title_invalid', $invalid);
        self::assertContains('meta_description_invalid', $invalid);
        self::assertContains('content_invalid', $invalid);
        self::assertNotContains('title_missing', $invalid);
    }

    /** Verify decimal strings compare canonically while invalid prices remain invalid. */
    public function testAnalyzerUsesDecimalOfferPolicy(): void
    {
        $base = [
            'title' => str_repeat('T', 30),
            'content' => '<h1>Product</h1>',
            'sku' => 'SKU',
            'price' => '10.00',
            'stock' => 0,
            'offer' => ['price' => '10', 'currency' => 'USD', 'availability' => 'in_stock'],
        ];
        $matching = array_column((new LocalSeoAnalyzer())->analyze(SeoContext::fromArray($base, 'product')), 'code');
        self::assertNotContains('offer_mismatch', $matching);

        $invalid = $base;
        $invalid['offer']['price'] = '-1.00';
        $invalidCodes = array_column((new LocalSeoAnalyzer())->analyze(SeoContext::fromArray($invalid, 'product')), 'code');
        self::assertContains('offer_invalid', $invalidCodes);
    }
}
