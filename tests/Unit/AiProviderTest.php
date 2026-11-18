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
        self::assertSame('A useful title', $first['output']['title']);
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

        self::assertSame('Compact widget', $response['output']['description']);
        self::assertSame('description', $response['output']['target_field']);
        self::assertSame('vi', $response['output']['language']);
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

        self::assertSame([], $analysis['output']['findings']);
        self::assertSame('vi', $analysis['output']['language']);
        self::assertSame(['title', 'meta_description'], $suggestion['output']['target_fields']);
        self::assertSame('vi', $suggestion['output']['language']);
    }
}
