<?php

namespace App\Tests\Unit;

use App\Ai\Provider\AnthropicProvider;
use App\Ai\Provider\DisabledRealProvider;
use App\Ai\Provider\GenerationRequest;
use App\Ai\Provider\ProviderException;
use App\Ai\Provider\ProviderFactory;
use App\Ai\Provider\SimulatedProvider;
use App\Enum\Step;
use PHPUnit\Framework\TestCase;

final class ProviderFactoryTest extends TestCase
{
    public function testSimulationIsTheDefault(): void
    {
        $p = ProviderFactory::create('simulation', false, '', 'claude-sonnet-5-5', 'medium', 60, 0);
        self::assertInstanceOf(SimulatedProvider::class, $p);
        self::assertTrue($p->isSimulated());
    }

    public function testRealProviderStaysLockedWithoutExplicitFlag(): void
    {
        $p = ProviderFactory::create('anthropic', false, 'sk-ant-fausse-cle', 'claude-sonnet-5-5', 'medium', 60, 0);
        self::assertInstanceOf(DisabledRealProvider::class, $p);
        try {
            $p->generate(new GenerationRequest(Step::Plan, 's', 'u', 100));
            self::fail('Un appel aurait dû être refusé.');
        } catch (ProviderException $e) {
            self::assertFalse($e->retryable);
            self::assertStringContainsString('AI_REAL_CALLS_ENABLED', $e->getMessage());
        }
    }

    public function testRealProviderRequiresKey(): void
    {
        $p = ProviderFactory::create('anthropic', true, '', 'claude-sonnet-5-5', 'medium', 60, 0);
        self::assertInstanceOf(AnthropicProvider::class, $p);
        $this->expectException(ProviderException::class);
        $p->countInputTokens(new GenerationRequest(Step::Plan, 's', 'u', 100));
    }

    public function testUnknownProviderIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProviderFactory::create('gpt', false, '', 'x', 'medium', 60, 0);
    }

    public function testSimulatedOutputsAreMarked(): void
    {
        $p = new SimulatedProvider('claude-sonnet-5-5');
        $r = $p->generate(new GenerationRequest(Step::ChapterWrite, 's', 'u', 8000, null, ['chapterNumber' => 2, 'targetWords' => 300]));
        self::assertStringContainsString(SimulatedProvider::MARKER, $r->text);
        self::assertTrue($r->usage->raw['simulated']);
    }
}
