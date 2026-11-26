<?php
/**
 * Exercise the editor preview acceptance matrix offline, using real readers
 * and observable seams for platform access, the AI provider, and option storage.
 */
declare(strict_types=1);

namespace MySeoForAll\Tests\Unit;

use MySeoForAll\AI\AiSettingsRepository;
use MySeoForAll\AI\Contracts\AiProvider;
use MySeoForAll\AI\Contracts\AiProviderException;
use MySeoForAll\Core\Container;
use MySeoForAll\Core\HookDispatcher;
use MySeoForAll\Infrastructure\WordPress\OptionStore;
use MySeoForAll\SEO\Runtime\ContentSeoInspector;
use MySeoForAll\SEO\Runtime\EditorPreviewController;
use MySeoForAll\SEO\Runtime\ProductSeoInspector;
use MySeoForAll\SEO\Runtime\SeoModule;
use MySeoForAll\SEO\Runtime\WordPressContentReader;
use MySeoForAll\SEO\Runtime\WooCommerceProductReader;
use PHPUnit\Framework\TestCase;

final class Increment3ATest extends TestCase
{
    private const ZERO_WRITES = ['writes' => 0, 'deletes' => 0, 'cas' => 0];
    private array $mutations = self::ZERO_WRITES;
    private array $options = [];
    private array $reads = [];
    private array $requests = [];
    private array $gates = [];
    private bool $woo = true;
    private bool $editable = true;
    private bool $rankMathActive = false;
    private string $owner = 'rank_math';
    private ?string $category = null;

    /** Check option mutations after every scenario, including failed previews. */
    protected function tearDown(): void
    {
        self::assertSame(self::ZERO_WRITES, $this->mutations);
    }

    /** Verify metabox registration is passive and variation inspection remains available offline. */
    public function testRegistrationAndOfflineVariationFallback(): void
    {
        [$controller, $content, $products] = $this->fixture();
        $container = new Container();
        $container->set(EditorPreviewController::class, $controller);
        $container->set(ContentSeoInspector::class, $content);
        $container->set(ProductSeoInspector::class, $products);
        $registered = [];
        $capability = static fn (string $capability, int $id): bool => $capability === 'edit_post' && $id === 4;
        $woo = fn (): bool => $this->woo;
        $module = new SeoModule($container, new HookDispatcher(), $capability, $woo,
            static function (string $id, string $title, callable $callback, string $type) use (&$registered): void {
                $registered[] = [$id, $callback, $type];
            }
        );
        $module->registerDiagnosticsSurface('post', null);
        $module->registerDiagnosticsSurface('page', (object) ['ID' => 0, 'post_type' => 'page']);
        $module->registerDiagnosticsSurface('product', (object) ['ID' => 3, 'post_type' => 'product']);
        $module->registerDiagnosticsSurface('product_variation', (object) ['ID' => 4, 'post_type' => 'product_variation']);
        $module->registerDiagnosticsSurface('post', (object) ['ID' => 2, 'post_type' => 'page']);
        $this->woo = false;
        $module->registerDiagnosticsSurface('product', null);
        self::assertSame(array_map(
            static fn (string $type): array => ['myseo-for-all-editor-metabox', [$controller, 'renderMetabox'], $type],
            ['post', 'page', 'product']
        ), $registered);
        self::assertSame([], $this->reads);

        self::assertFalse(function_exists('add_meta_box'), 'The diagnostics fallback requires the offline test runtime.');
        $offline = new SeoModule($container, new HookDispatcher(), $capability, $woo);
        $variation = (object) ['ID' => 4, 'post_type' => 'product_variation'];
        $offline->registerDiagnosticsSurface('product_variation', $variation);
        self::assertSame([], $this->reads);
        $this->woo = true;
        $offline->registerDiagnosticsSurface('product_variation', (object) ['ID' => 0, 'post_type' => 'product_variation']);
        $offline->registerDiagnosticsSurface('product_variation', (object) ['ID' => 9, 'post_type' => 'product_variation']);
        self::assertSame([], $this->reads);
        $offline->registerDiagnosticsSurface('product_variation', $variation);
        self::assertSame([['product', 4]], $this->reads);
        self::assertSame([], $this->requests);
    }

    /** Verify render gates avoid inspection and disabled AI still shows local findings. */
    public function testRenderGatesAndDisabledState(): void
    {
        [$controller] = $this->fixture();
        foreach ([[], ['ID' => 0], ['ID' => '12abc']] as $object) {
            self::assertStringContainsString('Hãy lưu bản nháp', $this->render($controller, (object) ($object + ['post_type' => 'post'])));
        }
        self::assertSame('', $this->render($controller, (object) ['ID' => 1, 'post_type' => 'attachment']));
        $this->woo = false;
        self::assertSame('', $this->render($controller, (object) ['ID' => 3, 'post_type' => 'product']));
        $this->editable = false;
        self::assertSame('', $this->render($controller, (object) ['ID' => 1, 'post_type' => 'post']));
        self::assertSame([], $this->reads);

        $this->editable = true;
        $html = $this->render($controller, (object) ['ID' => 1, 'post_type' => 'post']);
        self::assertStringContainsString('options-general.php?page=myseo-for-all', $html);
        self::assertStringNotContainsString('<script>', $html);
        foreach (['Cần xử lý ngay: 0', 'Cảnh báo: 3', 'Gợi ý: 2', 'Thiếu meta description.'] as $finding) {
            self::assertStringContainsString($finding, $html);
        }
        self::assertSame([['content', 1]], $this->reads);
        self::assertSame([], $this->requests);
    }

    /** Verify enabled AI discloses field names, renders ownership safely, and stays passive. */
    public function testEnabledMetaboxDisclosureAndActions(): void
    {
        [$controller] = $this->fixture(true);
        $this->rankMathActive = true;
        $article = $this->render($controller, (object) ['ID' => 1, 'post_type' => 'post']);
        self::assertStringContainsString('Rank Math đang hoạt động', $article);
        $this->rankMathActive = false;
        $page = $this->render($controller, (object) ['ID' => 2, 'post_type' => 'page']);
        self::assertStringContainsString('title_meta: rank_math / observed.', $page);
        $this->owner = '<script>alert(1)</script>';
        $product = $this->render($controller, (object) ['ID' => 3, 'post_type' => 'product']);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $product);
        self::assertStringNotContainsString($this->owner, $product);
        self::assertStringContainsString('Chỉ gửi các trường công khai:', $product);
        self::assertStringContainsString('data-task="product_description_draft"', $product);

        foreach ([$article, $page, $product] as $html) {
            self::assertStringContainsString('Chỉ gửi các trường công khai:', $html);
            self::assertStringNotContainsString('công khai: 0, 1', $html);
            self::assertStringContainsString($html === $product ? 'product_name' : 'title', $html);
            self::assertStringContainsString($html === $product ? 'description' : 'content', $html);
            self::assertStringContainsString('data-task="seo_analysis"', $html);
            self::assertStringContainsString('data-task="title_meta_suggestion"', $html);
            self::assertStringContainsString('textContent', $html);
            self::assertStringNotContainsString('.innerHTML', $html);
        }
        self::assertStringNotContainsString('data-task="product_description_draft"', $article);
        self::assertStringNotContainsString('data-task="product_description_draft"', $page);
        self::assertStringContainsString('data-task="product_description_draft"', $product);
        self::assertSame([], $this->requests);
    }

    /** Verify error status, gate order, and absence of downstream calls for rejected requests. */
    public function testPreviewGateMatrix(): void
    {
        [$controller] = $this->fixture();
        $base = $this->request();
        foreach ([
            ['not an array', 400, 'malformed_request', [], true, true],
            [['_method' => 'GET'] + $base, 400, 'malformed_request', [], true, true],
            [['post_id' => 0] + $base, 400, 'invalid_post_id', [], true, true],
            [['post_id' => -5] + $base, 400, 'invalid_post_id', [], true, true],
            [['post_id' => '12abc'] + $base, 400, 'invalid_post_id', [], true, true],
            [['_ajax_nonce' => 'wrong'] + $base, 403, 'forbidden', ['edit'], false, true],
            [array_diff_key($base, ['_ajax_nonce' => true]), 403, 'invalid_nonce', ['edit'], true, true],
            [['_ajax_nonce' => 'wrong', 'task' => 'unknown'] + $base, 403, 'invalid_nonce', ['edit', 'nonce'], true, true],
            [array_diff_key($base, ['task' => true]), 400, 'malformed_request', ['edit', 'nonce'], true, true],
            [['task' => ''] + $base, 400, 'malformed_request', ['edit', 'nonce'], true, true],
            [['task' => []] + $base, 400, 'malformed_request', ['edit', 'nonce'], true, true],
            [['task' => 'unknown', 'post_id' => 99] + $base, 400, 'unsupported_task', ['edit', 'nonce'], true, true],
            [$this->request(99), 404, 'not_found', ['edit', 'nonce', 'type'], true, true],
            [$this->request(4), 400, 'unsupported_resource', ['edit', 'nonce', 'type'], true, true],
            [$this->request(3), 400, 'unsupported_resource', ['edit', 'nonce', 'type', 'woo'], true, false],
            [$this->request(1, 'product_description_draft'), 400, 'unsupported_task_for_resource', ['edit', 'nonce', 'type'], true, true],
            [$this->request(2, 'product_description_draft'), 400, 'unsupported_task_for_resource', ['edit', 'nonce', 'type'], true, true],
        ] as [$request, $status, $code, $trace, $editable, $woo]) {
            $this->editable = $editable;
            $this->woo = $woo;
            $this->gates = [];
            $this->assertError($controller->handlePreviewRequest($request), $status, $code);
            self::assertSame($trace, $this->gates, $code);
            self::assertSame([], $this->reads, $code);
            self::assertSame([], $this->requests, $code);
        }
        $this->gates = [];
        $this->assertError($controller->handlePreviewRequest($this->request(5)), 422, 'payload_rejected');
        self::assertSame(['edit', 'nonce', 'type'], $this->gates);
        self::assertSame([['content', 5]], $this->reads);
        self::assertSame([], $this->requests);
    }

    /** Verify every supported task and resource pairing preserves the closed preview contract. */
    public function testSuccessfulTasksAndCurrentNormalization(): void
    {
        [$controller] = $this->fixture(true);
        $reflection = new \ReflectionMethod($controller, 'currentValues');
        $reflection->setAccessible(true);
        $current = $reflection->invoke($controller, ['description' => str_repeat('é', 2100)], 'product')['description'];
        self::assertSame(2000, preg_match_all('/./us', $current));
        $resources = [1 => 'article', 2 => 'page', 3 => 'product'];
        foreach ($resources as $id => $resource) {
            $tasks = ['seo_analysis', 'title_meta_suggestion'];
            if ($resource === 'product') {
                $tasks[] = 'product_description_draft';
            }
            foreach ($tasks as $task) {
                $result = $controller->handlePreviewRequest($this->request($id, $task));
                self::assertSame(200, $result['http_status'], "$resource/$task: " . json_encode($result['body']));
                self::assertSame(['http_status', 'body'], array_keys($result));
                self::assertSame([
                    'status' => 'ok', 'post_id' => $id, 'resource_type' => $resource, 'task' => $task,
                    'current' => match ($resource) {
                        'article' => ['title' => 'Title', 'meta_description' => null],
                        'page' => ['title' => 'Title', 'meta_description' => null],
                        'product' => ['title' => 'Product', 'meta_description' => null, 'description' => str_repeat('é', 1800)],
                    },
                    'proposal' => self::proposal($task),
                    'read_only' => true,
                ], $result['body']);
                $sent = $this->requests[array_key_last($this->requests)];
                self::assertSame(['schema_version', 'task', 'context'], array_keys($sent));
                self::assertSame('myseo.ai.request.v2', $sent['schema_version']);
                self::assertSame($task, $sent['task']);
                if ($resource === 'product') {
                    self::assertSame(str_repeat('é', 1800), $sent['context']['description']);
                    self::assertSame('Short summary', $sent['context']['short_description']);
                }
            }
        }
        self::assertCount(7, $this->requests);
    }

    /** Verify all eight provider categories return distinct Vietnamese messages without leaking exception text. */
    public function testProviderCategoryMatrixIsClosedAndRedacted(): void
    {
        [$controller] = $this->fixture(true);
        $cases = [
            AiProviderException::MALFORMED_REQUEST => [400, 'Yêu cầu gửi tới trợ lý AI không đúng định dạng hợp lệ.'],
            AiProviderException::UNSUPPORTED_TASK => [400, 'Tác vụ AI được yêu cầu không nằm trong danh mục hỗ trợ.'],
            AiProviderException::INVALID_RESPONSE => [502, 'Phản hồi từ AI Gateway không đạt chuẩn kiểm định an toàn nên đã bị từ chối.'],
            AiProviderException::PROVIDER_DISABLED => [409, 'Trợ lý AI đang tắt hoặc chưa cấu hình đầy đủ trong trang Cài đặt.'],
            AiProviderException::AUTHENTICATION_FAILED => [502, 'Xác thực với AI Gateway thất bại; vui lòng kiểm tra lại API key.'],
            AiProviderException::GATEWAY_POLICY_REJECTED => [502, 'Địa chỉ AI Gateway bị từ chối bởi chính sách bảo mật mạng.'],
            AiProviderException::RATE_LIMITED => [429, 'AI Gateway đang giới hạn tần suất yêu cầu; vui lòng thử lại sau ít phút.'],
            AiProviderException::TRANSPORT_FAILED => [502, 'Không thể kết nối tới AI Gateway hoặc yêu cầu đã hết thời gian chờ.'],
        ];
        $messages = [];
        foreach ($cases as $category => [$status, $message]) {
            $this->category = $category;
            $result = $controller->handlePreviewRequest($this->request());
            $this->assertError($result, $status, $category);
            self::assertSame($message, $result['body']['message']);
            self::assertStringNotContainsString('SECRET_LEAK_MARKER', json_encode($result, JSON_THROW_ON_ERROR));
            $messages[] = $result['body']['message'];
        }
        self::assertCount(8, array_unique($messages));
        self::assertCount(8, $this->requests);
    }

    /** Run render and preview against the same configured option spy after resetting setup writes. */
    public function testConfiguredRenderAndPreviewDoNotMutateOptions(): void
    {
        [$controller] = $this->fixture(true);
        $before = $this->options;
        self::assertStringContainsString('data-task="seo_analysis"', $this->render($controller, (object) ['ID' => 1, 'post_type' => 'post']));
        self::assertSame([], $this->requests);
        self::assertSame(self::ZERO_WRITES, $this->mutations);
        self::assertSame(200, $controller->handlePreviewRequest($this->request())['http_status']);
        self::assertCount(1, $this->requests);
        self::assertSame(self::ZERO_WRITES, $this->mutations);
        self::assertSame($before, $this->options);
    }

    /** Build a real controller and inspectors with deterministic offline seams and option counters. */
    private function fixture(bool $enabled = false): array
    {
        $this->editable = true;
        $this->woo = true;
        $this->rankMathActive = false;
        $this->owner = 'rank_math';
        $this->category = null;
        $this->gates = [];
        $this->reads = [];
        $this->requests = [];
        $this->mutations = self::ZERO_WRITES;
        $store = new OptionStore(
            fn (string $key, mixed $default): mixed => $this->options[$key] ?? $default,
            function (string $key, mixed $value): bool {
                $this->mutations['writes']++;
                $this->options[$key] = $value;
                return true;
            },
            function (string $key): bool {
                $this->mutations['deletes']++;
                unset($this->options[$key]);
                return true;
            },
            function (): bool {
                $this->mutations['cas']++;
                return false;
            }
        );
        $settings = new AiSettingsRepository($store, str_repeat('e', 32));
        if ($enabled) {
            self::assertSame('saved', $settings->save([
                'ai_enabled' => true, 'base_url' => 'https://gw.example.com',
                'default_model' => 'model-1', 'allowed_models' => ['model-1'],
            ], 0, str_repeat('k', 16))['status']);
            self::assertSame('configured', $settings->credentialStatus()['status']);
        }
        $this->mutations = self::ZERO_WRITES;
        $content = new ContentSeoInspector(new WordPressContentReader(function (int $id): array {
            $this->reads[] = ['content', $id];
            return [
                'id' => $id, 'post_type' => $id === 2 ? 'page' : 'post', 'title' => 'Title',
                'content' => $id === 5 ? str_repeat('x', 50000) : 'Body',
            ] + ($id === 2 ? ['meta_description' => ''] : []);
        }));
        $products = new ProductSeoInspector(new WooCommerceProductReader(function (int $id): array {
            $this->reads[] = ['product', $id];
            return [
                'id' => $id, 'resource_type' => $id === 4 ? 'variation' : 'product',
                'product_name' => 'Product', 'description' => str_repeat('é', 1800),
                'seo' => ['title' => 'Product', 'canonical' => 'https://example.com/product'],
                'url' => 'https://example.com/product',
                'sku' => 'SKU-1', 'price' => '12.50', 'stock' => 1,
                'short_description' => 'Short summary',
            ] + ($id === 4 ? ['parent_id' => 3] : []);
        }, null, null, static fn (int $id): bool => $id === 3));
        $provider = new class(function (array $request): array {
            $this->requests[] = $request;
            if ($this->category !== null) {
                throw new AiProviderException($this->category, 'SECRET_LEAK_MARKER');
            }
            return [
                'schema_version' => 'myseo.ai.response.v1', 'provider' => 'test',
                'task' => $request['task'], 'status' => 'ok', 'output' => self::proposal($request['task']),
            ];
        }) implements AiProvider {
            /** Use the supplied callback to record requests and return fixture output. */
            public function __construct(private \Closure $generate) {}
            /** Identify the fake provider in the validated response envelope. */
            public function name(): string { return 'test'; }
            /** Delegate to the offline fixture so every attempt remains observable. */
            public function generate(array $request): array { return ($this->generate)($request); }
        };
        return [new EditorPreviewController(
            $provider, $settings, $content, $products,
            function (string $capability, int $id): bool {
                $this->gates[] = 'edit';
                return $this->editable && $capability === 'edit_post' && $id > 0;
            },
            function (string $action, string $nonce): bool {
                $this->gates[] = 'nonce';
                return $action === 'myseo_ai_preview' && $nonce === 'ok';
            },
            function (): bool {
                $this->gates[] = 'woo';
                return $this->woo;
            },
            function (int $id): ?string {
                $this->gates[] = 'type';
                return [1 => 'post', 2 => 'page', 3 => 'product', 4 => 'attachment', 5 => 'post'][$id] ?? null;
            },
            fn (): array => ['rank_math_active' => $this->rankMathActive, 'owner' => $this->owner, 'state' => 'observed']
        ), $content, $products];
    }

    /** Supply a valid response body for each supported provider task without calling a gateway. */
    private static function proposal(string $task): array
    {
        return match ($task) {
            'seo_analysis' => ['findings' => []],
            'title_meta_suggestion' => ['target_field' => 'title', 'value' => 'Suggested', 'language' => 'en', 'provenance' => 'editor'],
            'product_description_draft' => ['target' => 'product', 'content' => 'Draft', 'language' => 'en', 'provenance' => 'woocommerce', 'uses_commercial_facts' => false],
        };
    }

    /** Provide valid request defaults so each case varies only the relevant input. */
    private function request(int $id = 1, string $task = 'seo_analysis'): array
    {
        return ['_method' => 'POST', 'post_id' => $id, '_ajax_nonce' => 'ok', 'task' => $task];
    }

    /** Assert the HTTP status and exact public shape for a rejected preview. */
    private function assertError(array $result, int $status, string $code): void
    {
        self::assertSame(['http_status', 'body'], array_keys($result));
        self::assertSame($status, $result['http_status'], $code);
        self::assertSame(['status', 'code', 'message'], array_keys($result['body']));
        self::assertSame('error', $result['body']['status']);
        self::assertSame($code, $result['body']['code']);
    }

    /** Capture metabox markup and always close the test's output buffer. */
    private function render(EditorPreviewController $controller, object $post): string
    {
        ob_start();
        try {
            $controller->renderMetabox($post);
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
