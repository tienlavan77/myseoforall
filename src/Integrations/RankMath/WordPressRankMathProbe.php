<?php
/*
 * Bridges passive WordPress signals into the Rank Math environment probe
 * without loading plugin files, calling network APIs, or inferring ownership.
 * Every observation includes explicit evidence metadata and remains unknown
 * when the hosting WordPress APIs cannot confirm a fact.
 */

declare(strict_types=1);

namespace MySeoForAll\Integrations\RankMath;

final class WordPressRankMathProbe implements RankMathEnvironmentProbe
{
    /**
     * Observe whether Rank Math appears installed from active plugin or loaded metadata.
     * Missing WordPress APIs produce an unobserved unknown result rather than an absent claim.
     * @return array<string,mixed>
     */
    public function observeInstallation(): array
    {
        $active = $this->activePlugins();
        if ($active === null) {
            return $this->unknown('installation_unavailable');
        }
        if ($this->containsRankMath($active)) {
            return $this->signal('installed', 'active_plugins');
        }

        if (function_exists('get_plugins')) {
            $plugins = get_plugins();
            if (is_array($plugins)) {
                foreach (array_keys($plugins) as $plugin) {
                    if ($this->isRankMathPlugin((string) $plugin)) {
                        return $this->signal('installed', 'plugin_metadata');
                    }
                }
                return $this->signal('absent', 'plugin_metadata');
            }
        }

        return $this->unknown('installation_metadata_unavailable');
    }

    /**
     * Observe activation independently from installation metadata.
     * Only an active plugin entry is treated as positive evidence.
     * @return array<string,mixed>
     */
    public function observeActivation(): array
    {
        $active = $this->activePlugins();
        if ($active === null) {
            return $this->unknown('activation_unavailable', 'inactive');
        }

        return $this->signal($this->containsRankMath($active) ? 'active' : 'inactive', 'active_plugins');
    }

    /**
     * Observe the loaded Rank Math version without treating it as compatibility proof.
     * @return array<string,mixed>
     */
    public function observeVersion(): array
    {
        if (defined('RANK_MATH_VERSION')) {
            return [
                'state' => 'present',
                'value' => (string) constant('RANK_MATH_VERSION'),
                'signal_source' => 'RANK_MATH_VERSION',
                'evidence_state' => 'observed',
                'evidence_identity' => 'wordpress:rank_math_version',
            ];
        }

        return [
            'state' => 'unavailable',
            'value' => null,
            'signal_source' => 'wordpress',
            'evidence_state' => function_exists('get_option') ? 'missing' : 'unobserved',
            'evidence_identity' => 'wordpress:rank_math_version',
        ];
    }

    /**
     * Report only allowlisted read capabilities and never infer support from a version.
     * @return array<string,mixed>
     */
    public function observeCapabilities(): array
    {
        $state = function_exists('get_option') ? 'unknown' : 'unknown';
        return [
            'capabilities' => [
                'read_title' => $state,
                'read_meta_description' => $state,
                'read_canonical' => $state,
            ],
            'signal_source' => 'wordpress',
            'evidence_state' => function_exists('get_option') ? 'unobserved' : 'unobserved',
            'evidence_identity' => 'wordpress:rank_math_capabilities',
        ];
    }

    /**
     * Read active plugin names only when WordPress exposes the option API.
     * @return list<string>|null
     */
    private function activePlugins(): ?array
    {
        if (!function_exists('get_option')) {
            return null;
        }
        $value = get_option('active_plugins', []);
        if (!is_array($value)) {
            return null;
        }
        $plugins = array_values(array_filter($value, 'is_string'));
        if (count($plugins) !== count($value)) {
            return null;
        }
        return $plugins;
    }

    /**
     * Return an unknown probe record with a safe reason.
     * @param string $reason
     * @param string $activation
     * @return array<string,mixed>
     */
    private function unknown(string $reason, string $activation = 'unknown'): array
    {
        return [
            'state' => $activation === 'inactive' ? 'unknown' : 'unknown',
            'signal_source' => 'wordpress',
            'evidence_state' => 'unobserved',
            'evidence_identity' => 'wordpress:' . $reason,
        ];
    }

    /**
     * Return a normalized binary signal record with a stable evidence identity.
     * @param string $state
     * @param string $source
     * @return array<string,mixed>
     */
    private function signal(string $state, string $source): array
    {
        return [
            'state' => $state,
            'signal_source' => $source,
            'evidence_state' => 'observed',
            'evidence_identity' => 'wordpress:rank_math:' . $source,
        ];
    }

    /**
     * Check plugin slugs without reading plugin payloads.
     * @param list<string> $plugins
     * @return bool
     */
    private function containsRankMath(array $plugins): bool
    {
        foreach ($plugins as $plugin) {
            if ($this->isRankMathPlugin($plugin)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Match only known Rank Math plugin path/name tokens.
     * @param string $plugin
     * @return bool
     */
    private function isRankMathPlugin(string $plugin): bool
    {
        return strtolower(trim($plugin)) === 'seo-by-rank-math/rank-math.php';
    }
}
