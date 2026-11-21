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

        $contentReader = new WordPressContentReader(null, $analyzer, $builder);
        $productReader = new WooCommerceProductReader(null, $analyzer, $builder);
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
        if (!(is_int($postId) || (is_string($postId) && ctype_digit($postId))) || (int) $postId <= 0) {
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
