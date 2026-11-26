<?php
/**
 * Business purpose: verify controlled Apply behavior for SEO proposals.
 * Technical behavior: exercise signed claims, deterministic fingerprints, gate ordering, ownership checks, and isolated persistence seams without platform writes outside injected test doubles.
 */
declare(strict_types=1);

namespace MySeoForAll\Tests\Unit;

use MySeoForAll\SEO\Runtime\ApplyController;
use MySeoForAll\SEO\Runtime\ApplyFingerprint;
use MySeoForAll\SEO\Runtime\ApplyToken;
use MySeoForAll\SEO\Runtime\CanonicalSeoMetaRepository;
use MySeoForAll\SEO\Runtime\WooCommerceDescriptionWriter;
use PHPUnit\Framework\TestCase;

/**
 * Business purpose: verify controlled Apply contracts offline.
 * Technical description: exercise signed claims, deterministic snapshots, gate ordering, and isolated writers.
 */
final class Increment3BTest extends TestCase
{
    /** Business purpose: prove signed Apply claims survive supported payload shapes and expiry. */
    public function testApplyTokenRoundTripAndExpiry(): void
    {
        $now = 1000;
        $token = new ApplyToken('unit-secret', 120, static function () use (&$now): int { return $now; });
        foreach (['abc', 'abcd', 'abcde', 'Tiếng Việt'] as $value) {
            $claims = ['post_id' => 7, 'value' => $value];
            $issued = $token->issue($claims);
            self::assertTrue($token->verify($issued, $claims));
            self::assertFalse($token->verify($issued . 'x', $claims));
        }
        $now = 1300;
        $expired = $token->issue(['post_id' => 7]);
        $now = 1421;
        self::assertFalse($token->verify($expired, ['post_id' => 7]));
    }

    /** Business purpose: keep Apply fingerprints stable for equivalent snapshots and sensitive to scoped changes. */
    public function testFingerprintIsDeterministicAndScoped(): void
    {
        $fingerprint = new ApplyFingerprint();
        $one = ['field' => 'title', 'value' => "  Hello\r\nworld  ", 'post_id' => 7];
        $two = ['post_id' => 7, 'value' => "Hello\nworld", 'field' => 'title'];
        self::assertSame($fingerprint->fingerprint($one), $fingerprint->fingerprint($two));
        $changed = $two;
        $changed['field'] = 'meta_description';
        self::assertNotSame($fingerprint->fingerprint($two), $fingerprint->fingerprint($changed));
        $unrelated = $two;
        $unrelated['ignored'] = ['debug' => true];
        self::assertNotSame($fingerprint->fingerprint($two), $fingerprint->fingerprint($unrelated));
    }

    /** Business purpose: ensure malformed requests stop before snapshot reads or persistence. */
    public function testEarlyGatesDoNotReadSnapshot(): void
    {
        $snapshotCalls = 0;
        $controller = $this->controller(
            static function () use (&$snapshotCalls): array { $snapshotCalls++; return ['status' => 'ok']; },
            static function (): bool { return true; },
            static function (): bool { return true; }
        );
        foreach ([['_method' => 'GET'], ['_method' => 'POST', 'post_id' => '07'], ['_method' => 'POST', 'post_id' => 7]] as $request) {
            $result = $controller->handleApplyRequest($request);
            self::assertSame('error', $result['body']['status']);
        }
        self::assertSame(0, $snapshotCalls);
    }

    /** Business purpose: apply one approved title and reject stale or tampered proposals without a second write. */
    public function testApplySuccessStaleAndTamperAreClosed(): void
    {
        $fingerprints = new ApplyFingerprint();
        $snapshot = ['version' => 1, 'post_id' => 7, 'resource_type' => 'article', 'task' => 'title_meta_suggestion', 'field' => 'title', 'value' => 'Old', 'source' => 'wordpress', 'ownership_state' => 'none'];
        $current = $snapshot;
        $stored = 'Old';
        $writes = 0;
        $repository = new CanonicalSeoMetaRepository(
            static function (int $id, string $key) use (&$stored): string { return $stored; },
            static function (int $id, string $key, string $value) use (&$stored, &$writes): bool { $stored = $value; $writes++; return true; }
        );
        $token = new ApplyToken('apply-secret', 900, static fn (): int => 1000);
        $controller = new ApplyController($token, $fingerprints, static function () use (&$current): array { return ['status' => 'ok', 'snapshot' => $current]; }, static fn (): bool => true, static fn (): bool => true, static fn (): array => [], $repository);
        $fingerprint = $fingerprints->fingerprint($snapshot);
        $claims = ['post_id' => 7, 'resource_type' => 'article', 'task' => 'title_meta_suggestion', 'field' => 'title', 'value' => 'New title', 'fingerprint' => $fingerprint];
        $request = ['_method' => 'POST', 'post_id' => 7, 'resource_type' => 'article', 'task' => 'title_meta_suggestion', 'field' => 'title', '_ajax_nonce' => 'ok', 'proposal' => ['value' => 'New title'], 'source_fingerprint' => $fingerprint, 'apply_token' => $token->issue($claims)];
        self::assertSame('saved', $controller->handleApplyRequest($request)['body']['code']);
        self::assertSame(1, $writes);
        $current['value'] = 'Changed elsewhere';
        self::assertSame('stale_preview', $controller->handleApplyRequest($request)['body']['code']);
        self::assertSame(1, $writes);
        $request['proposal'] = ['value' => 'Tampered'];
        self::assertSame('invalid_proposal', $controller->handleApplyRequest($request)['body']['code']);
        self::assertSame(1, $writes);
    }

    /** Business purpose: enforce ownership confirmation and protect commercial product fields. */
    public function testOwnershipAndProtectedFieldsAreRejected(): void
    {
        $snapshot = ['version' => 1, 'post_id' => 7, 'resource_type' => 'article', 'task' => 'title_meta_suggestion', 'field' => 'title', 'value' => 'Old'];
        $fingerprint = (new ApplyFingerprint())->fingerprint($snapshot);
        $token = new ApplyToken('ownership-secret', 900, static fn (): int => 1000);
        $controller = new ApplyController($token, new ApplyFingerprint(), static fn (): array => ['status' => 'ok', 'snapshot' => $snapshot], static fn (): bool => true, static fn (): bool => true, static fn (): array => ['owned' => true], new CanonicalSeoMetaRepository(static fn (): ?string => null, static function (): bool { self::fail('write must not run'); }));
        $claims = ['post_id' => 7, 'resource_type' => 'article', 'task' => 'title_meta_suggestion', 'field' => 'title', 'value' => 'New', 'fingerprint' => $fingerprint];
        $request = ['_method' => 'POST', 'post_id' => 7, 'resource_type' => 'article', 'task' => 'title_meta_suggestion', 'field' => 'title', '_ajax_nonce' => 'ok', 'proposal' => ['value' => 'New'], 'source_fingerprint' => $fingerprint, 'apply_token' => $token->issue($claims)];
        self::assertSame('ownership_conflict', $controller->handleApplyRequest($request)['body']['code']);
        $request['field'] = 'price';
        self::assertSame('protected_field', $controller->handleApplyRequest($request)['body']['code']);
    }

    /** Business purpose: keep canonical and product writers fail closed while allowing same-value persistence confirmation. */
    public function testWritersHandleSameValueAndExceptionsSafely(): void
    {
        $value = 'Existing';
        $repository = new CanonicalSeoMetaRepository(static fn (): string => $value, static fn (int $id, string $key, string $next): bool => false);
        self::assertTrue($repository->write(7, 'title', 'Existing'));
        $throwing = new CanonicalSeoMetaRepository(static function (): string { throw new \RuntimeException('storage'); }, static function (): bool { throw new \RuntimeException('storage'); });
        self::assertNull($throwing->read(7, 'title'));
        self::assertFalse($throwing->write(7, 'title', 'x'));
        $product = new WooCommerceDescriptionWriter(static fn (): string => 'old', static function (): bool { throw new \RuntimeException('save'); });
        self::assertSame('old', $product->read(7));
        self::assertFalse($product->write(7, 'new'));
    }

    /** Build a controller with explicit offline security seams. */
    private function controller(callable $snapshot, callable $capability, callable $nonce): ApplyController
    {
        return new ApplyController(new ApplyToken('test-secret', 900, static fn (): int => 1000), new ApplyFingerprint(), $snapshot, $capability, $nonce);
    }
}
