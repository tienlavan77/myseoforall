<?php

declare(strict_types=1);

namespace MySeoForAll\Tests\Unit;

use PHPUnit\Framework\TestCase;
use MySeoForAll\Integrations\RankMath\WordPressRankMathProbe;
use MySeoForAll\Integrations\RankMath\WordPressRankMathSourceObserver;

final class IncrementER6VerificationTest extends TestCase
{
    /**
     * Confirms Rank Math installation, activation, version, and malformed option states.
     */
    public function testRankMathProbeMatrix(): void
    {
        $outside = new WordPressRankMathProbe();
        self::assertSame('unobserved', $outside->observeActivation()['evidence_state']);

        $absent = new WordPressRankMathProbe(
            static fn (string $key, $default = null): array => [],
            static fn (): array => []
        );
        self::assertSame('inactive', $absent->observeActivation()['state']);
        self::assertSame('absent', $absent->observeInstallation()['state']);

        $installedInactive = new WordPressRankMathProbe(
            static fn (string $key, $default = null): array => [],
            static fn (): array => ['seo-by-rank-math/rank-math.php' => []]
        );
        self::assertSame('installed', $installedInactive->observeInstallation()['state']);
        self::assertSame('inactive', $installedInactive->observeActivation()['state']);

        $active = new WordPressRankMathProbe(
            static fn (string $key, $default = null): array => ['seo-by-rank-math/rank-math.php'],
            static fn (): array => ['seo-by-rank-math/rank-math.php' => []],
            static fn (): string => '1.0.210'
        );
        self::assertSame('active', $active->observeActivation()['state']);
        self::assertSame('present', $active->observeVersion()['state']);
        self::assertSame('1.0.210', $active->observeVersion()['value']);

        $invalid = new WordPressRankMathProbe(
            static fn (string $key, $default = null): array => [123],
            static fn (): array => []
        );
        $invalidActivation = $invalid->observeActivation();
        self::assertSame('invalid', $invalidActivation['evidence_state']);
        self::assertSame('wordpress:rank_math:active_plugins', $invalidActivation['evidence_identity']);
    }

    /**
     * Confirms unmapped, malformed, and unauthorized source requests never call metadata bridges.
     */
    public function testObserverZeroMetaCallsForRejectedSources(): void
    {
        $metaCalls = 0;
        $exists = static function (string $objectType, int $postId, string $slot) use (&$metaCalls): bool {
            $metaCalls++;
            return true;
        };
        $reader = static function (int $postId, string $slot, bool $single) use (&$metaCalls): string {
            $metaCalls++;
            return 'value';
        };

        foreach ([
            [[], 'post:7', 'rank_math_title', null],
            [['rank_math_title'], 'bad', 'rank_math_title', true],
            [['rank_math_title'], 'post:7', 'other_slot', true],
            [['rank_math_title'], 'post:7', 'rank_math_title', false],
        ] as [$slots, $sourceId, $slot, $allowed]) {
            $observer = new WordPressRankMathSourceObserver(
                $slots,
                $exists,
                $reader,
                $allowed === null ? null : static fn (string $capability, int $postId): bool => $allowed
            );
            $observer->observe($sourceId, $slot);
        }
        self::assertSame(0, $metaCalls);
    }

    /**
     * Confirms authorized mapped reads distinguish missing and present metadata and preserve provenance.
     */
    public function testObserverAuthorizedPresenceAndUnavailableBridge(): void
    {
        $missing = new WordPressRankMathSourceObserver(
            ['rank_math_title'],
            static fn (string $objectType, int $postId, string $slot): bool => false,
            static fn (int $postId, string $slot, bool $single): string => 'unused',
            static fn (string $capability, int $postId): bool => true
        );
        $missingResult = $missing->observe('post:7', 'rank_math_title');
        self::assertSame('missing', $missingResult['presence']);
        self::assertSame('runtime_verified', $missingResult['provenance']);

        $present = new WordPressRankMathSourceObserver(
            ['rank_math_title'],
            static fn (string $objectType, int $postId, string $slot): bool => true,
            static fn (int $postId, string $slot, bool $single): string => 'observed',
            static fn (string $capability, int $postId): bool => true
        );
        $presentResult = $present->observe('post:7', 'rank_math_title');
        self::assertSame('present', $presentResult['presence']);
        self::assertSame('observed', $presentResult['value']);
        self::assertSame('runtime_verified', $presentResult['provenance']);

        $unavailable = new WordPressRankMathSourceObserver(
            ['rank_math_title'],
            null,
            null,
            static fn (string $capability, int $postId): bool => true
        );
        $unavailableResult = $unavailable->observe('post:7', 'rank_math_title');
        self::assertSame('unreadable', $unavailableResult['presence']);
        self::assertFalse($unavailableResult['can_confirm_presence']);
        self::assertSame('synthetic', $unavailableResult['provenance']);
    }

    /**
     * Confirms admin capability gating and coexistence labels reject untrusted owner/state values.
     */
    public function testAdminCapabilityGateAndCoexistenceRedaction(): void
    {
        $healthCalls = 0;
        $environmentCalls = 0;
        $healthProvider = static function () use (&$healthCalls): array {
            $healthCalls++;
            return [
                'environment' => [
                    'php' => ['compatible' => true, 'installation' => 'installed', 'activation' => 'active'],
                    'wordpress' => ['compatible' => true, 'installation' => 'installed', 'activation' => 'active'],
                    'woocommerce' => ['compatible' => true, 'installation' => 'installed', 'activation' => 'inactive'],
                    'mbstring' => ['compatible' => true, 'installation' => 'absent', 'activation' => 'unknown', 'fallback' => true],
                ],
                'coexistence' => [
                    'outputs' => [
                        'title_meta' => [
                            'owner' => '<script>bad</script>',
                            'state' => '<script>bad</script>',
                            'duplicate_risk' => 'blocked',
                        ],
                    ],
                ],
                'modules' => [],
            ];
        };
        $admin = new \MySeoForAll\Admin\AdminModule(
            new \MySeoForAll\Core\Configuration\Configuration([]),
            new \MySeoForAll\Core\Environment\EnvironmentCheck(
                static function () use (&$environmentCalls): array {
                    $environmentCalls++;
                    return [];
                }
            ),
            new \MySeoForAll\Infrastructure\WordPress\OptionStore(),
            $healthProvider,
            null,
            static fn (string $capability): bool => true
        );
        ob_start();
        $admin->renderPage();
        $html = ob_get_clean();
        self::assertSame(1, $healthCalls);
        self::assertSame(0, $environmentCalls);
        self::assertStringContainsString('duplicate_risk=blocked', $html);
        self::assertStringContainsString('owner=unknown; state=unknown', $html);
        self::assertStringContainsString('fallback UTF-8 deterministic', $html);
        self::assertStringNotContainsString('<script>bad</script>', $html);
    }

    /**
     * Confirms object-backed post, page, product, and variation projections remain typed.
     */
    public function testObjectBackedFourResources(): void
    {
        $postLoader = static fn (int $id): object => (object) [
            'ID' => $id,
            'post_type' => $id === 1 ? 'post' : 'page',
            'post_title' => $id === 1 ? 'Post title' : 'Page title',
            'post_content' => '<h1>Heading</h1><p>Body</p>',
            'post_excerpt' => 'Excerpt',
            'post_name' => 'bai-viet-slug',
            'unexpected' => 'ignored',
        ];
        $contentReader = new \MySeoForAll\SEO\Runtime\WordPressContentReader(
            $postLoader,
            null,
            null,
            static fn (int $id): string => 'https://example.test/' . $id
        );
        $post = $contentReader->readPost(1);
        $page = $contentReader->readPost(2);
        self::assertSame('ok', $post['status']);
        self::assertSame('ok', $page['status']);
        self::assertNotEmpty($post['findings']);
        self::assertNotEmpty($page['findings']);
        (new \MySeoForAll\SEO\Context\ProviderPayloadBuilder())->validatePayload($post['provider_payload']);
        (new \MySeoForAll\SEO\Context\ProviderPayloadBuilder())->validatePayload($page['provider_payload']);

        $productClass = new class {
            /** Return the object identifier. */
            public function get_id(): int { return 3; }
            /** Return the simple product type. */
            public function get_type(): string { return 'simple'; }
            /** Return the product name. */
            public function get_name(): string { return 'Simple product'; }
            /** Return the description. */
            public function get_description(): string { return 'Description'; }
            /** Return the short description. */
            public function get_short_description(): string { return 'Short'; }
            /** Return the SKU. */
            public function get_sku(): ?string { return 'SKU-3'; }
            /** Return the decimal price. */
            public function get_price(string $context = 'view'): string { return '10.00'; }
            /** Return whether stock is managed. */
            public function get_manage_stock(): bool { return true; }
            /** Return the stock quantity. */
            public function get_stock_quantity(string $context = 'view'): int { return 0; }
            /** Return the stock status. */
            public function get_stock_status(string $context = 'view'): string { return 'instock'; }
            /** Return the product permalink. */
            public function get_permalink(): string { return 'https://example.test/product/3'; }
            /** Return product attributes. */
            public function get_attributes(): array { return []; }
        };
        $productReader = new \MySeoForAll\SEO\Runtime\WooCommerceProductReader(
            static fn (int $id) => $productClass,
            null,
            null,
            null,
            static fn (): string => 'USD'
        );
        $product = $productReader->readProduct(3);
        self::assertSame('ok', $product['status']);
        self::assertNotEmpty($product['findings']);
        self::assertSame(0, $product['provider_payload']['stock']);
        (new \MySeoForAll\SEO\Context\ProviderPayloadBuilder())->validatePayload($product['provider_payload']);

        $variationClass = new class {
            /** Return the variation identifier. */
            public function get_id(): int { return 4; }
            /** Return the variation type. */
            public function get_type(): string { return 'variation'; }
            /** Return the parent identifier. */
            public function get_parent_id(): int { return 3; }
            /** Return the variation name. */
            public function get_name(): string { return 'Variation'; }
            /** Return the variation description. */
            public function get_description(): string { return 'Variation description'; }
            /** Return the variation short description. */
            public function get_short_description(): string { return ''; }
            /** Return the variation SKU. */
            public function get_sku(): ?string { return null; }
            /** Return the variation price. */
            public function get_price(string $context = 'view'): string { return '11.00'; }
            /** Return whether variation stock is managed. */
            public function get_manage_stock(): bool { return false; }
            /** Return the variation stock quantity. */
            public function get_stock_quantity(string $context = 'view'): ?int { return null; }
            /** Return the variation stock status. */
            public function get_stock_status(string $context = 'view'): string { return 'instock'; }
            /** Return the variation permalink. */
            public function get_permalink(): string { return 'https://example.test/product/4'; }
            /** Return variation attributes. */
            public function get_attributes(): array { return []; }
        };
        $parentCalls = 0;
        $variationReader = new \MySeoForAll\SEO\Runtime\WooCommerceProductReader(
            static fn (int $id) => $variationClass,
            null,
            null,
            static function (int $id) use (&$parentCalls): object {
                $parentCalls++;
                return new class($id) {
                    /** Store the parent identifier. */
                    public function __construct(private int $id) {}
                    /** Return the parent identifier. */
                    public function get_id(): int { return $this->id; }
                    /** Return the variable product type. */
                    public function get_type(): string { return 'variable'; }
                };
            },
            static fn (): string => 'USD'
        );
        $variation = $variationReader->readProduct(4);
        self::assertSame('ok', $variation['status']);
        self::assertSame(1, $parentCalls);
        (new \MySeoForAll\SEO\Context\ProviderPayloadBuilder())->validatePayload($variation['provider_payload']);
    }
}
