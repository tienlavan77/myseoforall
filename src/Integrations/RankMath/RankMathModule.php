<?php
/*
 * Composes the existing Rank Math detector, read adapter, and ownership
 * registry with passive WordPress probe implementations. The module registers
 * no frontend or writer hooks and reports unknown when runtime evidence is not
 * sufficient to prove compatibility or ownership.
 */

declare(strict_types=1);

namespace MySeoForAll\Integrations\RankMath;

use MySeoForAll\Core\Container;
use MySeoForAll\Core\Contracts\Module;
use MySeoForAll\Core\HookDispatcher;

final class RankMathModule implements Module
{
    private Container $container;
    private HookDispatcher $dispatcher;

    /**
     * Inject the shared composition container and hook dispatcher.
     * Rank Math remains a pure read-only service boundary in this increment.
     * @param Container $container
     * @param HookDispatcher $dispatcher
     * @return void
     */
    public function __construct(Container $container, HookDispatcher $dispatcher)
    {
        $this->container = $container;
        $this->dispatcher = $dispatcher;
    }

    /**
     * Register detector, adapter, and ownership services with passive WordPress bridges.
     * The mapping and compatibility defaults intentionally remain zero-rule and fail closed.
     * @return void
     */
    public function register(): void
    {
        $matrix = RankMathCompatibilityMatrix::shippedDefault();
        $catalog = RankMathMappingCatalog::shippedDefault();
        $probe = new WordPressRankMathProbe();

        $this->container->set(RankMathCompatibilityMatrix::class, $matrix);
        $this->container->set(RankMathDetector::class, new RankMathDetector($matrix, $probe));
        $this->container->set(RankMathMappingCatalog::class, $catalog);
        $this->container->set(RankMathReadAdapter::class, new RankMathReadAdapter($catalog));
        $this->container->set(OwnershipRegistry::class, new OwnershipRegistry());
        $this->container->set(RankMathEnvironmentProbe::class, $probe);
        $this->container->set(RankMathSourceObserver::class, new WordPressRankMathSourceObserver());
    }

    /**
     * Keep the module hook-free because Rank Math runtime reads are requested explicitly by callers.
     * @return void
     */
    public function boot(): void
    {
    }

    /**
     * Report passive availability without claiming Rank Math is installed or compatible.
     * @return bool
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * Return safe compatibility and coexistence diagnostics with writing disabled.
     * @return array<string,mixed>
     */
    public function health(): array
    {
        $compatibility = 'unknown';
        $reason = 'runtime_unobserved';
        $dependencyReady = false;
        try {
            $decision = $this->container->get(RankMathDetector::class)->detect();
            $compatibility = in_array($decision['compatibility'] ?? null, ['compatible', 'incompatible', 'unknown'], true)
                ? $decision['compatibility'] : 'unknown';
            $reason = in_array($decision['reason'] ?? null, [
                'plugin_absent', 'installed_inactive', 'active_installation_metadata_missing',
                'matrix_unproven_for_range', 'version_unavailable', 'runtime_unobserved',
            ], true) ? $decision['reason'] : 'runtime_unavailable';
            $dependencyReady = $decision['installation'] === 'installed'
                && $decision['activation'] === 'active'
                && $decision['compatibility'] === 'compatible';
        } catch (\Throwable) {
            $reason = 'runtime_unavailable';
        }

        return [
            'name' => 'rank_math',
            'available' => $this->container->has(RankMathReadAdapter::class)
                && $this->container->has(OwnershipRegistry::class),
            'dependency_ready' => $dependencyReady,
            'message' => 'Rank Math chỉ được đọc thụ động; chưa chứng minh tương thích.',
            'compatibility' => $compatibility,
            'reason' => $reason,
            'write_allowed' => false,
            'frontend_owned' => false,
            'services_registered' => $this->container->has(RankMathReadAdapter::class)
                && $this->container->has(OwnershipRegistry::class),
        ];
    }
}
