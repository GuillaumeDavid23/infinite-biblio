<?php

namespace App\Ai\Prompt;

/**
 * Décode et vérifie une réponse structurée (JSON) contre son schéma.
 * Validation volontairement simple : types, propriétés obligatoires, énumérations.
 */
final class StructuredOutputParser
{
    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    public function parse(string $text, array $schema): array
    {
        $text = trim($text);
        // Tolère un bloc ```json … ``` éventuel.
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $text, $m)) {
            $text = $m[1];
        }
        try {
            $data = json_decode($text, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new StructuredOutputException('Réponse structurée illisible (JSON invalide) : '.$e->getMessage());
        }
        if (!\is_array($data)) {
            throw new StructuredOutputException('Réponse structurée inattendue : un objet JSON était attendu.');
        }
        $this->validate($data, $schema, '$');

        return $data;
    }

    /** @param array<string, mixed> $schema */
    private function validate(mixed $value, array $schema, string $path): void
    {
        $type = $schema['type'] ?? null;
        $types = \is_array($type) ? $type : [$type];
        if (null !== $type && !$this->matchesAny($value, $types)) {
            throw new StructuredOutputException(\sprintf('Champ %s : type « %s » attendu.', $path, implode('|', $types)));
        }
        if (isset($schema['enum']) && !\in_array($value, $schema['enum'], true)) {
            throw new StructuredOutputException(\sprintf('Champ %s : valeur non autorisée.', $path));
        }
        $isObject = \is_array($value) && ([] === $value || !array_is_list($value)) && \in_array('object', $types, true);
        if ($isObject) {
            foreach ($schema['required'] ?? [] as $key) {
                if (!\array_key_exists($key, $value)) {
                    throw new StructuredOutputException(\sprintf('Champ obligatoire manquant : %s.%s', $path, $key));
                }
            }
            foreach ($schema['properties'] ?? [] as $key => $sub) {
                if (\array_key_exists($key, $value)) {
                    $this->validate($value[$key], $sub, $path.'.'.$key);
                }
            }
        }
        if (\is_array($value) && \in_array('array', $types, true) && array_is_list($value) && isset($schema['items'])) {
            foreach ($value as $i => $item) {
                $this->validate($item, $schema['items'], $path.'['.$i.']');
            }
        }
    }

    /** @param list<string|null> $types */
    private function matchesAny(mixed $value, array $types): bool
    {
        foreach ($types as $t) {
            $ok = match ($t) {
                'object' => \is_array($value) && ([] === $value || !array_is_list($value)),
                'array' => \is_array($value) && array_is_list($value),
                'string' => \is_string($value),
                'integer' => \is_int($value),
                'number' => \is_int($value) || \is_float($value),
                'boolean' => \is_bool($value),
                'null' => null === $value,
                default => true,
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }
}
