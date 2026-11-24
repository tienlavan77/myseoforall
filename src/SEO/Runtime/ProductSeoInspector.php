<?php
/*
 * Exposes the WooCommerce product reader under the runtime inspection service
 * name used by the composition root, preserving a single closed projection
 * contract for product and variation diagnostics.
 */

declare(strict_types=1);

namespace MySeoForAll\SEO\Runtime;

final class ProductSeoInspector
{
    private WooCommerceProductReader $reader;

    /**
     * Store the read-only product reader used for every inspection call.
     * The inspector does not cache, persist, or mutate platform snapshots.
     * @param WooCommerceProductReader $reader
     * @return void
     */
    public function __construct(WooCommerceProductReader $reader)
    {
        $this->reader = $reader;
    }

    /**
     * Inspect one product or variation through the injected reader.
     * @param int $productId
     * @return array<string,mixed>
     */
    public function inspect(int $productId): array
    {
        return $this->reader->readProduct($productId);
    }

    /**
     * Return the underlying reader for composition tests without exposing mutable state.
     * @return WooCommerceProductReader
     */
    public function reader(): WooCommerceProductReader
    {
        return $this->reader;
    }
}
