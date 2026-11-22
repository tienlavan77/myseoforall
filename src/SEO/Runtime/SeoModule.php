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

final class SeoModule implements Module
{
    private Container $container;
    private HookDispatcher $dispatcher;
    private $capabilityChecker;
    private $wooAvailability;

    /**
     * Inject the composition container and request-scoped hook dispatcher.
     * Keeping both dependencies explicit lets tests inspect registration without WordPress.
     * @param Container $container
     * @param HookDispatcher $dispatcher
     * @return void
     */
    public function __construct(Container $container, HookDispatcher $dispatcher, $capabilityChecker = null, $wooAvailability = null)
    {
        $this->container = $container;
        $this->dispatcher = $dispatcher;
        $this->capabilityChecker = is_callable($capabilityChecker) ? $capabilityChecker : null;
        $this->wooAvailability = is_callable($wooAvailability) ? $wooAvailability : null;
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
        if (in_array($postType, ['post', 'page'], true) && $this->container->has(ContentSeoInspector::class)) {
            $this->container->get(ContentSeoInspector::class)->inspect($postId);
            return;
        }
        if (in_array($postType, ['product', 'product_variation'], true)
            && ($this->wooAvailability === null ? function_exists('wc_get_product') : ($this->wooAvailability)())
            && $this->container->has(ProductSeoInspector::class)) {
            $this->container->get(ProductSeoInspector::class)->inspect($postId);
        }
    }
}
