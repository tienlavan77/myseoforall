<?php
/**
 * Business purpose: persist My SEO canonical fields without touching third-party metadata.
 * Technical description: expose field-scoped reads and writes through injectable WordPress seams.
 */
declare(strict_types=1);
namespace MySeoForAll\SEO\Runtime;

final class CanonicalSeoMetaRepository
{
    /** Business purpose: configure the canonical storage boundary. Technical description: retain optional read and write seams for deterministic tests. */
    public function __construct(private $reader = null, private $writer = null) {}

    /** Business purpose: read one canonical field. Technical description: use the seam or guarded get_post_meta and normalize to null/string. */
    public function read(int $postId, string $field): ?string
    {
        $key = $this->key($field);
        try {
            $value = is_callable($this->reader) ? ($this->reader)($postId, $key) : (function_exists('get_post_meta') ? get_post_meta($postId, $key, true) : null);
        } catch (\Throwable $e) {
            return null;
        }
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Business purpose: read the field-scoped canonical snapshot. Technical description: return only the three namespaced fields. */
    public function snapshot(int $postId): array
    {
        return ['title' => $this->read($postId, 'title'), 'meta_description' => $this->read($postId, 'meta_description'), 'canonical' => $this->read($postId, 'canonical')];
    }

    /** Business purpose: persist one approved canonical field. Technical description: reject unknown fields and invoke exactly one writer operation. */
    public function write(int $postId, string $field, string $value): bool
    {
        $key = $this->key($field);
        try {
            $written = is_callable($this->writer)
                ? ($this->writer)($postId, $key, $value)
                : (function_exists('update_post_meta') ? update_post_meta($postId, $key, $value) : false);
        } catch (\Throwable $e) {
            return false;
        }
        if ($written === true || $written === 1) return true;
        return $this->read($postId, $field) === $value;
    }

    /** Business purpose: map public fields to the private My SEO namespace. Technical description: fail closed for unknown fields. */
    private function key(string $field): string
    {
        $keys = ['title' => '_myseo_title', 'meta_description' => '_myseo_meta_description', 'canonical' => '_myseo_canonical'];
        if (!isset($keys[$field])) throw new \InvalidArgumentException('unsupported_field');
        return $keys[$field];
    }
}
