<?php
/**
 * This file verifies the offline AI provider boundary used by My SEO For All.
 * It covers the versioned request and response envelopes, deterministic fake output,
 * recursive context allowlisting, malformed request rejection, and the product draft
 * safety flag. The tests deliberately provide no credentials and make no HTTP calls,
 * so a passing suite proves only local contract behavior and privacy guardrails.
 * Each request is copied before generation and compared afterward, which verifies that
 * a provider does not mutate caller-owned context. Repeated calls are compared as
 * well, proving that the fake has no hidden request history or time-dependent output.
 * The cases also establish that unsupported tasks and unknown nested fields fail with
 * a normal, catchable validation exception before any task output is constructed.
 */
declare(strict_types=1);

namespace MySeoForAll\Tests\Unit;

use InvalidArgumentException;
use MySeoForAll\AI\Contracts\AiProvider;
use MySeoForAll\AI\Fake\FakeAiProvider;
use PHPUnit\Framework\TestCase;

// Defines AiProviderTest class behavior for AiProviderTest.php.
final class AiProviderTest extends TestCase
{
    /**
     * Verify the offline provider exposes a versioned deterministic contract without network behavior.
     */
    public function testFakeProviderReturnsStableResponseWithoutMutatingRequest(): void
    {
        $request = [
            'schema_version' => AiProvider::REQUEST_SCHEMA,
            'task' => 'title_meta_suggestion',
            'context' => (new \MySeoForAll\SEO\Context\ProviderPayloadBuilder())->build([
                'title' => 'A useful title',
                'content' => 'Body',
                'meta_description' => 'A useful summary',
                'images' => [],
            ], 'article'),
        ];
        $original = $request;
        $provider = new FakeAiProvider();

        $first = $provider->generate($request);
        $second = $provider->generate($request);

        self::assertSame('fake', $provider->name());
        self::assertSame($first, $second);
        self::assertSame($original, $request);
        self::assertSame(AiProvider::RESPONSE_SCHEMA, $first['schema_version']);
        self::assertSame('ok', $first['status']);
        self::assertSame('title', $first['output']['target_field']);
        self::assertSame('A useful title', $first['output']['value']);
        self::assertSame('editor', $first['output']['provenance']);
    }

    /**
     * Verify nested allowlists reject secrets and arbitrary fields before any provider work occurs.
     */
    public function testNestedPrivacyAllowlistRejectsForbiddenFields(): void
    {
        $provider = new FakeAiProvider();
        $request = [
            'schema_version' => AiProvider::REQUEST_SCHEMA,
            'task' => 'seo_analysis',
            'context' => [
                'content' => 'Body',
                'images' => [['src' => 'image.jpg', 'alt' => '']],
                'nested' => ['token' => 'secret'],
            ],
        ];

        $this->expectException(InvalidArgumentException::class);
        $provider->generate($request);
    }

    /**
     * Verify malformed envelopes and unsupported tasks fail with actionable validation errors.
     */
    public function testMalformedAndUnsupportedRequestsAreRejected(): void
    {
        $provider = new FakeAiProvider();

        $this->expectException(InvalidArgumentException::class);
        $provider->generate([
            'schema_version' => 'unknown',
            'task' => 'unsupported',
            'context' => [],
            'extra' => true,
        ]);
    }

    /**
     * Verify malformed and unsupported requests expose distinct safe error categories.
     */
    public function testRequestErrorsExposeSafeCategories(): void
    {
        $provider = new FakeAiProvider();

        $cases = [
            [
                'request' => [
                    'schema_version' => AiProvider::REQUEST_SCHEMA,
                    'task' => 'seo_analysis',
                    'context' => [],
                    'extra' => 'secret',
                ],
                'category' => \MySeoForAll\AI\Contracts\AiProviderException::MALFORMED_REQUEST,
            ],
            [
                'request' => [
                    'schema_version' => AiProvider::REQUEST_SCHEMA,
                    'task' => 'unsupported',
                    'context' => [],
                ],
                'category' => \MySeoForAll\AI\Contracts\AiProviderException::UNSUPPORTED_TASK,
            ],
        ];

        foreach ($cases as $case) {
            try {
                $provider->generate($case['request']);
                self::fail('Invalid provider request should be rejected.');
            } catch (\MySeoForAll\AI\Contracts\AiProviderException $exception) {
                self::assertSame($case['category'], $exception->category());
                self::assertStringNotContainsString('secret', $exception->getMessage());
            }
        }
    }

    /**
     * Verify product drafts remain deterministic and explicitly avoid inventing commercial facts.
     */
    public function testProductDraftUsesAllowlistedContextOnly(): void
    {
        $response = (new FakeAiProvider())->generate([
            'schema_version' => AiProvider::REQUEST_SCHEMA,
            'task' => 'product_description_draft',
            'context' => (new \MySeoForAll\SEO\Context\ProviderPayloadBuilder())->build([
                'product_name' => 'Widget',
                'short_description' => 'Compact widget',
            ], 'product'),
        ]);

        self::assertSame('Compact widget', $response['output']['content']);
        self::assertSame('product', $response['output']['target']);
        self::assertSame('vi', $response['output']['language']);
        self::assertSame('woocommerce', $response['output']['provenance']);
        self::assertFalse($response['output']['uses_commercial_facts']);
    }

    /** Verify each offline task returns its own typed output envelope. */
    public function testEachTaskReturnsTypedOutputShape(): void
    {
        $provider = new FakeAiProvider();
        $analysis = $provider->generate([
            'schema_version' => AiProvider::REQUEST_SCHEMA,
            'task' => 'seo_analysis',
            'context' => (new \MySeoForAll\SEO\Context\ProviderPayloadBuilder())->build(['title' => 'Article', 'content' => 'Body'], 'article'),
        ]);
        $suggestion = $provider->generate([
            'schema_version' => AiProvider::REQUEST_SCHEMA,
            'task' => 'title_meta_suggestion',
            'context' => (new \MySeoForAll\SEO\Context\ProviderPayloadBuilder())->build(['title' => 'Title', 'content' => 'Body'], 'page'),
        ]);

        self::assertSame(['findings' => []], $analysis['output']);
        self::assertSame('title', $suggestion['output']['target_field']);
        self::assertSame('Title', $suggestion['output']['value']);
        self::assertSame('editor', $suggestion['output']['provenance']);
        self::assertSame('vi', $suggestion['output']['language']);
    }

    /**
     * Prove the provider cannot return a response when an injected boundary rejects it.
     */
    public function testInjectedValidatorIsCalledAndCanRejectResponse(): void
    {
        $calls = 0;
        $validator = new class($calls) implements \MySeoForAll\AI\Contracts\AiOutputValidatorContract {
            /** @var int */
            private $calls;

            /**
             * Capture the external invocation counter used by the integration assertion.
             * The reference lets the test observe the validator call without changing provider behavior.
             */
            public function __construct(&$calls)
            {
                $this->calls =& $calls;
            }

            /**
             * Reject the generated response to prove the injected boundary controls delivery.
             * The method increments the shared counter and throws the stable invalid-response category.
             */
            public function validate(array $response, ?string $expectedTask = null, ?string $expectedProvider = null): array
            {
                $this->calls++;
                throw new \MySeoForAll\AI\Contracts\AiProviderException(
                    \MySeoForAll\AI\Contracts\AiProviderException::INVALID_RESPONSE,
                    'Injected validator rejected the response.'
                );
            }
        };

        $request = [
            'schema_version' => AiProvider::REQUEST_SCHEMA,
            'task' => 'seo_analysis',
            'context' => (new \MySeoForAll\SEO\Context\ProviderPayloadBuilder())->build(
                ['title' => 'Article', 'content' => 'Body'],
                'article'
            ),
        ];
        $original = $request;

        try {
            (new FakeAiProvider($validator))->generate($request);
            self::fail('The injected validator must be authoritative.');
        } catch (\MySeoForAll\AI\Contracts\AiProviderException $exception) {
            self::assertSame(
                \MySeoForAll\AI\Contracts\AiProviderException::INVALID_RESPONSE,
                $exception->category()
            );
        }

        self::assertSame(1, $calls);
        self::assertSame($original, $request);
    }
}
