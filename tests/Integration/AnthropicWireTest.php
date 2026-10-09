<?php

namespace App\Tests\Integration;

use App\Ai\Provider\AnthropicProvider;
use App\Ai\Provider\GenerationRequest;
use App\Ai\Provider\ProviderException;
use App\Enum\Step;
use PHPUnit\Framework\TestCase;

/**
 * Vérifie l'intégration Anthropic contre un faux serveur local : format exact des requêtes,
 * lecture de la consommation (y compris le cache) et classement des erreurs. Aucun appel payant.
 */
final class AnthropicWireTest extends TestCase
{
    /** @var resource|null */
    private static $server;
    private static int $port;

    public static function setUpBeforeClass(): void
    {
        self::$port = random_int(20000, 40000);
        self::$server = proc_open(
            [\PHP_BINARY, '-S', '127.0.0.1:'.self::$port, __DIR__.'/../Fixtures/anthropic_mock.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        for ($i = 0; $i < 50; ++$i) {
            $socket = @fsockopen('127.0.0.1', self::$port);
            if (false !== $socket) {
                fclose($socket);
                break;
            }
            usleep(100_000);
        }
        putenv('ANTHROPIC_BASE_URL=http://127.0.0.1:'.self::$port);
    }

    public static function tearDownAfterClass(): void
    {
        putenv('ANTHROPIC_BASE_URL');
        if (\is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    private function request(): GenerationRequest
    {
        $schema = json_decode((string) file_get_contents(__DIR__.'/../../prompts/v1/schemas/review.json'), true);

        return new GenerationRequest(Step::ChapterReview, 'Consignes système', 'Consignes de l\'étape', 4000, $schema);
    }

    /** @return array<string, mixed> */
    private function last(): array
    {
        return json_decode((string) file_get_contents(sys_get_temp_dir().'/anthropic_mock_last.json'), true);
    }

    public function testRequestFormatAndUsageParsing(): void
    {
        $provider = new AnthropicProvider('sk-ant-cle-de-test', 'claude-sonnet-5-5', 'medium', 10);

        self::assertSame(1234, $provider->countInputTokens($this->request()));
        self::assertStringEndsWith('/v1/messages/count_tokens', $this->last()['uri']);
        self::assertArrayNotHasKey('effort', $this->last()['body']['output_config']);

        $result = $provider->generate($this->request());
        $sent = $this->last();
        self::assertStringEndsWith('/v1/messages', $sent['uri']);
        self::assertSame('claude-sonnet-5-5', $sent['body']['model']);
        self::assertSame(4000, $sent['body']['max_tokens']);
        self::assertSame(['type' => 'adaptive'], $sent['body']['thinking']);
        self::assertSame('medium', $sent['body']['output_config']['effort']);
        self::assertSame('json_schema', $sent['body']['output_config']['format']['type']);
        self::assertSame(['type' => 'ephemeral'], $sent['body']['system'][0]['cache_control']);
        self::assertSame('0', (string) ($sent['headers']['X-Stainless-Retry-Count'] ?? '0'));

        self::assertSame('{"summary":"ok","issues":[]}', $result->text, 'Les blocs de réflexion sont ignorés.');
        self::assertSame('claude-sonnet-5-5', $result->model);
        self::assertSame(1200, $result->usage->inputTokens);
        self::assertSame(345, $result->usage->outputTokens);
        self::assertSame(10, $result->usage->cacheCreation5mTokens);
        self::assertSame(20, $result->usage->cacheReadInputTokens);
    }

    public function testTruncatedResponseIsReported(): void
    {
        $result = (new AnthropicProvider('sk-ant-cle-de-test', 'mock-truncated', 'medium', 10))->generate($this->request());
        self::assertTrue($result->isTruncated());
        self::assertNotNull($result->usage, 'La consommation d\'une réponse coupée est conservée pour le coût.');
    }

    public function testAuthenticationErrorIsNotRetriedAndKeyIsNotLeaked(): void
    {
        try {
            (new AnthropicProvider('sk-ant-secret-123', 'mock-401', 'medium', 10))->generate($this->request());
            self::fail('Exception attendue.');
        } catch (ProviderException $e) {
            self::assertFalse($e->retryable);
            self::assertStringNotContainsString('sk-ant-secret-123', $e->getMessage());
        }
    }

    public function testOverloadIsRetryable(): void
    {
        try {
            (new AnthropicProvider('sk-ant-cle-de-test', 'mock-529', 'medium', 10))->generate($this->request());
            self::fail('Exception attendue.');
        } catch (ProviderException $e) {
            self::assertTrue($e->retryable);
        }
    }
}
