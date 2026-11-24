<?php
/*
 * Exercises the Increment E composition root in one focused offline PHPUnit case.
 */

declare(strict_types=1);

namespace MySeoForAll\Tests\Unit;

use MySeoForAll\Core\Container;
use MySeoForAll\Core\HookDispatcher;
use MySeoForAll\Core\Plugin;
use PHPUnit\Framework\TestCase;

final class RuntimeCompositionSmokeTest extends TestCase
{
    /**
     * Verify plugin boot composes all four modules and the container services without WordPress.
     * @return void
     */
    public function testCompositionBoots(): void
    {
        $container = new Container();
        $plugin = new Plugin($container, null, null, null, null, new HookDispatcher());
        $plugin->boot();
        $health = $plugin->health();

        self::assertCount(4, $health['modules']);
        self::assertTrue($container->has(\MySeoForAll\SEO\Runtime\ContentSeoInspector::class));
    }
}
