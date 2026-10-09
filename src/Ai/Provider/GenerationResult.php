<?php

namespace App\Ai\Provider;

final readonly class GenerationResult
{
    public function __construct(
        public string $text,
        public ?TokenUsage $usage,
        public ?string $model,
        public ?string $stopReason,
        public ?string $requestId = null,
    ) {
    }

    /** La réponse a-t-elle été coupée par la limite de sortie ? */
    public function isTruncated(): bool
    {
        return 'max_tokens' === $this->stopReason;
    }

    public function isRefusal(): bool
    {
        return 'refusal' === $this->stopReason;
    }
}
