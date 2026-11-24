<?php

/**
 * Injectable environment probe contract for Rank Math detector signals.
 *
 * Business purpose: Decouple Rank Math installation, activation, version, and capability
 * discovery from WordPress globals and runtime constants so detection stays offline,
 * deterministic, and safely testable even when Rank Math or WordPress is absent.
 * Technical description: Declares independent read-only probe methods returning structured
 * state, value, signal source, and evidence metadata without side effects.
 */

declare(strict_types=1);

namespace MySeoForAll\Integrations\RankMath;

/**
 * Provides independent observation probes for Rank Math installation, activation, version, and capabilities.
 *
 * Business purpose: Allow callers and tests to supply partial, missing, or conflicting probe
 * observations without requiring Rank Math classes or WordPress hooks.
 * Technical description: Each method returns a closed associative observation array rather than
 * booleans so missing and contradictory signals remain distinguishable.
 */
interface RankMathEnvironmentProbe
{
    /**
     * Observe Rank Math installation presence independently of activation.
     *
     * Business purpose: Distinguish an installed-but-inactive plugin from an absent or unverified installation.
     * Technical description: Returns state (`absent`, `installed`, `unknown` or `conflict`), signal_source,
     * evidence_state, and evidence_identity.
     *
     * @return array<string, mixed>
     */
    public function observeInstallation(): array;

    /**
     * Observe Rank Math activation status independently of installation metadata.
     *
     * Business purpose: Detect whether Rank Math is active without assuming installation metadata is complete.
     * Technical description: Returns state (`inactive`, `active`, `unknown` or `conflict`), signal_source,
     * evidence_state, and evidence_identity.
     *
     * @return array<string, mixed>
     */
    public function observeActivation(): array;

    /**
     * Observe Rank Math version metadata independently of compatibility.
     *
     * Business purpose: Capture raw version strings or report unavailable/conflicting version probes safely.
     * Technical description: Returns state (`present`, `unavailable`, `unknown` or `conflict`), raw version value,
     * signal_source, evidence_state, and evidence_identity.
     *
     * @return array<string, mixed>
     */
    public function observeVersion(): array;

    /**
     * Observe Rank Math read capabilities for compatibility evaluation.
     *
     * Business purpose: Verify whether specific read capabilities are available without assuming version implies support.
     * Technical description: Returns capabilities map (`array<string, mixed>`), signal_source, evidence_state,
     * and evidence_identity.
     *
     * @return array<string, mixed>
     */
    public function observeCapabilities(): array;
}
