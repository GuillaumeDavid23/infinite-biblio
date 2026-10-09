<?php

namespace App\Export;

/**
 * Export EPUB 3 minimal et valide : mimetype non compressé en premier,
 * container.xml, package OPF, navigation XHTML et un fichier XHTML par chapitre.
 */
final class EpubExporter
{
    /** Écrit l'EPUB dans un fichier temporaire et renvoie son chemin. */
    public function export(BookContent $book, ?\DateTimeImmutable $modified = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'epub_');
        if (false === $path) {
            throw new \RuntimeException('Impossible de créer un fichier temporaire.');
        }
        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException('Impossible de créer l\'archive EPUB.');
        }

        $zip->addFromString('mimetype', 'application/epub+zip');
        $zip->setCompressionName('mimetype', \ZipArchive::CM_STORE);
        $zip->addFromString('META-INF/container.xml', $this->container());

        $items = [];
        if ($book->simulated) {
            $items[] = ['id' => 'avertissement', 'href' => 'avertissement.xhtml', 'title' => 'Avertissement'];
            $zip->addFromString('OEBPS/avertissement.xhtml', $this->page($book, 'Avertissement', '<p>Ce livre a été produit par le simulateur du laboratoire : son texte n\'a aucune valeur littéraire.</p>'));
        }
        foreach ($book->chapters as $chapter) {
            $id = 'chapitre-'.$chapter['number'];
            $items[] = ['id' => $id, 'href' => $id.'.xhtml', 'title' => $chapter['title']];
            $zip->addFromString('OEBPS/'.$id.'.xhtml', $this->page($book, $chapter['title'], $this->paragraphs($chapter['body'])));
        }
        $zip->addFromString('OEBPS/nav.xhtml', $this->nav($book, $items));
        $zip->addFromString('OEBPS/content.opf', $this->opf($book, $items, $modified ?? new \DateTimeImmutable()));
        $zip->close();

        return $path;
    }

    private function container(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">
  <rootfiles>
    <rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/>
  </rootfiles>
</container>
XML;
    }

    /** @param list<array{id: string, href: string, title: string}> $items */
    private function opf(BookContent $book, array $items, \DateTimeImmutable $modified): string
    {
        $manifest = '    <item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>'."\n";
        $spine = '';
        foreach ($items as $item) {
            $manifest .= \sprintf('    <item id="%s" href="%s" media-type="application/xhtml+xml"/>'."\n", $item['id'], $item['href']);
            $spine .= \sprintf('    <itemref idref="%s"/>'."\n", $item['id']);
        }
        $title = $this->e($book->title);
        $id = $this->e($book->identifier);
        $date = $modified->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="bookid" xml:lang="{$book->language}">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
    <dc:identifier id="bookid">{$id}</dc:identifier>
    <dc:title>{$title}</dc:title>
    <dc:language>{$book->language}</dc:language>
    <dc:creator>Bibliothèque infinie (laboratoire)</dc:creator>
    <meta property="dcterms:modified">{$date}</meta>
  </metadata>
  <manifest>
{$manifest}  </manifest>
  <spine>
{$spine}  </spine>
</package>
XML;
    }

    /** @param list<array{id: string, href: string, title: string}> $items */
    private function nav(BookContent $book, array $items): string
    {
        $li = '';
        foreach ($items as $item) {
            $li .= \sprintf('      <li><a href="%s">%s</a></li>'."\n", $item['href'], $this->e($item['title']));
        }
        $body = "<nav epub:type=\"toc\" id=\"toc\">\n    <ol>\n{$li}    </ol>\n  </nav>";

        return $this->page($book, 'Sommaire', $body);
    }

    private function page(BookContent $book, string $title, string $body): string
    {
        $t = $this->e($title);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" xml:lang="{$book->language}" lang="{$book->language}">
<head>
  <meta charset="UTF-8"/>
  <title>{$t}</title>
</head>
<body>
  <h1>{$t}</h1>
  {$body}
</body>
</html>
XML;
    }

    private function paragraphs(string $text): string
    {
        $out = [];
        foreach (preg_split('/\n\s*\n/', trim($text)) ?: [] as $para) {
            $para = trim($para);
            if ('' === $para) {
                continue;
            }
            if (preg_match('/^(\*\s*){3}$|^-{3,}$/', $para)) {
                $out[] = '<hr/>';
                continue;
            }
            $html = $this->e($para);
            $html = (string) preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);
            $html = (string) preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/s', '<em>$1</em>', $html);
            $out[] = '<p>'.str_replace("\n", '<br/>', $html).'</p>';
        }

        return implode("\n  ", $out);
    }

    private function e(string $s): string
    {
        // Supprime les caractères interdits en XML 1.0.
        $s = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s);

        return htmlspecialchars($s, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
    }
}
