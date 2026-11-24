<?php
/*
 * Exposes the WordPress content reader under the runtime inspection service
 * name used by the composition root, preserving a single closed projection
 * contract for post and page diagnostics.
 */

declare(strict_types=1);

namespace MySeoForAll\SEO\Runtime;

final class ContentSeoInspector
{
    private WordPressContentReader $reader;

    /**
     * Store the read-only content reader used for all inspection calls.
     * The inspector does not cache or mutate snapshots.
     * @param WordPressContentReader $reader
     * @return void
     */
    public function __construct(WordPressContentReader $reader)
    {
        $this->reader = $reader;
    }

    /**
     * Inspect one post or page through the injected read-only reader.
     * @param int $postId
     * @return array<string,mixed>
     */
    public function inspect(int $postId): array
    {
        return $this->reader->readPost($postId);
    }

    /**
     * Return the underlying reader for composition tests without exposing mutable state.
     * @return WordPressContentReader
     */
    public function reader(): WordPressContentReader
    {
        return $this->reader;
    }
}
