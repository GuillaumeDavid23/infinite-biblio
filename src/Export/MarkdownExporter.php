<?php

namespace App\Export;

final class MarkdownExporter
{
    public function export(BookContent $book): string
    {
        $out = '# '.$book->title."\n\n";
        if ($book->simulated) {
            $out .= "> Texte produit par le simulateur : aucun contenu littéraire réel.\n\n";
        }
        foreach ($book->chapters as $chapter) {
            $out .= '## '.$chapter['title']."\n\n";
            if (!$chapter['accepted']) {
                $out .= "> Chapitre non encore accepté (brouillon).\n\n";
            }
            $out .= trim($chapter['body'])."\n\n";
        }

        return rtrim($out)."\n";
    }
}
