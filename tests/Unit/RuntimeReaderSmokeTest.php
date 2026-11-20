<?php
/*
 * Exercises the Increment E reader projection contracts with deterministic offline fixtures.
 */

declare(strict_types=1);

namespace MySeoForAll\Tests\Unit;

use MySeoForAll\SEO\Runtime\WordPressContentReader;
use MySeoForAll\SEO\Runtime\WooCommerceProductReader;
use PHPUnit\Framework\TestCase;

final class RuntimeReaderSmokeTest extends TestCase
{
    /**
     * Verify an article fixture produces a local context and typed provider payload.
     * @return void
     */
    public function testArticleProjection(): void
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
        self::assertIsArray($result['provider_payload']);
    }

    /**
     * Verify an unmanaged-stock product preserves its decimal string and omits stock.
     * @return void
     */
    public function testProductProjection(): void
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
        self::assertIsArray($result['provider_payload']);
        self::assertSame('199000.50', $result['provider_payload']['price']);
        self::assertArrayNotHasKey('stock', $result['provider_payload']);
    }

    /**
     * Verify a variation with an unconfirmed parent fails closed.
     * @return void
     */
    public function testVariationParentProjection(): void
    {
        $reader = new WooCommerceProductReader(static fn (int $id): array => [
            'resource_type' => 'variation',
            'id' => $id,
            'product_name' => 'Variation',
            'parent_id' => 20,
            'variation' => ['id' => $id, 'parent_id' => 20],
            'parent_exists' => false,
        ]);
        self::assertSame('parent_product_missing', $reader->readProduct(10)['reason']);
    }
}
