<?php

declare(strict_types=1);

namespace MySeoForAll\Core\Configuration;

/**
 * Normalizes the six-key core configuration contract and drops every unrelated persisted key.
 */
final class Configuration
{
    public const SCHEMA_VERSION = 1;
    public const OPTION_KEY = 'myseo_core_settings';

    /** @var list<string> */
    private const MODULES = ['core', 'admin', 'woocommerce', 'seo', 'rank_math'];

    /** @var array<string,mixed> */
    private array $values;

    /**
     * Build a closed configuration from an untrusted partial option value.
     *
     * @param array<string,mixed> $values
     */
    public function __construct(array $values = [])
    {
        $defaults = self::defaults();
        $this->values = $defaults;
        if (isset($values['schema_version']) && (int) $values['schema_version'] === self::SCHEMA_VERSION) {
            $this->values['schema_version'] = self::SCHEMA_VERSION;
        }
        if (isset($values['locale']) && is_string($values['locale'])) {
            $locale = $values['locale'];
            if (preg_match('/^[a-z]{2}(?:_[A-Z]{2})?$/', $locale) === 1) {
                $this->values['locale'] = $locale;
            }
        }
        if (isset($values['module_enablement']) && is_array($values['module_enablement'])) {
            $modules = [];
            foreach (self::MODULES as $module) {
                if (array_key_exists($module, $values['module_enablement']) && is_bool($values['module_enablement'][$module])) {
                    $modules[$module] = $values['module_enablement'][$module];
                }
            }
            $this->values['module_enablement'] = array_replace($defaults['module_enablement'], $modules);
        }
        if (isset($values['migration_status']) && is_string($values['migration_status']) && in_array($values['migration_status'], ['pending', 'complete', 'failed'], true)) {
            $this->values['migration_status'] = $values['migration_status'];
        }
        if (isset($values['rank_math_mode']) && in_array($values['rank_math_mode'], ['observe', 'disabled', 'unknown'], true)) {
            $this->values['rank_math_mode'] = $values['rank_math_mode'];
        }
        if (isset($values['sitemap_owner']) && in_array($values['sitemap_owner'], ['myseo_for_all', 'rank_math', 'unknown'], true)) {
            $this->values['sitemap_owner'] = $values['sitemap_owner'];
        }
    }

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'locale' => 'vi_VN',
            'module_enablement' => ['core' => true, 'admin' => true, 'woocommerce' => true, 'seo' => true, 'rank_math' => true],
            'migration_status' => 'complete',
            'rank_math_mode' => 'observe',
            'sitemap_owner' => 'unknown',
        ];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->values;
    }
}
