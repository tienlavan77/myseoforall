<?php

/**
 * Immutable offline fixture source observer for Rank Math read adapter tests and dry evaluations.
 *
 * Business purpose: Provide deterministic offline test observations that explicitly label their
 * provenance as `fixture` so test fixtures can never be mistaken for live Rank Math runtime reads.
 * Technical description: Deep-copies fixture slot records on construction and returns presence-aware
 * observations (`present`, `missing`, `unreadable`) without fabricating `explicit_null` when `value` is omitted.
 */

declare(strict_types=1);

namespace MySeoForAll\Integrations\RankMath;

/**
 * Serves immutable fixture observations for RankMathReadAdapter.
 *
 * Business purpose: Support contract testing of presence, explicit null, unreadable errors,
 * and fallback suppression without touching WordPress globals or post meta tables.
 * Technical description: Stores a normalized map keyed by `[sourceId][slotKey]` and stamps
 * `fixture:` evidence identities and `provenance => 'fixture'`.
 */
final class RankMathFixtureSource implements RankMathSourceObserver
{
    private string $fixtureId;
    private bool $confirmPresenceByDefault;

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $slots;

    /**
     * Construct an immutable fixture source from nested source/slot definitions.
     *
     * Business purpose: Guarantee caller fixture arrays are never mutated and carry explicit fixture identity.
     * Technical description: Validates fixture identifier and copies nested source slot entries.
     *
     * @param array<string, mixed> $sources
     */
    public function __construct(
        string $fixtureId,
        array $sources,
        bool $confirmPresenceByDefault = true
    ) {
        if (trim($fixtureId) === '') {
            throw new \InvalidArgumentException('Fixture source identifier must be non-empty.');
        }

        $this->fixtureId = trim($fixtureId);
        $this->confirmPresenceByDefault = $confirmPresenceByDefault;
        $this->slots = $this->copySources($sources);
    }

    /**
     * Observe one mapped slot from the immutable fixture dataset.
     *
     * Business purpose: Return deterministic presence/null/unreadable observations tagged as `fixture`.
     * Technical description: When a slot is omitted, reports `missing` only if presence confirmation is enabled;
     * otherwise reports `unreadable` with `presence_unconfirmed` so fallback cannot fire silently.
     *
     * @return array<string, mixed>
     */
    public function observe(string $sourceId, string $slotKey): array
    {
        $defaultEvidence = 'fixture:' . $this->fixtureId . ':' . $sourceId . ':' . $slotKey;
        if (!isset($this->slots[$sourceId]) || !array_key_exists($slotKey, $this->slots[$sourceId])) {
            if (!$this->confirmPresenceByDefault) {
                return [
                    'presence' => 'unreadable',
                    'can_confirm_presence' => false,
                    'value' => null,
                    'source' => $sourceId,
                    'evidence_identity' => $defaultEvidence,
                    'provenance' => 'fixture',
                    'reason' => 'presence_unconfirmed',
                ];
            }

            return [
                'presence' => 'missing',
                'can_confirm_presence' => true,
                'value' => null,
                'source' => $sourceId,
                'evidence_identity' => $defaultEvidence,
                'provenance' => 'fixture',
                'reason' => 'source_slot_missing',
            ];
        }

        $entry = $this->slots[$sourceId][$slotKey];
        $observation = [
            'presence' => $entry['presence'],
            'can_confirm_presence' => $entry['can_confirm_presence'],
            'source' => $entry['source'] ?? $sourceId,
            'evidence_identity' => $entry['evidence_identity'] ?? $defaultEvidence,
            'provenance' => 'fixture',
            'reason' => $entry['reason'],
        ];
        if ($entry['has_value']) {
            $observation['value'] = $entry['value'];
        }

        return $observation;
    }

    /**
     * Copy and normalize caller-supplied fixture definitions without mutating inputs.
     *
     * Business purpose: Enforce fixture immutability and preserve whether `value` was actually supplied
     * on explicit `presence => 'present'` records so missing keys fail closed instead of becoming `explicit_null`.
     * Technical description: Supports both explicit observation records (`['presence' => ...]`) and
     * shorthand raw values (treated as `presence => 'present'` with `has_value => true`).
     *
     * @param array<string, mixed> $sources
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function copySources(array $sources): array
    {
        $copied = [];
        foreach ($sources as $sourceId => $slots) {
            if (!is_string($sourceId) || !is_array($slots)) {
                continue;
            }
            $copied[$sourceId] = [];
            foreach ($slots as $slotKey => $raw) {
                if (!is_string($slotKey)) {
                    continue;
                }
                if (is_array($raw) && array_key_exists('presence', $raw)) {
                    $hasValue = array_key_exists('value', $raw);
                    $copied[$sourceId][$slotKey] = [
                        'presence' => $raw['presence'],
                        'can_confirm_presence' => array_key_exists('can_confirm_presence', $raw)
                            ? $raw['can_confirm_presence'] === true
                            : $this->confirmPresenceByDefault,
                        'has_value' => $hasValue,
                        'value' => $hasValue ? $this->cloneValue($raw['value']) : null,
                        'source' => isset($raw['source']) && is_string($raw['source']) ? $raw['source'] : $sourceId,
                        'evidence_identity' => isset($raw['evidence_identity']) && is_string($raw['evidence_identity'])
                            ? $raw['evidence_identity']
                            : null,
                        'reason' => isset($raw['reason']) && is_string($raw['reason']) ? $raw['reason'] : null,
                    ];
                } else {
                    $copied[$sourceId][$slotKey] = [
                        'presence' => 'present',
                        'can_confirm_presence' => $this->confirmPresenceByDefault,
                        'has_value' => true,
                        'value' => $this->cloneValue($raw),
                        'source' => $sourceId,
                        'evidence_identity' => null,
                        'reason' => null,
                    ];
                }
            }
        }

        return $copied;
    }

    /**
     * Recursively clone array/object values so fixture storage remains isolated from caller mutations.
     *
     * Business purpose: Guarantee input non-mutation during adapter read tests.
     * Technical description: Clones objects and maps arrays recursively.
     */
    private function cloneValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->cloneValue($item), $value);
        }
        if (is_object($value)) {
            return clone $value;
        }

        return $value;
    }
}
