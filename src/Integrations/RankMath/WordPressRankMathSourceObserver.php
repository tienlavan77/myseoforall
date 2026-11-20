<?php
/*
 * Reads allowlisted Rank Math post metadata through presence-aware WordPress
 * APIs and emits the observer contract required by RankMathReadAdapter.
 * It never loads unknown files, writes metadata, or treats unavailable APIs
 * as confirmed absence.
 */

declare(strict_types=1);

namespace MySeoForAll\Integrations\RankMath;

final class WordPressRankMathSourceObserver implements RankMathSourceObserver
{
    /** @var list<string> */
    private array $allowedSlots;

    /**
     * Restrict runtime reads to explicitly approved mapping slots.
     * @param list<string> $allowedSlots
     * @return void
     */
    public function __construct(array $allowedSlots = [])
    {
        $this->allowedSlots = [];
        foreach ($allowedSlots as $slot) {
            if (is_string($slot) && trim($slot) !== '') {
                $this->allowedSlots[] = trim($slot);
            }
        }
        $this->allowedSlots = array_values(array_unique($this->allowedSlots));
    }

    /**
     * Observe one post-meta slot with explicit presence and provenance metadata.
     * The source identifier must contain a positive post identifier to avoid broad reads.
     * @param string $sourceId
     * @param string $slotKey
     * @return array<string,mixed>
     */
    public function observe(string $sourceId, string $slotKey): array
    {
        $postId = $this->postId($sourceId);
        if ($postId <= 0 || $slotKey === '' || !in_array($slotKey, $this->allowedSlots, true)) {
            return $this->unreadable('unmapped_source');
        }
        if (!function_exists('metadata_exists') || !function_exists('get_post_meta')) {
            return $this->unreadable('wordpress_metadata_unavailable');
        }

        $present = metadata_exists('post', $postId, $slotKey);
        if (!$present) {
            return [
                'presence' => 'missing',
                'can_confirm_presence' => true,
                'value' => null,
                'source' => 'wordpress_post_meta',
                'evidence_identity' => 'wordpress:post:' . $postId . ':' . $slotKey,
                'provenance' => 'runtime_verified',
                'reason' => 'meta_missing',
            ];
        }

        return [
            'presence' => 'present',
            'can_confirm_presence' => true,
            'value' => get_post_meta($postId, $slotKey, true),
            'source' => 'wordpress_post_meta',
            'evidence_identity' => 'wordpress:post:' . $postId . ':' . $slotKey,
            'provenance' => 'runtime_verified',
        ];
    }

    /**
     * Extract a positive post identifier from the closed source identifier form.
     * @param string $sourceId
     * @return int
     */
    private function postId(string $sourceId): int
    {
        if (preg_match('/^(?:post:)?([1-9][0-9]*)$/', trim($sourceId), $matches) !== 1) {
            return 0;
        }
        return (int) $matches[1];
    }

    /**
     * Return an unreadable observer result without exposing platform details.
     * @param string $reason
     * @return array<string,mixed>
     */
    private function unreadable(string $reason): array
    {
        return [
            'presence' => 'unreadable',
            'can_confirm_presence' => false,
            'source' => 'wordpress_post_meta',
            'evidence_identity' => 'wordpress:unavailable',
            'provenance' => 'synthetic',
            'reason' => $reason,
        ];
    }
}
