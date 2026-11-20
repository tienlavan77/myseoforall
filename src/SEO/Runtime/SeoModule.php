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

    /**
     * Inject the composition container and request-scoped hook dispatcher.
     * Keeping both dependencies explicit lets tests inspect registration without WordPress.
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
        if (!function_exists('add_action')) {
            return;
        }
        $this->dispatcher->addAction('add_meta_boxes', [$this, 'registerDiagnosticsSurface']);
    }

    /**
     * Report whether the module can attach its optional diagnostics hook.
     * @return bool
     */
    public function isAvailable(): bool
    {
        return function_exists('add_action');
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
    public function registerDiagnosticsSurface(): void
    {
    }
}
