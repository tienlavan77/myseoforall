# Increment 3B — Implementation Report

## Scope

Increment 3B implements controlled Apply for reviewed AI proposals after Increment 3A acceptance. The implementation is limited to authenticated AJAX Apply, field-scoped canonical persistence, optimistic concurrency, signed proposal claims, ownership gating, and read-back confirmation.

`myseoforall.php` was not changed.

## Implemented

- Added `ApplyController` for ordered method, ID, capability, nonce, resource, field, proposal, token, fingerprint, ownership, persistence, and read-back gates.
- Added `ApplyToken` using a deployment-provided `AUTH_KEY`; no fallback secret is used.
- Added deterministic `ApplyFingerprint`.
- Added `CanonicalSeoMetaRepository` for the allowlisted SEO fields:
  - `_myseo_title`
  - `_myseo_meta_description`
  - `_myseo_canonical`
- Added `WooCommerceDescriptionWriter`, restricted to product `get_description()`, `set_description()`, and `save()`.
- Extended Preview responses with `source_fingerprint` and `apply_token` when Apply services are available.
- Wired Preview and Apply services in `SeoModule`.
- Registered only `wp_ajax_myseo_ai_apply`; no `nopriv` hook was added.

## Changed files

- `src/SEO/Runtime/ApplyController.php`
- `src/SEO/Runtime/ApplyFingerprint.php`
- `src/SEO/Runtime/ApplyToken.php`
- `src/SEO/Runtime/CanonicalSeoMetaRepository.php`
- `src/SEO/Runtime/WooCommerceDescriptionWriter.php`
- `src/SEO/Runtime/EditorPreviewController.php`
- `src/SEO/Runtime/SeoModule.php`

## Source snapshot

- `ApplyController.php` — `sha256:e4e1674d0bc0f2ffecc63a90d1a097912207e2d8d3602ccbbb474eee24e77195`
- `ApplyFingerprint.php` — `sha256:a05fcaafd6f70071e866f864a093dd06fc878b5b51580f3ee1ff88cc61ea9d4f`
- `ApplyToken.php` — `sha256:a4e85df1e5e07e55dec6558bf338c016f3a3b2d9436ef45067853930649539c6`
- `CanonicalSeoMetaRepository.php` — `sha256:62c1d5ef69cb6eb702d7933062e3e2283befe0ce93937898dbe62fab72864108`
- `WooCommerceDescriptionWriter.php` — `sha256:d62cf44c1d3ad2da9b620c8de7633098a7effad9dd1052277ce924555871869e`
- `EditorPreviewController.php` — current edited source
- `SeoModule.php` — current edited source

## Verification

- `VERIFY-RUN-1a03a360-77f6-4cdb-879b-3eecfeb891e5` — `php -l src/SEO/Runtime/SeoModule.php` passed.
- `VERIFY-RUN-2ce60b45-5efc-4f3f-a04c-92c33965f75f` — `php -l src/SEO/Runtime/EditorPreviewController.php` passed.
- `VERIFY-RUN-e013ba3e-7213-4d54-878b-3adf15bc9923` — `php vendor/bin/phpunit tests/Unit/Increment3ATest.php` passed.

No focused Increment 3B PHPUnit file was present in this execution, so the 3B behavior has not been represented as a passed automated test suite.

## Limitations

Offline checks do not prove WordPress/WooCommerce runtime acceptance. Runtime validation still needs the platform integration environment, including real nonce/capability behavior, WordPress meta persistence, WooCommerce product read-back, and hook dispatch.
