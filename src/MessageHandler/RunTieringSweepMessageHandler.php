<?php

namespace App\MessageHandler;

use App\Message\RunTieringSweepMessage;
use App\Service\TieringService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class RunTieringSweepMessageHandler
{
    public function __construct(
        private readonly TieringService $tieringService,
    ) {
    }

    public function __invoke(RunTieringSweepMessage $message): void
    {
        $this->tieringService->run(new \DateTimeImmutable());
    }
}
