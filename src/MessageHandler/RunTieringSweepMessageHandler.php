<?php

namespace App\MessageHandler;

use App\Message\RunTieringSweepMessage;
use App\Service\JobRunTracker;
use App\Service\TieringService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class RunTieringSweepMessageHandler
{
    public function __construct(
        private readonly TieringService $tieringService,
        private readonly JobRunTracker $jobRunTracker,
    ) {
    }

    public function __invoke(RunTieringSweepMessage $message): void
    {
        $now = new \DateTimeImmutable();
        $this->jobRunTracker->track('tiering', $now, fn () => $this->tieringService->run($now));
    }
}
