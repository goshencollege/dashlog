<?php

namespace App\MessageHandler;

use App\Message\RunSpoolDrainMessage;
use App\Service\JobRunTracker;
use App\Service\SpoolDrainService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class RunSpoolDrainMessageHandler
{
    public function __construct(
        private readonly SpoolDrainService $spoolDrainService,
        private readonly JobRunTracker $jobRunTracker,
    ) {
    }

    public function __invoke(RunSpoolDrainMessage $message): void
    {
        $this->jobRunTracker->track('spool_drain', new \DateTimeImmutable(), fn () => $this->spoolDrainService->run());
    }
}
