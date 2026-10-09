<?php

namespace App\Ai\Provider;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Messages\Message;
use Anthropic\RequestOptions;

/**
 * Intégration de l'API Anthropic (Messages). Utilisée uniquement si AI_PROVIDER=anthropic
 * ET AI_REAL_CALLS_ENABLED=1 : chaque appel est facturé sur le compte API, indépendamment
 * de tout abonnement Claude Code.
 */
final class AnthropicProvider implements TextGenerationProvider
{
    private ?Client $client = null;

    public function __construct(
        #[\SensitiveParameter] private readonly string $apiKey,
        private readonly string $model,
        private readonly string $effort,
        private readonly float $timeoutSeconds,
    ) {
    }

    public function name(): string
    {
        return 'anthropic';
    }

    public function isSimulated(): bool
    {
        return false;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function countInputTokens(GenerationRequest $request): int
    {
        try {
            return $this->client()->messages->countTokens(...$this->params($request, false))->inputTokens;
        } catch (\Throwable $e) {
            throw $this->wrap($e);
        }
    }

    public function generate(GenerationRequest $request): GenerationResult
    {
        try {
            /** @var Message $message */
            $message = $this->client()->messages->create(...['maxTokens' => $request->maxOutputTokens] + $this->params($request, true));
        } catch (\Throwable $e) {
            throw $this->wrap($e);
        }

        $text = '';
        foreach ($message->content as $block) {
            if ('text' === $block->type) {
                $text .= $block->text;
            }
        }

        $usage = $message->usage;
        $raw = json_decode(json_encode($usage) ?: '{}', true) ?: [];

        return new GenerationResult(
            $text,
            new TokenUsage(
                $usage->inputTokens,
                $usage->outputTokens,
                $usage->cacheCreationInputTokens,
                $usage->cacheCreation?->ephemeral5mInputTokens,
                $usage->cacheCreation?->ephemeral1hInputTokens,
                $usage->cacheReadInputTokens,
                \is_array($raw) ? $raw : [],
            ),
            \is_string($message->model) ? $message->model : $message->model->value,
            \is_string($message->stopReason) || null === $message->stopReason ? $message->stopReason : $message->stopReason->value,
            $message->id,
        );
    }

    /** @return array<string, mixed> */
    private function params(GenerationRequest $request, bool $forCreate): array
    {
        // L'effort ne concerne que la génération ; le comptage de tokens n'en a pas besoin.
        $outputConfig = $forCreate ? ['effort' => $this->effort] : [];
        if (null !== $request->jsonSchema) {
            $outputConfig['format'] = ['type' => 'json_schema', 'schema' => $request->jsonSchema];
        }

        return [
            'model' => $this->model,
            // Consignes système stables : marquées pour le cache (ignoré si trop court).
            'system' => [['type' => 'text', 'text' => $request->system, 'cacheControl' => ['type' => 'ephemeral']]],
            'messages' => [['role' => 'user', 'content' => $request->userPrompt]],
            'thinking' => ['type' => 'adaptive'],
            'outputConfig' => [] !== $outputConfig ? $outputConfig : null,
            // Pas de reprise automatique cachée dans le SDK : chaque tentative est journalisée par l'application.
            'requestOptions' => RequestOptions::with(timeout: $this->timeoutSeconds, maxRetries: 0),
        ];
    }

    private function client(): Client
    {
        if ('' === trim($this->apiKey)) {
            throw new ProviderException('ANTHROPIC_API_KEY n\'est pas renseignée (fichier .env.local).', false);
        }

        return $this->client ??= new Client(apiKey: $this->apiKey);
    }

    private function wrap(\Throwable $e): ProviderException
    {
        if ($e instanceof ProviderException) {
            return $e;
        }
        $nonRetryable = $e instanceof AuthenticationException || $e instanceof PermissionDeniedException || $e instanceof BadRequestException;
        $label = match (true) {
            $e instanceof APIStatusException => 'Erreur API Anthropic ('.$e::class.')',
            $e instanceof APIConnectionException => 'Connexion à l\'API Anthropic impossible',
            default => 'Erreur inattendue du fournisseur',
        };
        // Le message d'origine ne contient pas la clé ; on le tronque par prudence.
        $detail = mb_substr(preg_replace('/sk-ant-[A-Za-z0-9_\-]+/', 'sk-ant-***', $e->getMessage()) ?? '', 0, 500);

        return new ProviderException($label.' : '.$detail, !$nonRetryable, $e);
    }
}
