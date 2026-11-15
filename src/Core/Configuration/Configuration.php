<?php
/**
 * Owns versioned, site-scoped foundation settings for My SEO For All.
 */
namespace MySeoForAll\Core\Configuration;

/**
 * Represents validated plugin configuration without exposing secrets.
 */
final class Configuration
{
    public const SCHEMA_VERSION = 1;
    public const OPTION_KEY = 'myseo_core_settings';

    /** @var array<string, mixed> */
    private array $values;

    /**
     * Creates configuration from a partial persisted value.
     *
     * @param array<string, mixed> $values
     */
    public function __construct(array $values = [])
    {
        $this->values = array_merge(self::defaults(), $values);
        $this->values['schema_version'] = self::SCHEMA_VERSION;
        $locale = (string) $this->values['locale'];
        $this->values['locale'] = function_exists('sanitize_key')
            ? sanitize_key($locale)
            : (string) preg_replace('/[^a-zA-Z0-9_]/', '', $locale);
        if ($this->values['locale'] === '') {
            $this->values['locale'] = 'vi_VN';
        }
        $this->values['module_enablement'] = is_array($this->values['module_enablement'])
            ? array_map('boolval', $this->values['module_enablement'])
            : [];
        $migrationStatus = (string) $this->values['migration_status'];
        $this->values['migration_status'] = function_exists('sanitize_key')
            ? sanitize_key($migrationStatus)
            : strtolower($migrationStatus);
    }

    /**
     * Returns safe defaults for a new site.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'locale' => 'vi_VN',
            'module_enablement' => ['core' => true, 'admin' => true, 'woocommerce' => true],
            'migration_status' => 'complete',
        ];
    }

    /**
     * Returns one setting value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    /**
     * Returns the complete safe configuration.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }
}
