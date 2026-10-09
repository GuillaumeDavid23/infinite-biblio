<?php

namespace App\Tests\Unit;

use App\Exception\UserFacingException;
use App\Generation\ChapterService;
use PHPUnit\Framework\TestCase;

final class ReplacePassageTest extends TestCase
{
    public function testReplacesUniquePassage(): void
    {
        $text = "Lise ouvrit la porte.\n\nElle trouva la clé sous le paillasson.";
        self::assertSame("Lise ouvrit la porte.\n\nElle ne trouva rien.", ChapterService::replacePassage($text, 'Elle trouva la clé sous le paillasson.', 'Elle ne trouva rien.'));
    }

    public function testRefusesMissingPassage(): void
    {
        $this->expectException(UserFacingException::class);
        ChapterService::replacePassage('Texte.', 'Absent', 'x');
    }

    public function testRefusesAmbiguousPassage(): void
    {
        $this->expectException(UserFacingException::class);
        ChapterService::replacePassage('La mer. La mer.', 'La mer.', 'x');
    }
}
