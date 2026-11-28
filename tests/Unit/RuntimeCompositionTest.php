<?php
/*
 * Verifies Increment E composition, read-only projections, passive Rank Math
 * probes, and duplicate hook registration behavior entirely offline.
 */

declare(strict_types=1);

namespace MySeoForAll\Tests\Unit;

use InvalidArgumentException;
use MySeoForAll\Core\Container;
use MySeoForAll\Core\HookDispatcher;
use MySeoForAll\Core\Plugin;
use MySeoForAll\Integrations\RankMath\RankMathCompatibilityMatrix;
use MySeoForAll\Integrations\RankMath\RankMathDetector;
use MySeoForAll\Integrations\RankMath\RankMathEnvironmentProbe;
use MySeoForAll\Integrations\RankMath\WordPressRankMathProbe;
use MySeoForAll\SEO\Context\ProviderPayloadBuilder;
use MySeoForAll\SEO\Runtime\ContentSeoInspector;
use MySeoForAll\SEO\Runtime\ProductSeoInspector;
use MySeoForAll\SEO\Runtime\WordPressContentReader;
use MySeoForAll\SEO\Runtime\WooCommerceProductReader;
use PHPUnit\Framework\TestCase;

final class RuntimeCompositionTest extends TestCase
{
    /**
     * Verify the four canonical modules and all Increment E service identifiers compose offline.
     * @return void
     */
    public function testCompositionRegistersCanonicalModulesAndServices(): void
    {
        HookDispatcher::resetShared();
        $container = new Container();
        $plugin = new Plugin($container, null, null, null, null, new HookDispatcher());
        $plugin->boot();
        $health = $plugin->health();

        self::assertSame(['admin', 'woocommerce', 'seo', 'rank_math'], array_column($health['modules'], 'name'));
        self::assertTrue($container->has(ProviderPayloadBuilder::class));
        self::assertTrue($container->has(ContentSeoInspector::class));
        self::assertTrue($container->has(ProductSeoInspector::class));
        self::assertTrue($container->has(RankMathCompatibilityMatrix::class));
        self::assertFalse($health['coexistence']['write_allowed']);
    }

    /**
     * Verify a second service instance cannot silently replace a composition identifier.
     * @return void
     */
    public function testContainerRejectsConflictingDuplicateRegistration(): void
    {
        $container = new Container();
        $container->set('first', new \stdClass());

        $this->expectException(\LogicException::class);
        $container->set('first', new \stdClass());
    }

    /**
     * Verify the dispatcher deduplicates the same hook/listener/priority tuple.
     * @return void
     */
    public function testHookDispatcherDeduplicatesNamedListeners(): void
    {
        $dispatcher = new HookDispatcher(static function (): void {});
        $listener = [new class {
            /** Handle a test hook without side effects. */
            public function handle(): void
            {
            }
        }, 'handle'];

        self::assertTrue($dispatcher->addAction('diagnostics', $listener));
        self::assertFalse($dispatcher->addAction('diagnostics', $listener));
        self::assertCount(1, $dispatcher->registrations());

        $offline = new HookDispatcher();
        self::assertFalse($offline->addAction('diagnostics', $listener));
        self::assertSame([], $offline->registrations());
        $this->expectException(\InvalidArgumentException::class);
        $dispatcher->addAction('diagnostics', static function (): void {});
    }

    /**
     * Verify a WordPress post snapshot reaches both local findings and a typed provider payload.
     * @return void
     */
    public function testWordPressReaderProjectsArticleWithoutPlatformRuntime(): void
    {
        $reader = new WordPressContentReader(static fn (int $id): array => [
            'id' => $id,
            'post_type' => 'post',
            'title' => 'A valid article title for local analysis',
            'content' => '<h1>Primary</h1><h2>Section</h2>',
            'url' => 'https://example.test/article',
        ]);

        $result = $reader->readPost(7);

        self::assertSame('ok', $result['status']);
        self::assertSame('article', $result['resource_type']);
        self::assertNotNull($result['seo_context']);
        self::assertIsArray($result['provider_payload']);
        self::assertSame('article', $result['provider_payload']['type']);
    }

    /** Verify long editor markup is projected to bounded plain text without changing local analysis input. */
    public function testWordPressReaderBoundsPreviewProjectionAndClosedReasons(): void
    {
        $reader = new WordPressContentReader(static fn (int $id): array => [
            'id' => $id,
            'post_type' => 'post',
            'title' => 'A valid title',
            'content' => '<!-- wp:paragraph --><p>' . str_repeat('Safe text ', 400) . '</p><!-- /wp:paragraph -->',
            'url' => 'http://localhost/draft',
        ]);
        $result = $reader->readPost(7);
        self::assertSame('ok', $result['status']);
        self::assertSame('accepted', $result['payload_status']);
        self::assertLessThanOrEqual(2000, preg_match_all('/./us', $result['provider_payload']['content']));
        self::assertStringNotContainsString('<!--', $result['provider_payload']['content']);
        self::assertStringNotContainsString('<p>', $result['provider_payload']['content']);
        self::assertArrayNotHasKey('url', $result['provider_payload']);

        $empty = new WordPressContentReader(static fn (int $id): array => [
            'id' => $id, 'post_type' => 'post', 'title' => 'Title', 'content' => '   ',
        ]);
        $emptyResult = $empty->readPost(7);
        self::assertSame('rejected', $emptyResult['payload_status']);
        self::assertSame('missing_content', $emptyResult['payload_reason']);
    }

    /**
     * Verify product and variation projections preserve decimal strings and reject an unknown parent.
     * @return void
     */
    public function testWooCommerceReaderPreservesDecimalAndFailsClosedForParent(): void
    {
        $reader = new WooCommerceProductReader(static fn (int $id): array => [
            'resource_type' => 'product',
            'id' => $id,
            'product_name' => 'Simple product',
            'description' => 'A product description',
            'price' => '199000.50',
            'manage_stock' => false,
            'stock_status' => 'instock',
            'currency' => 'VND',
            'parent_exists' => true,
        ]);

        $result = $reader->readProduct(9);
        self::assertSame('ok', $result['status']);
        self::assertSame('199000.50', $result['provider_payload']['price']);
        self::assertArrayNotHasKey('stock', $result['provider_payload']);

        $variationReader = new WooCommerceProductReader(static fn (int $id): array => [
            'resource_type' => 'variation',
            'id' => $id,
            'product_name' => 'Variation',
            'parent_id' => 20,
            'variation' => ['id' => $id, 'parent_id' => 20],
        ]);
        self::assertSame('parent_product_missing', $variationReader->readProduct(10)['reason']);
    }

    /**
     * Verify the passive Rank Math bridge remains unknown without WordPress and a zero-rule matrix.
     * @return void
     */
    public function testRankMathProbeFailsClosedWithoutWordPress(): void
    {
        $probe = new WordPressRankMathProbe();
        $detector = new RankMathDetector(RankMathCompatibilityMatrix::shippedDefault(), $probe);
        $result = $detector->detect();

        self::assertSame('unknown', $result['compatibility']);
        self::assertSame('unobserved', $result['signals']['installation']['evidence_state']);
    }

    /** Verify privacy checks inspect the complete normalized source before preview truncation. */
    public function testPreviewRejectsPrivateTextAfterProjectionLimitAndMissingTitles(): void
    {
        $reader = new WordPressContentReader(static fn (int $id): array => [
            'id' => $id,
            'post_type' => 'post',
            'title' => 'Title',
            'content' => str_repeat('safe ', 500) . ' email@example.com',
            'excerpt' => str_repeat('excerpt ', 600),
            'meta_description' => str_repeat('meta ', 600),
        ]);
        $private = $reader->readPost(9);
        self::assertSame('rejected', $private['payload_status']);
        self::assertSame('privacy_policy', $private['payload_reason']);

        $arrayMissing = new WordPressContentReader(static fn (int $id): array => [
            'id' => $id, 'post_type' => 'post', 'content' => 'Body',
        ]);
        self::assertSame('missing_title', $arrayMissing->readPost(9)['payload_reason']);

        $objectMissing = new WordPressContentReader(static fn (int $id): object => (object) [
            'ID' => $id, 'post_type' => 'post', 'post_title' => '', 'post_content' => 'Body',
        ]);
        self::assertSame('missing_title', $objectMissing->readPost(9)['payload_reason']);
    }
}
