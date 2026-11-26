<?php
/**
 * Business purpose: update only the permitted WooCommerce product description.
 * Technical description: use injectable product seams or guarded CRUD methods and never touch commercial fields.
 */
declare(strict_types=1);
namespace MySeoForAll\SEO\Runtime;

final class WooCommerceDescriptionWriter
{
    /** Business purpose: configure the product description boundary. Technical description: retain optional read and write seams for offline tests. */
    public function __construct(private $reader = null, private $writer = null) {}

    /** Business purpose: read the current product description. Technical description: use the seam or guarded wc_get_product and get_description methods. */
    public function read(int $productId): ?string
    {
        try {
            $value = is_callable($this->reader) ? ($this->reader)($productId) : null;
            if ($value === null && !is_callable($this->reader) && function_exists('wc_get_product')) {
                $product = wc_get_product($productId);
                $value = is_object($product) && method_exists($product, 'get_description') ? $product->get_description() : null;
            }
        } catch (\Throwable $e) {
            return null;
        }
        return is_string($value) ? $value : null;
    }

    /** Business purpose: persist one approved product description. Technical description: invoke only set_description and save on the product CRUD object. */
    public function write(int $productId, string $value): bool
    {
        try {
            if (is_callable($this->writer)) return (bool) ($this->writer)($productId, $value);
            if (!function_exists('wc_get_product')) return false;
            $product = wc_get_product($productId);
            if (!is_object($product) || !method_exists($product, 'set_description') || !method_exists($product, 'save')) return false;
            $product->set_description($value);
            $product->save();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
