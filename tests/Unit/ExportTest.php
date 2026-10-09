<?php

namespace App\Tests\Unit;

use App\Export\BookContent;
use App\Export\EpubExporter;
use App\Export\MarkdownExporter;
use PHPUnit\Framework\TestCase;

final class ExportTest extends TestCase
{
    private function book(): BookContent
    {
        return new BookContent('Le Phare & les « marées »', 'fr', [
            ['number' => 1, 'title' => 'L\'arrivée <sur> l\'île', 'body' => "Premier paragraphe avec *emphase* & esperluette.\n\nSecond paragraphe.\n\n***\n\nAprès la pause.", 'accepted' => true],
            ['number' => 2, 'title' => 'La marée', 'body' => "Texte du deuxième chapitre.\x01", 'accepted' => false],
        ], true, 'urn:uuid:12345678-1234-3123-a123-123456789012');
    }

    public function testMarkdown(): void
    {
        $md = (new MarkdownExporter())->export($this->book());
        self::assertStringStartsWith('# Le Phare & les « marées »', $md);
        self::assertStringContainsString('## L\'arrivée <sur> l\'île', $md);
        self::assertStringContainsString('simulateur', $md);
        self::assertStringContainsString('brouillon', $md);
    }

    public function testEpubStructureIsValid(): void
    {
        $path = (new EpubExporter())->export($this->book(), new \DateTimeImmutable('2026-10-09 10:00:00'));
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));

        // Exigences OCF : « mimetype » en premier, non compressé, contenu exact.
        self::assertSame('mimetype', $zip->getNameIndex(0));
        self::assertSame(\ZipArchive::CM_STORE, $zip->statIndex(0)['comp_method']);
        self::assertSame('application/epub+zip', $zip->getFromName('mimetype'));

        $container = $this->xml($zip->getFromName('META-INF/container.xml'));
        $opfPath = $container->getElementsByTagName('rootfile')->item(0)->getAttribute('full-path');
        $opf = $this->xml($zip->getFromName($opfPath));

        // Chaque fichier du manifeste existe et est un XHTML bien formé.
        $hrefs = [];
        foreach ($opf->getElementsByTagName('item') as $item) {
            $href = 'OEBPS/'.$item->getAttribute('href');
            $content = $zip->getFromName($href);
            self::assertNotFalse($content, $href);
            $this->xml($content);
            $hrefs[$item->getAttribute('id')] = $href;
        }
        self::assertArrayHasKey('nav', $hrefs);
        foreach ($opf->getElementsByTagName('itemref') as $ref) {
            self::assertArrayHasKey($ref->getAttribute('idref'), $hrefs);
        }
        self::assertSame('fr', $opf->getElementsByTagNameNS('http://purl.org/dc/elements/1.1/', 'language')->item(0)->textContent);
        self::assertStringContainsString('<em>emphase</em> &amp; esperluette', $zip->getFromName('OEBPS/chapitre-1.xhtml'));
        self::assertStringContainsString('<hr/>', $zip->getFromName('OEBPS/chapitre-1.xhtml'));
        $zip->close();
        unlink($path);
    }

    private function xml(string|false $content): \DOMDocument
    {
        self::assertIsString($content);
        $dom = new \DOMDocument();
        self::assertTrue(@$dom->loadXML($content), 'XML mal formé : '.substr($content, 0, 200));

        return $dom;
    }
}
