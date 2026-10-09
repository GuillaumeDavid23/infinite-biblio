<?php

namespace App\Tests\Unit;

use App\Ai\Prompt\StructuredOutputException;
use App\Ai\Prompt\StructuredOutputParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StructuredOutputParserTest extends TestCase
{
    private function schema(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../../prompts/v1/schemas/review.json'), true);
    }

    public function testValidResponse(): void
    {
        $data = (new StructuredOutputParser())->parse('{"summary":"ok","issues":[{"type":"continuity","severity":"critical","description":"La clé réapparaît","excerpt":"","suggestion":""}]}', $this->schema());
        self::assertSame('critical', $data['issues'][0]['severity']);
    }

    public function testToleratesCodeFence(): void
    {
        $data = (new StructuredOutputParser())->parse("```json\n{\"summary\":\"ok\",\"issues\":[]}\n```", $this->schema());
        self::assertSame([], $data['issues']);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidResponses(): iterable
    {
        yield 'JSON tronqué' => ['{"summary":"ok","issues":['];
        yield 'texte libre' => ['Voici mon analyse : tout va bien.'];
        yield 'champ manquant' => ['{"summary":"ok"}'];
        yield 'mauvais type' => ['{"summary":"ok","issues":"aucune"}'];
        yield 'valeur hors liste' => ['{"summary":"ok","issues":[{"type":"autre","severity":"info","description":"x","excerpt":"","suggestion":""}]}'];
        yield 'liste au lieu d\'objet' => ['[1,2,3]'];
    }

    #[DataProvider('invalidResponses')]
    public function testInvalidResponsesAreRejected(string $text): void
    {
        $this->expectException(StructuredOutputException::class);
        (new StructuredOutputParser())->parse($text, $this->schema());
    }

    public function testAllProjectSchemasAreValidJson(): void
    {
        foreach (glob(__DIR__.'/../../prompts/v1/schemas/*.json') ?: [] as $file) {
            $schema = json_decode((string) file_get_contents($file), true);
            self::assertIsArray($schema, basename($file));
            self::assertFalse($schema['additionalProperties'], basename($file));
        }
    }
}
