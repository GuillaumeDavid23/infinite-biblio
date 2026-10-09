<?php

namespace App\MessageHandler;

use App\Generation\JobRunner;
use App\Message\RunGenerationJob;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RunGenerationJobHandler
{
    public function __construct(private readonly JobRunner $runner)
    {
    }

    public function __invoke(RunGenerationJob $message): void
    {
        $this->runner->run($message->jobId);
    }
}
