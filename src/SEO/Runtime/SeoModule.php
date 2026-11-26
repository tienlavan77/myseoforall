<?php
/*
 * Composes the SEO analyzer, typed provider boundary, and read-only content
 * inspectors behind the project container. Its only runtime hook is an admin
 * diagnostics surface and it never emits frontend SEO output or writes data.
 */

declare(strict_types=1);

namespace MySeoForAll\SEO\Runtime;

use MySeoForAll\AI\Contracts\AiOutputValidator;
use MySeoForAll\Core\Contracts\Module;
use MySeoForAll\Core\HookDispatcher;
use MySeoForAll\Core\Container;
use MySeoForAll\SEO\Analysis\LocalSeoAnalyzer;
use MySeoForAll\SEO\Context\ProviderPayloadBuilder;
use MySeoForAll\SEO\Runtime\ApplyController;
use MySeoForAll\SEO\Runtime\ApplyFingerprint;
use MySeoForAll\SEO\Runtime\ApplyToken;
use MySeoForAll\SEO\Runtime\CanonicalSeoMetaRepository;
use MySeoForAll\SEO\Runtime\WooCommerceDescriptionWriter;

final class SeoModule implements Module
{
    private Container $container;
    private HookDispatcher $dispatcher;
    private $capabilityChecker;
    private $wooAvailability;
    /** Optional registrar used to exercise metabox registration without WordPress. */
    private $metaBoxRegistrar;

    /**
     * Inject the composition container and request-scoped hook dispatcher.
     * Keeping both dependencies explicit lets tests inspect registration without WordPress.
     * @param Container $container
     * @param HookDispatcher $dispatcher
     * @return void
     */
    public function __construct(Container $container, HookDispatcher $dispatcher, $capabilityChecker = null, $wooAvailability = null, $metaBoxRegistrar = null)
    {
        $this->container = $container;
        $this->dispatcher = $dispatcher;
        $this->capabilityChecker = is_callable($capabilityChecker) ? $capabilityChecker : null;
        $this->wooAvailability = is_callable($wooAvailability) ? $wooAvailability : null;
        $this->metaBoxRegistrar = is_callable($metaBoxRegistrar) ? $metaBoxRegistrar : null;
    }

    /**
     * Register pure SEO services and both read-only projection inspectors.
     * Registration is deterministic and uses only stable fully qualified service identifiers.
     * @return void
     */
    public function register(): void
    {
        $builder = $this->container->has(ProviderPayloadBuilder::class)
            ? $this->container->get(ProviderPayloadBuilder::class)
            : new ProviderPayloadBuilder();
        $analyzer = $this->container->has(LocalSeoAnalyzer::class)
            ? $this->container->get(LocalSeoAnalyzer::class)
            : new LocalSeoAnalyzer();

        $this->container->set(ProviderPayloadBuilder::class, $builder);
        $this->container->set(AiOutputValidator::class, new AiOutputValidator());
        $this->container->set(LocalSeoAnalyzer::class, $analyzer);

        // Rank Math is registered after SEO; resolve its services lazily at read time.
        $seoOverrideResolver = function (int $objectId): array {
            $canonical = $this->container->has(CanonicalSeoMetaRepository::class) ? $this->container->get(CanonicalSeoMetaRepository::class)->snapshot($objectId) : [];
            $canonicalFields = [];
            foreach ($canonical as $field => $value) if (is_string($value) && $value !== '') $canonicalFields[$field] = ['state' => 'value', 'value' => $value, 'source' => 'myseo_for_all'];
            if ($canonicalFields !== []) return $canonicalFields;
            if (!$this->container->has(\MySeoForAll\Integrations\RankMath\RankMathReadAdapter::class)
                || !$this->container->has(\MySeoForAll\Integrations\RankMath\RankMathDetector::class)
                || !$this->container->has(\MySeoForAll\Integrations\RankMath\RankMathSourceObserver::class)) {
                return [];
            }
            $adapter = $this->container->get(\MySeoForAll\Integrations\RankMath\RankMathReadAdapter::class);
            $detector = $this->container->get(\MySeoForAll\Integrations\RankMath\RankMathDetector::class);
            $baseObserver = $this->container->get(\MySeoForAll\Integrations\RankMath\RankMathSourceObserver::class);
            $detected = $detector->detect();
            $version = $detected['version']['value'] ?? null;
            $version = is_string($version) && $version !== '' ? $version : null;
            $observer = new class($baseObserver, $objectId) implements \MySeoForAll\Integrations\RankMath\RankMathSourceObserver {
                private $observer;
                private int $objectId;
                /** Construct a scoped adapter that binds every mapped read to one positive post identity. */
                public function __construct($observer, int $objectId)
                {
                    $this->observer = $observer;
                    $this->objectId = $objectId;
                }
                /** Forward only allowlisted slots through the scoped source observer for this object. */
                public function observe(string $sourceId, string $slotKey): array
                {
                    return $this->observer->observe('post:' . $this->objectId, $slotKey);
                }
            };
            $snapshot = $adapter->readAll($observer, $version, ['title', 'meta_description', 'canonical']);
            return is_array($snapshot['fields'] ?? null) ? $snapshot['fields'] : [];
        };
        $contentReader = new WordPressContentReader(null, $analyzer, $builder, null, $seoOverrideResolver);
        $productReader = new WooCommerceProductReader(null, $analyzer, $builder, null, null, $seoOverrideResolver);
        $this->container->set(WordPressContentReader::class, $contentReader);
        $this->container->set(WooCommerceProductReader::class, $productReader);
        $this->container->set(ContentSeoInspector::class, new ContentSeoInspector($contentReader));
        $this->container->set(ProductSeoInspector::class, new ProductSeoInspector($productReader));
        $fingerprint = null;
        if (class_exists(ApplyFingerprint::class)) {
            $fingerprint = $this->container->has(ApplyFingerprint::class)
                ? $this->container->get(ApplyFingerprint::class)
                : new ApplyFingerprint();
            $this->container->set(ApplyFingerprint::class, $fingerprint);
        }
        if (!$this->container->has(CanonicalSeoMetaRepository::class)) {
            $this->container->set(CanonicalSeoMetaRepository::class, new CanonicalSeoMetaRepository());
        }
        if (!$this->container->has(WooCommerceDescriptionWriter::class)) {
            $this->container->set(WooCommerceDescriptionWriter::class, new WooCommerceDescriptionWriter());
        }
        if (defined('AUTH_KEY') && is_string(AUTH_KEY) && AUTH_KEY !== '' && !$this->container->has(ApplyToken::class)) {
            $this->container->set(ApplyToken::class, new ApplyToken(AUTH_KEY));
        }
        $applyToken = $this->container->has(ApplyToken::class) ? $this->container->get(ApplyToken::class) : null;
        $resolver = null;
        if ($applyToken !== null && $fingerprint !== null) {
            $meta = $this->container->get(CanonicalSeoMetaRepository::class);
            $description = $this->container->get(WooCommerceDescriptionWriter::class);
            $fingerprint = $this->container->get(ApplyFingerprint::class);
            $ownershipState = function (int $postId, string $resource, string $field): array {
                $detectorClass = \MySeoForAll\Integrations\RankMath\RankMathDetector::class;
                $registryClass = \MySeoForAll\Integrations\RankMath\OwnershipRegistry::class;
                $detected = $this->container->has($detectorClass) ? $this->container->get($detectorClass)->detect() : [];
                $resolved = $this->container->has($registryClass) ? $this->container->get($registryClass)->resolve() : [];
                $decision = is_array($resolved) ? ($resolved['outputs']['title_meta'] ?? []) : [];
                $activation = is_array($detected) && is_array($detected['activation'] ?? null)
                    ? ($detected['activation']['state'] ?? null)
                    : ($detected['activation'] ?? null);
                $owner = is_string($decision['owner'] ?? null) ? $decision['owner'] : 'unknown';
                $state = is_string($decision['state'] ?? null) ? $decision['state'] : 'unknown';
                return [
                    'active' => $activation === 'active',
                    'owner' => $owner,
                    'state' => $state,
                    'requires_confirmation' => $activation === 'active' && $owner === 'rank_math' && $state === 'owned',
                ];
            };
            $resolver = function (int $postId, string $resource, string $field, string $task = '') use ($meta, $description, $ownershipState): array {
                if (!in_array($resource, ['article', 'page', 'product'], true)) return ['status' => 'unsupported_resource'];
                if (($resource === 'product') !== ($field === 'description')) return ['status' => 'unsupported_field'];
                if ($resource !== 'product' && !in_array($field, ['title', 'meta_description', 'canonical'], true)) return ['status' => 'unsupported_field'];
                if ($resource !== 'product' && $task === 'product_description_draft') return ['status' => 'unsupported_task'];
                if ($resource === 'product' && $task !== '' && $task !== 'product_description_draft') return ['status' => 'unsupported_task'];
                if ($resource !== 'product' && $task !== '' && $task !== 'title_meta_suggestion') return ['status' => 'unsupported_task'];
                $postType = $resource === 'article' ? 'post' : $resource;
                if (function_exists('get_post_type')) {
                    $actual = get_post_type($postId);
                    if (!is_string($actual) || $actual !== $postType) return ['status' => 'not_found'];
                }
                $value = $resource === 'product' ? $description->read($postId) : $meta->read($postId, $field);
                $ownership = $ownershipState($postId, $resource, $field);
                return [
                    'status' => 'ok',
                    'snapshot' => [
                        'version' => 1,
                        'post_id' => $postId,
                        'resource_type' => $resource,
                        'task' => $task !== '' ? $task : ($resource === 'product' ? 'product_description_draft' : 'title_meta_suggestion'),
                        'field' => $field,
                        'value' => is_string($value) ? trim(str_replace(["\r\n", "\r"], "\n", $value)) : null,
                        'ownership_state' => $ownership,
                    ],
                ];
            };
            $applyOwnership = function (int $postId, string $resource, string $field, string $fingerprint, array $snapshot = []) use ($ownershipState): array {
                $state = $ownershipState($postId, $resource, $field);
                return ['owned' => ($state['requires_confirmation'] ?? false) === true, 'confirmation_required' => ($state['requires_confirmation'] ?? false) === true];
            };
            $this->container->set(ApplyController::class, new ApplyController($this->container->get(ApplyToken::class), $fingerprint, $resolver, $this->capabilityChecker, null, $applyOwnership, $meta, $description));
        }
        if ($this->container->has(\MySeoForAll\AI\Contracts\AiProvider::class)
            && $this->container->has(\MySeoForAll\AI\AiSettingsRepository::class)) {
            if (!class_exists(EditorPreviewController::class, false)) {
                $controllerFile = __DIR__ . '/EditorPreviewController.php';
                if (!is_file($controllerFile) || !is_readable($controllerFile)) {
                    return;
                }
                require_once $controllerFile;
                if (!class_exists(EditorPreviewController::class, false)) {
                    return;
                }
            }
            $this->container->set(
                EditorPreviewController::class,
                new EditorPreviewController(
                    $this->container->get(\MySeoForAll\AI\Contracts\AiProvider::class),
                    $this->container->get(\MySeoForAll\AI\AiSettingsRepository::class),
                    $this->container->get(ContentSeoInspector::class),
                    $this->container->get(ProductSeoInspector::class),
                    $this->capabilityChecker,
                    null,
                    $this->wooAvailability,
                    null,
                    function (): array {
                        $detector = \MySeoForAll\Integrations\RankMath\RankMathDetector::class;
                        $registry = \MySeoForAll\Integrations\RankMath\OwnershipRegistry::class;
                        $detected = $this->container->has($detector) ? $this->container->get($detector)->detect() : [];
                        $snapshot = $this->container->has($registry) ? $this->container->get($registry)->resolve() : [];
                        $decision = $snapshot['outputs']['title_meta'] ?? [];
                        return [
                            'rank_math_active' => ($detected['activation'] ?? null) === 'active',
                            'owner' => $decision['owner'] ?? null,
                            'state' => $decision['state'] ?? null,
                        ];
                    },
                    null,
                    $applyToken,
                    $fingerprint,
                    $resolver
                )
            );
        }
    }

    /**
     * Register only the bounded admin diagnostics hook when WordPress exposes hook APIs.
     * @return void
     */
    public function boot(): void
    {
        if (!$this->dispatcher->canDispatch()) {
            return;
        }
        $this->dispatcher->addAction('add_meta_boxes', [$this, 'registerDiagnosticsSurface'], 10, 2);
        if ($this->container->has(EditorPreviewController::class)) {
            $this->dispatcher->addAction('wp_ajax_myseo_ai_preview', [$this->container->get(EditorPreviewController::class), 'handleWordPressRequest'], 10, 1);
        }
        if ($this->container->has(ApplyController::class)) {
            $this->dispatcher->addAction('wp_ajax_myseo_ai_apply', [$this->container->get(ApplyController::class), 'handleWordPressRequest'], 10, 1);
        }
    }

    /**
     * Report whether the module can attach its optional diagnostics hook.
     * @return bool
     */
    public function isAvailable(): bool
    {
        return $this->dispatcher->canDispatch();
    }

    /**
     * Return a serializable health record with the write boundary permanently disabled.
     * @return array<string,mixed>
     */
    public function health(): array
    {
        return [
            'name' => 'seo',
            'available' => $this->isAvailable(),
            'message' => $this->isAvailable() ? 'SEO Core và adapter đọc sẵn sàng.' : 'WordPress hook API chưa sẵn sàng.',
            'write_allowed' => false,
            'frontend_owned' => false,
            'services_registered' => $this->container->has(ProviderPayloadBuilder::class)
                && $this->container->has(ContentSeoInspector::class)
                && $this->container->has(ProductSeoInspector::class),
        ];
    }

    /**
     * Provide a named diagnostics listener without rendering or mutating platform content.
     * @return void
     */
    public function registerDiagnosticsSurface($postType = '', $post = null): void
    {
        $registrar = $this->metaBoxRegistrar ?? (function_exists('add_meta_box') ? 'add_meta_box' : null);
        $editorial = in_array($postType, ['post', 'page'], true);
        if ($registrar !== null) {
            if ((!$editorial && ($postType !== 'product' || !$this->wooAvailable()))
                || (is_object($post) && ($post->post_type ?? null) !== $postType)
                || !$this->container->has(EditorPreviewController::class)) {
                return;
            }
            $registrar(
                'myseo-for-all-editor-metabox',
                'My SEO For All — Trợ lý SEO & AI',
                [$this->container->get(EditorPreviewController::class), 'renderMetabox'],
                $postType,
                'normal',
                'high'
            );
            return;
        }
        if (!is_string($postType) || $postType === '' || !is_object($post) || !isset($post->ID)) {
            return;
        }
        $postId = $post->ID;
        if (!(is_int($postId) || (is_string($postId) && ctype_digit($postId)))
            || (int) $postId <= 0
            || (is_string($postId) && (string) (int) $postId !== $postId)) {
            return;
        }
        $postId = (int) $postId;
        if (isset($post->post_type) && $post->post_type !== $postType) {
            return;
        }
        $allowed = is_callable($this->capabilityChecker)
            ? ($this->capabilityChecker)('edit_post', $postId)
            : (function_exists('current_user_can') && current_user_can('edit_post', $postId));
        if ($allowed !== true) {
            return;
        }
        if ($editorial && $this->container->has(ContentSeoInspector::class)) {
            $this->container->get(ContentSeoInspector::class)->inspect($postId);
        } elseif (in_array($postType, ['product', 'product_variation'], true)
            && $this->wooAvailable() && $this->container->has(ProductSeoInspector::class)) {
            $this->container->get(ProductSeoInspector::class)->inspect($postId);
        }
    }

    /** Check whether commerce diagnostics can read WooCommerce products in this request. */
    private function wooAvailable(): bool
    {
        return $this->wooAvailability === null ? function_exists('wc_get_product') : ($this->wooAvailability)() === true;
    }
}
