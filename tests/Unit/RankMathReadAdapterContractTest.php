<?php

/**
 * Contract tests for Rank Math read adapter, mapping catalog, and source-observation boundary (Increment D — O2).
 *
 * Business purpose: Prove `title`, `meta_description`, and `canonical` reads obey closed field states
 * (`value|missing|explicit_null|invalid|unsupported|unreadable`), strict fallback-on-missing-only semantics,
 * fixture provenance isolation, canonical URL syntax rules, template/secret rejection, and input immutability.
 * Technical description: Exercises RankMathReadAdapter, RankMathMappingCatalog, and RankMathFixtureSource
 * purely offline using shipped empty defaults and synthetic test catalogs without WordPress globals or network calls.
 */

declare(strict_types=1);

namespace MySeoForAll\Tests\Unit;

use MySeoForAll\Integrations\RankMath\RankMathFixtureSource;
use MySeoForAll\Integrations\RankMath\RankMathMappingCatalog;
use MySeoForAll\Integrations\RankMath\RankMathReadAdapter;
use MySeoForAll\Integrations\RankMath\RankMathSourceObserver;
use PHPUnit\Framework\TestCase;

/**
 * Verifies O2 Rank Math read adapter and source-observation contract semantics.
 *
 * Business purpose: Prevent raw templates, unconfirmed presence, invalid URLs, or extra metadata
 * from leaking across the Rank Math read boundary.
 * Technical description: Tests shipped default catalog, synthetic mapping precedence, fallback guards,
 * scalar validation, canonical URL normalization, and caller array non-mutation.
 */
final class RankMathReadAdapterContractTest extends TestCase
{
    /**
     * Verify shipped default catalog keeps unproven Rank Math versions `unsupported` without `value`.
     *
     * Business purpose: Ensure shipped adapter never guesses meta keys or version ranges without runtime proof.
     * Technical description: Calls readAll() on default adapter and checks `state=unsupported` across all 3 fields.
     */
    public function testShippedDefaultCatalogReturnsUnsupportedWithoutSpeculativeRanges(): void
    {
        $adapter = new RankMathReadAdapter();
        $fixture = new RankMathFixtureSource('default-check', [
            'post_meta' => ['rank_math_title' => 'Example Title'],
        ]);

        $snapshot = $adapter->readAll($fixture, '1.0.210');

        self::assertSame(0, $snapshot['catalog']['entry_count']);
        self::assertSame(['title', 'meta_description', 'canonical'], array_keys($snapshot['fields']));
        foreach (RankMathReadAdapter::ALLOWED_FIELDS as $field) {
            self::assertSame('unsupported', $snapshot['fields'][$field]['state']);
            self::assertSame('mapping_unproven_for_version', $snapshot['fields'][$field]['reason']);
            self::assertArrayNotHasKey('value', $snapshot['fields'][$field]);
        }
    }

    /**
     * Verify valid normalization, missing, explicit_null, unreadable, and fallback-only-on-missing behavior.
     *
     * Business purpose: Prove fallback runs exclusively when the primary source confirms `missing`
     * and never masks `explicit_null`, `invalid`, `unsupported`, or `unreadable` observations.
     * Technical description: Tests primary->fallback chain across confirmed missing, explicit null,
     * invalid type, unreadable error, and unconfirmed presence (`can_confirm_presence=false`).
     */
    public function testFieldStatesPrecedenceAndStrictMissingOnlyFallback(): void
    {
        $adapter = new RankMathReadAdapter($this->syntheticCatalog());

        $fallbackOnMissing = new RankMathFixtureSource('fx-fallback', [
            'primary_meta' => [],
            'fallback_meta' => [
                'rm_title_fallback' => "  Clean   Product\nTitle  ",
                'rm_desc_fallback' => 'Concise product summary.',
                'rm_canonical_fallback' => 'HTTPS://Example.COM:443/shop/item?ref=1',
            ],
            'ignored_extra' => [
                'rank_math_focus_keyword' => 'should-never-be-exposed',
            ],
        ]);

        $resolved = $adapter->readAll($fallbackOnMissing, '1.0.210');
        self::assertSame('value', $resolved['fields']['title']['state']);
        self::assertSame('Clean Product Title', $resolved['fields']['title']['value']);
        self::assertSame('fallback_meta', $resolved['fields']['title']['source']);
        self::assertSame('fixture', $resolved['fields']['title']['provenance']);

        self::assertSame('value', $resolved['fields']['canonical']['state']);
        self::assertSame('https://example.com/shop/item?ref=1', $resolved['fields']['canonical']['value']);
        self::assertCount(3, $resolved['fields']);

        $explicitNullBlocksFallback = new RankMathFixtureSource('fx-null', [
            'primary_meta' => [
                'rm_title' => ['presence' => 'present', 'value' => null],
            ],
            'fallback_meta' => [
                'rm_title_fallback' => 'Fallback Title Must Not Be Used',
            ],
        ]);
        $nullResult = $adapter->readField('title', $explicitNullBlocksFallback, '1.0.210');
        self::assertSame('explicit_null', $nullResult['state']);
        self::assertSame('primary_meta', $nullResult['source']);
        self::assertArrayNotHasKey('value', $nullResult);

        $invalidBlocksFallback = new RankMathFixtureSource('fx-invalid', [
            'primary_meta' => [
                'rm_title' => 12345,
            ],
            'fallback_meta' => [
                'rm_title_fallback' => 'Fallback Must Not Mask Invalid',
            ],
        ]);
        $invalidResult = $adapter->readField('title', $invalidBlocksFallback, '1.0.210');
        self::assertSame('invalid', $invalidResult['state']);
        self::assertSame('invalid_type', $invalidResult['reason']);
        self::assertArrayNotHasKey('value', $invalidResult);

        $unreadableBlocksFallback = new RankMathFixtureSource('fx-unreadable', [
            'primary_meta' => [
                'rm_title' => ['presence' => 'unreadable', 'reason' => 'storage_timeout'],
            ],
            'fallback_meta' => [
                'rm_title_fallback' => 'Fallback Must Not Mask Unreadable',
            ],
        ]);
        $unreadableResult = $adapter->readField('title', $unreadableBlocksFallback, '1.0.210');
        self::assertSame('unreadable', $unreadableResult['state']);
        self::assertSame('storage_timeout', $unreadableResult['reason']);
        self::assertArrayNotHasKey('value', $unreadableResult);

        $unconfirmedPresence = new RankMathFixtureSource('fx-unconfirmed', [
            'fallback_meta' => ['rm_title_fallback' => 'Should Not Be Reached'],
        ], false);
        $unconfirmedResult = $adapter->readField('title', $unconfirmedPresence, '1.0.210');
        self::assertSame('unreadable', $unconfirmedResult['state']);
        self::assertSame('presence_unconfirmed', $unconfirmedResult['reason']);

        $bothMissing = new RankMathFixtureSource('fx-missing', []);
        $missingResult = $adapter->readField('meta_description', $bothMissing, '1.0.210');
        self::assertSame('missing', $missingResult['state']);
        self::assertSame('field_missing', $missingResult['reason']);
        self::assertArrayNotHasKey('value', $missingResult);
    }

    /**
     * Verify ambiguous mapping collision, unsupported versions, and out-of-allowlist rejections.
     *
     * Business purpose: Ensure equal-precedence mapping collisions return `unsupported` with `mapping_ambiguous`
     * and non-allowlisted fields are strictly rejected.
     * Technical description: Tests tied catalog entries, unsupported version `2.0.0`, and `robots` rejection.
     */
    public function testAmbiguousMappingUnsupportedVersionAndAllowlistEnforcement(): void
    {
        $ambiguousCatalog = new RankMathMappingCatalog('syn-amb', 'r1', '2026-11-20', [
            [
                'mapping_id' => 'm1',
                'field' => 'title',
                'min_version' => '1.0.0',
                'max_version' => '1.9.9',
                'precedence' => 5,
                'data_type' => 'plain_text',
                'normalization' => 'trim_collapse_whitespace',
                'fallback_rule' => 'none',
                'evidence_source' => 'synthetic:a',
                'sources' => [['source' => 'primary_meta', 'slot' => 'rm_title_a']],
            ],
            [
                'mapping_id' => 'm2',
                'field' => 'title',
                'min_version' => '1.0.100',
                'max_version' => '1.5.0',
                'precedence' => 5,
                'data_type' => 'plain_text',
                'normalization' => 'trim_collapse_whitespace',
                'fallback_rule' => 'none',
                'evidence_source' => 'synthetic:b',
                'sources' => [['source' => 'primary_meta', 'slot' => 'rm_title_b']],
            ],
        ]);

        $adapter = new RankMathReadAdapter($ambiguousCatalog);
        $fixture = new RankMathFixtureSource('fx-amb', [
            'primary_meta' => ['rm_title_a' => 'A', 'rm_title_b' => 'B'],
        ]);

        $ambiguous = $adapter->readField('title', $fixture, '1.0.210');
        self::assertSame('unsupported', $ambiguous['state']);
        self::assertSame('mapping_ambiguous', $ambiguous['reason']);

        $unsupportedVer = (new RankMathReadAdapter($this->syntheticCatalog()))->readField('title', $fixture, '2.0.0');
        self::assertSame('unsupported', $unsupportedVer['state']);
        self::assertSame('version_unsupported', $unsupportedVer['reason']);

        $this->expectException(\InvalidArgumentException::class);
        $adapter->readField('robots', $fixture, '1.0.210');
    }

    /**
     * Verify text/canonical validation boundaries, secret rejection, malformed observer handling, and input non-mutation.
     *
     * Business purpose: Reject empty/whitespace strings, broken UTF-8, control chars, unrendered templates,
     * credentials, oversized text, and invalid canonical URLs (non-HTTP, userinfo, fragment) without mutating inputs.
     * Technical description: Iterates invalid payloads and verifies exact reason codes and input preservation.
     */
    public function testValidationBoundariesCanonicalPolicyAndInputNonMutation(): void
    {
        $adapter = new RankMathReadAdapter($this->syntheticCatalog());

        $invalidTitleCases = [
            'whitespace' => ['   ', 'empty_or_whitespace'],
            'bad_utf8' => ["\xB1\x31", 'invalid_utf8'],
            'control_char' => ["Hello\x07World", 'control_character_rejected'],
            'rm_template' => ['%title% %sep% %sitename%', 'unrendered_template_rejected'],
            'mustache_template' => ['Buy {{product.name}} now', 'unrendered_template_rejected'],
            'secret_token' => ['Title Bearer sk-ant-1234567890abcdef', 'secret_or_credential_rejected'],
            'oversized' => [str_repeat('a', RankMathReadAdapter::MAX_TITLE_CHARS + 1), 'exceeds_max_length'],
        ];

        foreach ($invalidTitleCases as $label => [$inputValue, $expectedReason]) {
            $source = new RankMathFixtureSource('fx-' . $label, [
                'primary_meta' => ['rm_title' => $inputValue],
            ]);
            $res = $adapter->readField('title', $source, '1.0.210');
            self::assertSame('invalid', $res['state'], $label);
            self::assertSame($expectedReason, $res['reason'], $label);
            self::assertArrayNotHasKey('value', $res);
        }

        $invalidCanonicals = [
            'relative' => ['/shop/item', 'invalid_canonical_url'],
            'javascript_scheme' => ['javascript:alert(1)', 'invalid_canonical_url'],
            'ftp_scheme' => ['ftp://example.com/item', 'invalid_canonical_scheme_or_host'],
            'userinfo' => ['https://user:pass@example.com/item', 'secret_or_credential_rejected'],
            'fragment' => ['https://example.com/item#section', 'canonical_fragment_rejected'],
        ];

        foreach ($invalidCanonicals as $label => [$url, $expectedReason]) {
            $source = new RankMathFixtureSource('fx-canon-' . $label, [
                'primary_meta' => ['rm_canonical' => $url],
            ]);
            $res = $adapter->readField('canonical', $source, '1.0.210');
            self::assertSame('invalid', $res['state'], $label);
            self::assertSame($expectedReason, $res['reason'], $label);
        }

        $rawFixtureData = [
            'primary_meta' => [
                'rm_title' => 'Immutable Title',
                'nested' => ['keep' => 'intact'],
            ],
        ];
        $copyBefore = $rawFixtureData;
        $fixture = new RankMathFixtureSource('fx-immutable', $rawFixtureData);
        $malformedObserver = new class () implements RankMathSourceObserver {
            /**
             * Return an observation missing required presence metadata.
             *
             * Business purpose: Test fail-closed adapter handling when custom observer omits presence.
             * Technical description: Returns array without `presence` key.
             *
             * @return array<string, mixed>
             */
            public function observe(string $sourceId, string $slotKey): array
            {
                return ['value' => 'no-presence-key', 'provenance' => 'unknown_origin'];
            }
        };

        $ok = $adapter->readField('title', $fixture, '1.0.210');
        $badObs = $adapter->readField('title', $malformedObserver, '1.0.210');

        self::assertSame($copyBefore, $rawFixtureData);
        self::assertSame('value', $ok['state']);
        self::assertSame('unreadable', $badObs['state']);
        self::assertSame('invalid_source_provenance', $badObs['reason']);
        self::assertSame('unverified_source', $badObs['provenance']);
    }

    /**
     * Verify D2-2 fail-closed rules: missing/unknown provenance, `present` with `can_confirm_presence=false`, and `present` missing `value` key.
     *
     * Business purpose: Ensure custom observers cannot omit provenance, bypass `can_confirm_presence`
     * on `present` records, or fabricate `explicit_null` when the `value` key is missing.
     * Technical description: Exercises custom observers and RankMathFixtureSource across all three D2-2 edge cases.
     */
    public function testProvenancePresenceConfirmationAndMissingValueKeyFailClosed(): void
    {
        $adapter = new RankMathReadAdapter($this->syntheticCatalog());
        self::assertSame(['fixture', 'synthetic', 'runtime_verified'], RankMathSourceObserver::ALLOWED_PROVENANCE);

        $missingProvenanceObserver = new class () implements RankMathSourceObserver {
            /**
             * Return an observation that omits the required `provenance` field.
             *
             * Business purpose: Verify missing provenance is rejected as unreadable rather than defaulted to `fixture`.
             * Technical description: Returns `presence=present`, `can_confirm_presence=true`, and `value` without `provenance`.
             *
             * @return array<string, mixed>
             */
            public function observe(string $sourceId, string $slotKey): array
            {
                return [
                    'presence' => 'present',
                    'can_confirm_presence' => true,
                    'value' => 'Should Not Pass',
                ];
            }
        };
        $noProv = $adapter->readField('title', $missingProvenanceObserver, '1.0.210');
        self::assertSame('unreadable', $noProv['state']);
        self::assertSame('invalid_source_provenance', $noProv['reason']);
        self::assertArrayNotHasKey('value', $noProv);

        $presentUnconfirmed = new RankMathFixtureSource('fx-present-unconfirmed', [
            'primary_meta' => [
                'rm_title' => [
                    'presence' => 'present',
                    'can_confirm_presence' => false,
                    'value' => 'Unconfirmed Present Must Fail Closed',
                ],
            ],
            'fallback_meta' => [
                'rm_title_fallback' => 'Fallback Must Not Run',
            ],
        ]);
        $unconfirmedRes = $adapter->readField('title', $presentUnconfirmed, '1.0.210');
        self::assertSame('unreadable', $unconfirmedRes['state']);
        self::assertSame('presence_unconfirmed', $unconfirmedRes['reason']);
        self::assertArrayNotHasKey('value', $unconfirmedRes);

        $presentWithoutValueKey = new RankMathFixtureSource('fx-missing-value-key', [
            'primary_meta' => [
                'rm_title' => [
                    'presence' => 'present',
                    'can_confirm_presence' => true,
                ],
            ],
            'fallback_meta' => [
                'rm_title_fallback' => 'Fallback Must Not Mask Malformed Observation',
            ],
        ]);
        $missingKeyRes = $adapter->readField('title', $presentWithoutValueKey, '1.0.210');
        self::assertSame('unreadable', $missingKeyRes['state']);
        self::assertSame('malformed_source_observation', $missingKeyRes['reason']);
        self::assertNotSame('explicit_null', $missingKeyRes['state']);
        self::assertArrayNotHasKey('value', $missingKeyRes);
    }

    /**
     * Build a synthetic test-only mapping catalog covering `title`, `meta_description`, and `canonical`.
     *
     * Business purpose: Exercise mapping resolution, precedence, and fallback chains in offline unit tests.
     * Technical description: Defines synthetic version `1.0.200..1.0.299` mappings with primary and fallback slots.
     */
    private function syntheticCatalog(): RankMathMappingCatalog
    {
        return new RankMathMappingCatalog('synthetic-mapping-catalog', 'test.r1', '2026-11-20', [
            [
                'mapping_id' => 'syn-title',
                'field' => 'title',
                'min_version' => '1.0.200',
                'max_version' => '1.0.299',
                'precedence' => 10,
                'data_type' => 'plain_text',
                'normalization' => 'trim_collapse_whitespace',
                'fallback_rule' => 'missing_only',
                'evidence_source' => 'synthetic:unit_test',
                'sources' => [
                    ['source' => 'primary_meta', 'slot' => 'rm_title'],
                    ['source' => 'fallback_meta', 'slot' => 'rm_title_fallback'],
                ],
            ],
            [
                'mapping_id' => 'syn-desc',
                'field' => 'meta_description',
                'min_version' => '1.0.200',
                'max_version' => '1.0.299',
                'precedence' => 10,
                'data_type' => 'plain_text',
                'normalization' => 'trim_collapse_whitespace',
                'fallback_rule' => 'missing_only',
                'evidence_source' => 'synthetic:unit_test',
                'sources' => [
                    ['source' => 'primary_meta', 'slot' => 'rm_desc'],
                    ['source' => 'fallback_meta', 'slot' => 'rm_desc_fallback'],
                ],
            ],
            [
                'mapping_id' => 'syn-canonical',
                'field' => 'canonical',
                'min_version' => '1.0.200',
                'max_version' => '1.0.299',
                'precedence' => 10,
                'data_type' => 'canonical_url',
                'normalization' => 'canonical_http_url',
                'fallback_rule' => 'missing_only',
                'evidence_source' => 'synthetic:unit_test',
                'sources' => [
                    ['source' => 'primary_meta', 'slot' => 'rm_canonical'],
                    ['source' => 'fallback_meta', 'slot' => 'rm_canonical_fallback'],
                ],
            ],
        ]);
    }
}
