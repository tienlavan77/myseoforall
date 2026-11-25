<?php
/**
 * Plugin Name: My SEO For All
 * Description: SEO Assistant for WordPress and WooCommerce.
 * Version: 0.1.0
 * Requires PHP: 8.0
 * Text Domain: myseo-for-all
 *
 * Boots the My SEO For All WordPress plugin and wires lifecycle hooks.
 */

declare(strict_types=1);

if (! defined('MYSEO_FOR_ALL_VERSION')) {
    define('MYSEO_FOR_ALL_VERSION', '0.1.0');
}

if (! defined('ABSPATH')) {
    exit;
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (is_readable($autoload)) {
    require_once $autoload;
}

// Load the plugin's PSR-4 classes when a production package does not include Composer's vendor directory.
if (!class_exists('MySeoForAll' . chr(92) . 'Core' . chr(92) . 'Plugin')) {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'MySeoForAll' . chr(92);
        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        if ($relative === false || $relative === '') {
            return;
        }

        $file = __DIR__ . '/src/' . str_replace(chr(92), '/', $relative) . '.php';
        if (is_readable($file)) {
            require_once $file;
        }
    });
}

if (! class_exists(MySeoForAll\Core\Plugin::class)) {
    return;
}

$plugin = new MySeoForAll\Core\Plugin();
$plugin->boot();

register_activation_hook(__FILE__, [MySeoForAll\Core\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [MySeoForAll\Core\Plugin::class, 'deactivate']);
