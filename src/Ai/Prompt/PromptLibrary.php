<?php

namespace App\Ai\Prompt;

use App\Enum\Step;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

/**
 * Consignes de génération versionnées, stockées hors du code métier (dossier prompts/).
 */
final class PromptLibrary
{
    private ?Environment $twig = null;
    private ?string $version = null;

    public function __construct(private readonly string $promptsDir)
    {
    }

    /** Version active (prompts/manifest.yaml). */
    public function version(): string
    {
        if (null === $this->version) {
            $manifest = Yaml::parseFile($this->promptsDir.'/manifest.yaml');
            $this->version = (string) $manifest['current'];
        }

        return $this->version;
    }

    public function system(): string
    {
        return trim($this->twig()->render($this->version().'/system.md.twig'));
    }

    /** @param array<string, mixed> $vars */
    public function user(Step $step, array $vars): string
    {
        return trim($this->twig()->render($this->version().'/'.$step->promptName().'.user.md.twig', $vars));
    }

    /** @return array<string, mixed>|null */
    public function schema(Step $step): ?array
    {
        $file = $this->promptsDir.'/'.$this->version().'/schemas/'.$step->promptName().'.json';
        if (!is_file($file)) {
            return null;
        }

        return json_decode((string) file_get_contents($file), true, 64, \JSON_THROW_ON_ERROR);
    }

    private function twig(): Environment
    {
        if (null === $this->twig) {
            $this->twig = new Environment(new FilesystemLoader($this->promptsDir), [
                'autoescape' => false,
                'strict_variables' => true,
                'cache' => false,
            ]);
            // Encode les données du lecteur en JSON : elles restent des données,
            // et les chevrons sont neutralisés pour ne pas fermer les balises de délimitation.
            $this->twig->addFilter(new TwigFilter('data', static fn (mixed $v): string => json_encode(
                $v,
                \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT | \JSON_HEX_TAG | \JSON_THROW_ON_ERROR
            )));
        }

        return $this->twig;
    }
}
