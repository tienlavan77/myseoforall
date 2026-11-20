# Increment D r3 — Engineering Report

## Snapshot

- implementation_snapshot_sha: `5aa3332133f203f41a8701e83b213c246cf491da`
- Branch: `codex/update-r3-report`
- Scope: Rank Math detector and ownership observation normalization only.
- Guardrails preserved: no runtime wiring, network, persistence, writer, Apply, publish, or ownership transfer.

## Changes

- D3-1: Rank Math cross-probe checks are independent. When installation is absent, simultaneous active activation, known version, and positive capability signals are each marked `conflict`; the stable top-level reason uses the documented priority.
- D3-2: `title_meta` observations missing `fields` now mark a structural error, forcing raw fingerprinting. Distinct malformed observations sharing an `observation_id` therefore resolve to `identity_collision` instead of merging.
- Added regression assertions for both cases.

## Verification

- `VERIFY-RUN-0dafd0d1-ca2a-4714-a828-ef75d6b2ded9` — `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit` — passed, exit code 0, directly on snapshot `5aa3332133f203f41a8701e83b213c246cf491da`.
- Earlier focused checks also passed:
  - `VERIFY-RUN-da96ae51-459d-4796-95ab-bb445e3c1e66` — RankMathDetectorContractTest.
  - `VERIFY-RUN-42b34776-4aca-4df6-b667-71dd4011946b` — OwnershipRegistryEdgeContractTest.

## Snapshot file hashes

### Source

- `src/Integrations/RankMath/OwnershipObservationNormalizer.php` — `sha256:5591931cae004ff14b4b3f7a903857ab58985f2a73648feb03259d0636fbd8e7`
- `src/Integrations/RankMath/OwnershipRegistry.php` — `sha256:22686cf991a3fed05a07725b550f330bc801b71cbdf0e8c1cc5d54680065a8c9`
- `src/Integrations/RankMath/RankMathCompatibilityMatrix.php` — `sha256:3ebeaa4280dda6947e53230745bc3ecfcd54c8164be4bcb1c311f39882f324d2`
- `src/Integrations/RankMath/RankMathDetector.php` — `sha256:430c07722ddca388f74affbe121287c059bd69b83169e7c4c01c9200c721fae7`
- `src/Integrations/RankMath/RankMathEnvironmentProbe.php` — `sha256:67ce76d12f50c6b219fc8e00b75a0ba9107f314ee3a1deac3f87a038c72a5a6d`
- `src/Integrations/RankMath/RankMathFixtureSource.php` — `sha256:5099e13287f6c8f81f16b3b9e667dfaa2100c408e93038e4166dbfaa49ad5294`
- `src/Integrations/RankMath/RankMathMappingCatalog.php` — `sha256:93d44750fc1989b3f8157664b79eaab65e9387f7aec0c9d1e7c3bc3f801a4116`
- `src/Integrations/RankMath/RankMathProbeNormalizer.php` — `sha256:cb1c7f3657aa65d6f46aa169124e38ae1255bdc7685eea0931b5e8e9f04ab39b`
- `src/Integrations/RankMath/RankMathReadAdapter.php` — `sha256:7b7e47ba316f62139df470f0728b47cfe28e4c8a30ab0e45f844a3d099b20992`
- `src/Integrations/RankMath/RankMathSourceObserver.php` — `sha256:b086a989f2d34794c0d6f3bf8d044ebc4f54d6e744197b3da3dd891fda8e1f1e`

### Tests

- `tests/Unit/OwnershipRegistryEdgeContractTest.php` — `sha256:4cbd99f688f47308d3eea8ba51d9dd2216784bbf4ff88acc8192344eb374120c`
- `tests/Unit/RankMathDetectorContractTest.php` — `sha256:f39679e8fd0dc634587b3fee3b62be6ab0f8c7a8ca2747b4b6a47ed9b0906af1`
- Other Unit files were unchanged in this snapshot; their current hashes are retained in the source evidence.

## Gate status

This report records offline contract behavior and verification only. `shippedDefault()` remains a zero-rule/unproven catalog. Increment C/D exit gates still require independent Reviewer review and Owner confirmation before Increment E.
