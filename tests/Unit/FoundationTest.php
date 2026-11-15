<?php
/**
 * Protects foundation behavior for configuration, dependency safety, and idempotent boot.
 *
 * These tests exercise production classes through Composer autoload without pretending
 * that a unit fixture proves WordPress activation or a WooCommerce runtime migration.
 */

declare(strict_types=1);

namespace MySeoForAll\Tests\Unit;

use MySeoForAll\Core\Configuration\Configuration;
use MySeoForAll\Core\Environment\EnvironmentCheck;
use MySeoForAll\Core\Plugin;
use PHPUnit\Framework\TestCase;

// Defines FoundationTest class behavior for FoundationTest.php.
final class FoundationTest extends TestCase
{
    /**
     * Verifies that new sites receive the documented Vietnamese site configuration defaults.
     */
    public function testConfigurationDefaultsAreVersioned(): void
    {
        $configuration = new Configuration();

        self::assertSame(1, $configuration->get('schema_version'));
        self::assertSame('vi_VN', $configuration->get('locale'));
        self::assertTrue($configuration->get('module_enablement')['woocommerce']);
    }

    /**
     * Verifies that optional WooCommerce absence produces a status instead of a fatal error.
     */
    public function testEnvironmentCheckIsSafeWithoutWordPress(): void
    {
        $status = (new EnvironmentCheck())->evaluate();

        self::assertFalse($status['wordpress']['active']);
        self::assertFalse($status['woocommerce']['active']);
    }

    /**
     * Verifies that repeated plugin boot calls preserve one lifecycle registration pass.
     */
    public function testPluginBootAndHealthAreIdempotent(): void
    {
        $plugin = new Plugin();
        $plugin->boot();
        $firstHealth = $plugin->health();
        $plugin->boot();

        self::assertSame($firstHealth, $plugin->health());
        self::assertCount(2, $firstHealth['modules']);
    }
}
