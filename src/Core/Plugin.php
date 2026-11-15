<?php
/**
 * Composes and boots the My SEO For All foundation modules.
 *
 * This class is the single lifecycle owner: it loads configuration, registers
 * services, boots available modules once, and exposes safe activation health data.
 */

declare(strict_types=1);

namespace MySeoForAll\Core;

use MySeoForAll\Admin\AdminModule;
use MySeoForAll\Core\Configuration\Configuration;
use MySeoForAll\Core\Environment\EnvironmentCheck;
use MySeoForAll\Core\Contracts\Module;
use MySeoForAll\Infrastructure\WordPress\OptionStore;
use MySeoForAll\WooCommerce\WooCommerceModule;

// Defines Plugin class behavior for Plugin.php.
final class Plugin
{
    private bool $booted = false;

    /** @var list<Module> */
    private array $modules;

    /**
     * Composes the foundation services and module list without contacting external APIs.
     */
    private Container $container;

    /**
     * Composes the foundation services and module list without contacting external APIs.
     */
    public function __construct(
        ?Container $container = null,
        ?Configuration $configuration = null,
        ?EnvironmentCheck $environment = null,
        ?OptionStore $options = null,
        ?array $modules = null
    ) {
        $this->container = $container ?? new Container();
        $options ??= new OptionStore();
        $configuration ??= new Configuration((array) $options->get(Configuration::OPTION_KEY, []));
        $environment ??= new EnvironmentCheck();

        $this->container->set(Configuration::class, $configuration);
        $this->container->set(EnvironmentCheck::class, $environment);
        $this->container->set(OptionStore::class, $options);
        $this->modules = $modules ?? [
            new AdminModule($configuration, $environment, $options),
            new WooCommerceModule(),
        ];
    }

    /**
     * Registers services and boots each available module exactly once.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        foreach ($this->modules as $module) {
            $module->register();
        }
        foreach ($this->modules as $module) {
            if ($module->isAvailable()) {
                $module->boot();
            }
        }

        $this->booted = true;
    }

    /**
     * Returns the composed configuration and module health for diagnostics.
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        $environment = $this->container->get(EnvironmentCheck::class);
        $configuration = $this->container->get(Configuration::class);

        return [
            'plugin' => [
                'name' => 'My SEO For All',
                'version' => defined('MYSEO_FOR_ALL_VERSION') ? MYSEO_FOR_ALL_VERSION : '0.1.0',
            ],
            'configuration' => $configuration->all(),
            'environment' => $environment->evaluate(),
            'modules' => array_map(static fn (Module $module): array => $module->health(), $this->modules),
        ];
    }

    /**
     * Creates the namespaced site configuration during plugin activation without heavy work.
     */
    public static function activate(): void
    {
        $options = new OptionStore();
        $existing = $options->get(Configuration::OPTION_KEY, []);
        $configuration = new Configuration(is_array($existing) ? $existing : []);
        $options->update(Configuration::OPTION_KEY, $configuration->all());
    }

    /**
     * Stops runtime behavior on deactivation while preserving every user option.
     */
    public static function deactivate(): void
    {
        // Deactivation deliberately preserves configuration and user data.
    }
}
