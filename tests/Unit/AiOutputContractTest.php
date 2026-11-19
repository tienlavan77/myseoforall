<?php
/**
 * Exercises the shared AI response boundary with literal success and malformed envelopes.
 * The fixtures stay offline and independent from FakeAiProvider output generation so the contract
 * tests can detect missing validation, mutation, unsafe text, and task-specific schema drift.
 */

declare(strict_types=1);

namespace MySeoForAll\Tests\Unit;

use MySeoForAll\AI\Contracts\AiOutputValidator;
use MySeoForAll\AI\Contracts\AiProviderException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the exact response contract for each approved offline AI task.
 */
final class AiOutputContractTest extends TestCase
{
    /**
     * Accept literal success fixtures for all three tasks and preserve their values.
     */
    public function testLiteralSuccessFixturesUseExactSchemas(): void
    {
        $validator = new AiOutputValidator();

        $responses = [
            [
                'schema_version' => AiOutputValidator::RESPONSE_SCHEMA,
                'provider' => 'fixture',
                'task' => 'seo_analysis',
                'status' => 'ok',
                'output' => [
                    'findings' => [[
                        'code' => 'title_missing',
                        'severity' => 'error',
                        'message' => 'Missing title',
                        'field' => 'title',
                        'evidence' => 'No title was provided',
                        'suggested_fix' => 'Add a title',
                    ]],
                ],
            ],
            [
                'schema_version' => AiOutputValidator::RESPONSE_SCHEMA,
                'provider' => 'fixture',
                'task' => 'title_meta_suggestion',
                'status' => 'ok',
                'output' => [
                    'target_field' => 'title',
                    'value' => 'A useful title',
                    'language' => 'vi',
                    'provenance' => 'editor',
                ],
            ],
            [
                'schema_version' => AiOutputValidator::RESPONSE_SCHEMA,
                'provider' => 'fixture',
                'task' => 'product_description_draft',
                'status' => 'ok',
                'output' => [
                    'target' => 'product',
                    'content' => 'A bounded product draft',
                    'language' => 'vi',
                    'provenance' => 'woocommerce',
                    'uses_commercial_facts' => false,
                ],
            ],
        ];

        foreach ($responses as $response) {
            self::assertSame($response, $validator->validate($response, $response['task']));
        }
    }

    /**
     * Reject extra fields and malformed scalar, list, and object values deterministically.
     */
    public function testRejectsClosedSchemaViolations(): void
    {
        $validator = new AiOutputValidator();
        $base = [
            'schema_version' => AiOutputValidator::RESPONSE_SCHEMA,
            'provider' => 'fixture',
            'task' => 'title_meta_suggestion',
            'status' => 'ok',
            'output' => [
                'target_field' => 'title',
                'value' => 'A title',
                'language' => 'vi',
                'provenance' => 'editor',
            ],
        ];

        $cases = [
            'extra envelope key' => $base + ['extra' => true],
            'wrong output scalar' => array_replace($base, ['output' => 'invalid']),
            'extra output key' => array_replace($base, ['output' => array_replace($base['output'], ['publish' => true])]),
            'wrong target type' => array_replace($base, ['output' => array_replace($base['output'], ['target_field' => 1])]),
            'wrong language case' => array_replace($base, ['output' => array_replace($base['output'], ['language' => 'VI'])]),
        ];

        foreach ($cases as $label => $response) {
            try {
                $validator->validate($response);
                self::fail($label . ' should be rejected.');
            } catch (AiProviderException $exception) {
                self::assertSame(AiProviderException::INVALID_RESPONSE, $exception->category(), $label);
            }
        }
    }

    /**
     * Reject task-specific enum, provenance, commercial mutation, and text-limit violations.
     */
    public function testRejectsTaskSafetyViolations(): void
    {
        $validator = new AiOutputValidator();
        $base = [
            'schema_version' => AiOutputValidator::RESPONSE_SCHEMA,
            'provider' => 'fixture',
            'task' => 'product_description_draft',
            'status' => 'ok',
            'output' => [
                'target' => 'product',
                'content' => 'Draft',
                'language' => 'vi',
                'provenance' => 'woocommerce',
                'uses_commercial_facts' => false,
            ],
        ];

        $cases = [
            'wrong target' => array_replace($base, ['output' => array_replace($base['output'], ['target' => 'price'])]),
            'wrong provenance' => array_replace($base, ['output' => array_replace($base['output'], ['provenance' => 'token'])]),
            'commercial facts enabled' => array_replace($base, ['output' => array_replace($base['output'], ['uses_commercial_facts' => true])]),
            'content too long' => array_replace($base, ['output' => array_replace($base['output'], ['content' => str_repeat('x', 4001)])]),
            'private text' => array_replace($base, ['output' => array_replace($base['output'], ['content' => 'email person@example.com'])]),
        ];

        foreach ($cases as $label => $response) {
            try {
                $validator->validate($response);
                self::fail($label . ' should be rejected.');
            } catch (AiProviderException $exception) {
                self::assertSame(AiProviderException::INVALID_RESPONSE, $exception->category(), $label);
            }
        }
    }

    /**
     * Reject malformed findings, oversized collections, and preserve input arrays unchanged.
     */
    public function testRejectsFindingViolationsAndDoesNotMutateInput(): void
    {
        $validator = new AiOutputValidator();
        $finding = [
            'code' => 'title_missing',
            'severity' => 'error',
            'message' => 'Missing title',
            'field' => 'title',
            'evidence' => 'No title',
            'suggested_fix' => 'Add title',
        ];
        $response = [
            'schema_version' => AiOutputValidator::RESPONSE_SCHEMA,
            'provider' => 'fixture',
            'task' => 'seo_analysis',
            'status' => 'ok',
            'output' => ['findings' => array_fill(0, 50, $finding)],
        ];
        $original = $response;

        self::assertSame($response, $validator->validate($response));
        self::assertSame($original, $response);

        $response['output']['findings'][] = $finding;
        try {
            $validator->validate($response);
            self::fail('The 51st finding should be rejected.');
        } catch (AiProviderException $exception) {
            self::assertSame(AiProviderException::INVALID_RESPONSE, $exception->category());
            self::assertStringNotContainsString('Missing title', $exception->getMessage());
        }
    }

    /**
     * Distinguish malformed request, unsupported task, and invalid response categories safely.
     */
    public function testErrorCategoriesAreStableAndPayloadFree(): void
    {
        $malformed = new AiProviderException(AiProviderException::MALFORMED_REQUEST, 'Request envelope is malformed.');
        $unsupported = new AiProviderException(AiProviderException::UNSUPPORTED_TASK, 'Provider task is unsupported.');
        $invalid = new AiProviderException(AiProviderException::INVALID_RESPONSE, 'Response output is invalid.');

        self::assertSame(AiProviderException::MALFORMED_REQUEST, $malformed->getCategory());
        self::assertSame(AiProviderException::UNSUPPORTED_TASK, $unsupported->category());
        self::assertSame(AiProviderException::INVALID_RESPONSE, $invalid->category());
        self::assertStringNotContainsString('token', $invalid->getMessage());
    }
}
