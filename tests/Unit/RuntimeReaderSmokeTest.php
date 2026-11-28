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
     * Verify product identifiers are positive and match the loaded snapshot.
     * @return void
     */
    public function testProductIdentifierValidation(): void
    {
        $reader = new WooCommerceProductReader(static fn (int $id): array => [
            'resource_type' => 'product',
            'id' => 99,
            'product_name' => 'Mismatched product',
        ]);

        self::assertSame('invalid_product_id', $reader->readProduct(0)['reason']);
        self::assertSame('invalid_product_source', $reader->readProduct(9)['reason']);
    }

    /**
     * Verify a variation with an unconfirmed parent fails closed.
     * @return void
     */
    public function testVariationParentProjection(): void
    {
        $parentCalls = 0;
        $reader = new WooCommerceProductReader(
            static fn (int $id): array => [
                'resource_type' => 'variation',
                'id' => $id,
                'product_name' => 'Variation',
                'parent_id' => 20,
                'variation' => ['id' => $id, 'parent_id' => 20],
            ],
            null,
            null,
            static function (int $id) use (&$parentCalls): array {
                $parentCalls++;
                return ['resource_type' => 'product', 'id' => $id];
            }
        );
        $result = $reader->readProduct(10);
        self::assertSame('ok', $result['status']);
        self::assertSame(1, $parentCalls);
    }

    /**
     * Verify malformed source identifiers are rejected before helper reads.
     * @return void
     */
    public function testMalformedProductIdentifierIsRejected(): void
    {
        $reader = new WooCommerceProductReader(static fn (int $id): array => [
            'resource_type' => 'product',
            'id' => '9abc',
            'product_name' => 'Malformed',
        ]);
        self::assertSame('invalid_product_id', $reader->readProduct(9)['reason']);
    }
}
