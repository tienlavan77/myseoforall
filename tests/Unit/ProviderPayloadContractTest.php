<?php
/**
 * Exercises the shared offline provider boundary independently of fake response generation.
 * Fixtures cover each resource, every nested collection, privacy rejection, resource limits,
 * deterministic canonical precedence, provenance, and provider validation of built payloads.
 */
declare(strict_types=1);

namespace MySeoForAll\Tests\Unit;

use InvalidArgumentException;
use MySeoForAll\AI\Contracts\AiProvider;
use MySeoForAll\AI\Fake\FakeAiProvider;
use MySeoForAll\SEO\Context\ProviderPayloadBuilder;
use PHPUnit\Framework\TestCase;

/** Protects the public input contract without WordPress, WooCommerce, network or persistence. */
final class ProviderPayloadContractTest extends TestCase
{
    /** Define representative complete public resources, including a variation with its own facts. */
    private function fixtures(): array
    {
        $editorial = [
            'id' => 1, 'title' => 'Bài viết', 'content' => 'Nội dung',
            'excerpt' => 'Summary', 'meta_description' => 'Description',
            'url' => 'https://example.test/item', 'canonical' => '',
            'language' => 'vi', 'categories' => ['News'], 'tags' => ['Guide'],
            'images' => [['src' => 'image.jpg', 'alt' => 'A useful image']],
            'headings' => [['level' => 1, 'text' => 'Heading']],
            'internal_links' => [['url' => 'https://example.test/guide', 'anchor' => 'Guide']],
            'seo' => ['title' => 'SEO title', 'meta_description' => 'SEO summary',
                'canonical' => 'https://example.test/seo'],
        ];
        $product = [
            'id' => 2, 'product_name' => 'Widget', 'description' => 'Body',
            'short_description' => 'Compact widget', 'sku' => 'WIDGET',
            'price' => '10.00', 'stock' => 0, 'language' => 'vi',
            'attributes' => [['name' => 'Color', 'value' => 'Blue']],
            'offer' => ['price' => '10.00', 'currency' => 'USD', 'availability' => 'in_stock'],
        ];
        return [
            'article' => $editorial, 'page' => $editorial,
            'product' => $product,
            'variation' => array_replace($product, [
                'id' => 3, 'price' => '12.00', 'stock' => 4,
                'variation' => ['id' => 3, 'parent_id' => 2, 'sku' => 'WIDGET',
                    'attributes' => [['name' => 'Color', 'value' => 'Blue']]],
            ]),
        ];
    }

    /** Verify complete resources survive building and provider revalidation without input mutation. */
    public function testSuccessFixturesUseSharedBoundaryForEveryTask(): void
    {
        $builder = new ProviderPayloadBuilder();
        $provider = new FakeAiProvider();
        foreach ($this->fixtures() as $type => $source) {
            $original = $source;
            $payload = $builder->build($source, $type);
            $builder->validatePayload($payload);
            self::assertSame($original, $source);
            self::assertSame($type, $payload['type']);
            foreach (['seo_analysis', 'title_meta_suggestion', 'product_description_draft'] as $task) {
                $request = ['schema_version' => AiProvider::REQUEST_SCHEMA, 'task' => $task, 'context' => $payload];
                $copy = $request;
                self::assertSame('ok', $provider->generate($request)['status']);
                self::assertSame($copy, $request);
            }
        }
    }

    /** Require each resource identity and reject exact-type, shape, and cross-resource violations. */
    public function testInvalidTypesRequiredFieldsAndRelationshipsAreRejected(): void
    {
        $cases = [
            ['article', []], ['page', ['title' => 'Title']], ['product', []],
            ['variation', ['product_name' => 'Widget']],
            ['article', ['title' => 123]], ['article', ['content' => null]],
            ['article', ['images' => ['src' => 'x']]],
            ['article', ['images' => ['x']]], ['article', ['images' => [['src' => 'x']]]],
            ['article', ['seo' => 'x']], ['article', ['seo' => [['title' => 'x']]]],
            ['article', ['categories' => [12]]], ['article', ['tags' => [true]]],
            ['article', ['headings' => [['level' => '1', 'text' => 'x']]]],
            ['article', ['headings' => [['level' => 7, 'text' => 'x']]]],
            ['article', ['title' => new \stdClass()]], ['article', ['id' => '1']],
            ['article', ['price' => '1.00']], ['product', ['price' => 10.0]],
            ['product', ['stock' => '1']], ['product', ['price' => '-1']],
            ['product', ['attributes' => ['color' => 'blue']]],
            ['product', ['offer' => ['price' => '1', 'currency' => 'usd', 'availability' => 'in_stock']]],
            ['product', ['variation' => ['id' => 3, 'parent_id' => 2]]],
            ['variation', ['variation' => ['id' => 3, 'parent_id' => 3]]],
            ['variation', ['id' => 4]], ['variation', ['sku' => 'DIFFERENT']],
            ['article', ['type' => 'product']], ['article', ['unknown' => 'x']],
            ['article', ['provenance' => [['field' => 'title', 'source' => 'token']]]],
            ['article', ['url' => 'javascript:alert(1)']],
            ['article', ['canonical' => 'https://user:pass@example.test']],
            ['article', ['language' => 'invalid_language']],
        ];
        foreach ($cases as [$type, $changes]) {
            $source = $changes === [] ? [] : array_replace($this->fixtures()[$type], $changes);
            if ($changes === ['title' => 'Title'] || $changes === ['product_name' => 'Widget']) {
                $source = $changes;
            }
            $this->rejectBuild($source, $type);
        }
        $this->rejectBuild(['title' => 'Title', 'content' => 'Body'], 'generic');
    }

    /** Reject private keys and recognizable private strings in every allowed nested item schema. */
    public function testPrivacyIsEnforcedAcrossAllNestedSchemas(): void
    {
        $builder = new ProviderPayloadBuilder();
        foreach ($this->fixtures() as $type => $source) {
            foreach ($this->privateKeyVariants($source) as $unsafe) {
                $this->rejectBuild($unsafe, $type);
            }
            foreach ($this->privateTextVariants($source, 'private@example.test') as $unsafe) {
                $this->rejectBuild($unsafe, $type);
            }
        }
        foreach (['api_key=example', 'Bearer abcdefgh', 'token: example',
            'sk-ant-example12345', 'phone: +84 123 456 789', 'private%40example.test',
            'private&#64;example.test', 'https://user:pass@localhost/private',
            '-----BEGIN PRIVATE KEY-----'] as $private) {
            $this->rejectBuild(['title' => 'Title', 'content' => $private], 'article');
        }
        self::assertSame('Title', $builder->build(['title' => 'Title', 'content' => 'Body'])['title']);
    }

    /** Inject a forbidden key into each object, including later list items, without altering originals. */
    private function privateKeyVariants(array $source): array
    {
        $variants = [];
        foreach (['api_key', 'token', 'customer_email', 'order_id'] as $key) {
            $variants[] = $source + [$key => 'private'];
        }
        foreach ($source as $field => $value) {
            if (!is_array($value)) {
                continue;
            }
            foreach ($this->privateKeyVariants($value) as $nested) {
                $copy = $source;
                $copy[$field] = $nested;
                $variants[] = $copy;
            }
        }
        return $variants;
    }

    /** Inject private text at every scalar leaf so all accepted nested paths share the same guard. */
    private function privateTextVariants(array $source, string $text): array
    {
        $variants = [];
        foreach ($source as $field => $value) {
            if (is_array($value)) {
                foreach ($this->privateTextVariants($value, $text) as $nested) {
                    $copy = $source;
                    $copy[$field] = $nested;
                    $variants[] = $copy;
                }
            } else {
                $copy = $source;
                $copy[$field] = $text;
                $variants[] = $copy;
            }
        }
        return $variants;
    }

    /** Test inclusive Unicode, list, depth, and byte budgets and safe rejection beyond them. */
    public function testResourceLimitsCoverTaxonomyNestedItemsAndCycles(): void
    {
        $builder = new ProviderPayloadBuilder();
        $base = ['title' => 'Title', 'content' => 'Body'];
        $payload = $builder->build(array_replace($base, ['content' => str_repeat('ế', 2000),
            'tags' => array_fill(0, 50, 'Tag')]));
        self::assertSame(str_repeat('ế', 2000), $payload['content']);
        $builder->validatePayload($payload);
        foreach (['content', 'categories', 'tags', 'images'] as $field) {
            $value = match ($field) {
                'content' => str_repeat('ế', 2001),
                'images' => [['src' => 'x', 'alt' => str_repeat('x', 2001)]],
                default => [str_repeat('x', 2001)],
            };
            $this->rejectBuild(array_replace($base, [$field => $value]), 'article');
        }
        $this->rejectBuild($base + ['tags' => array_fill(0, 51, 'Tag')], 'article');
        $this->rejectBuild(array_replace($base, ['content' => "\xC3\x28"]), 'article');
        $this->rejectBuild($base + ['categories' => array_fill(0, 6, str_repeat('x', 2000))], 'article');
        $this->rejectBuild($base + ['images' => [[[[['src' => 'x']]]]]], 'article');
        $cycle = [];
        $cycle['loop'] = &$cycle;
        $this->rejectBuild($base + ['images' => $cycle], 'article');
        $exact = array_replace($base, ['content' => str_repeat('x', 2000), 'tags' => array_fill(0, 4, str_repeat('x', 2000))]);
        $start = strlen(json_encode($builder->build($exact), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $exact['excerpt'] = '';
        $overhead = strlen(json_encode($builder->build($exact), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) - $start;
        $exact['excerpt'] = str_repeat('x', 12000 - $start - $overhead);
        self::assertSame(12000, strlen(json_encode($builder->build($exact), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
        $exact['excerpt'] .= 'x';
        $this->rejectBuild($exact, 'article');
    }

    /** Assert canonical precedence, winning provenance, stable ordering and no fabricated field labels. */
    public function testCanonicalAndProvenanceAreDeterministic(): void
    {
        $builder = new ProviderPayloadBuilder();
        $source = $this->fixtures()['article'];
        $source['canonical'] = 'https://example.test/manual';
        $labels = ['title' => 'editor', 'seo.canonical' => 'rank_math'];
        $payload = $builder->build($source, 'article', $labels);
        self::assertSame('https://example.test/seo', $payload['canonical']);
        self::assertSame('rank_math', array_column($payload['provenance'], 'source', 'field')['canonical']);
        self::assertSame($payload, $builder->build(array_reverse($source, true), 'article', array_reverse($labels, true)));
        $builder->validatePayload($payload);
        $source['seo']['canonical'] = '';
        self::assertSame($source['canonical'], $builder->build($source)['canonical']);
        $source['canonical'] = '';
        self::assertSame($source['url'], $builder->build($source)['canonical']);
        foreach ([['absent' => 'editor'], ['title' => 'token'], ['title' => ' token '],
            ['title' => str_repeat('x', 2001)], ['title' => ['api_key' => 'x']]] as $unsafe) {
            try {
                $builder->build($source, 'article', $unsafe);
                self::fail('Invalid provenance was accepted.');
            } catch (InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    /** Reject raw contexts and modified payloads before any offline provider output is produced. */
    public function testProviderRejectsUnbuiltAndTamperedPayloads(): void
    {
        $builder = new ProviderPayloadBuilder();
        $payload = $builder->build($this->fixtures()['article']);
        $cases = [$this->fixtures()['article'], $payload + ['token' => 'private']];
        $tampered = $payload;
        $tampered['seo']['token'] = 'private';
        $cases[] = $tampered;
        $tampered = $payload;
        $tampered['canonical'] = 'https://example.test/tampered';
        $cases[] = $tampered;
        $tampered = $payload;
        $tampered['provenance'][0]['source'] = 'token';
        $cases[] = $tampered;
        foreach ($cases as $context) {
            try {
                (new FakeAiProvider())->generate([
                    'schema_version' => AiProvider::REQUEST_SCHEMA, 'task' => 'seo_analysis', 'context' => $context,
                ]);
                self::fail('Unsafe provider input was accepted.');
            } catch (InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    /** Assert safe rejection for one isolated invalid fixture and avoid treating any other error as success. */
    private function rejectBuild(array $source, string $type): void
    {
        try {
            (new ProviderPayloadBuilder())->build($source, $type);
            self::fail('Invalid provider source was accepted.');
        } catch (InvalidArgumentException $error) {
            self::assertNotSame('', $error->getMessage());
        }
    }
}
