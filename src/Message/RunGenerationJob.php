<?php

namespace App\Message;

final readonly class RunGenerationJob
{
    public function __construct(public int $jobId)
    {
    }
}
