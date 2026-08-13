<?php

namespace App\MessageHandler;

use App\Message\RunLogEntryPruneMessage;
use App\Service\JobRunTracker;
use App\Service\LogEntryPruneService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class RunLogEntryPruneMessageHandler
{
    public function __construct(
        private readonly LogEntryPruneService $logEntryPruneService,
        private readonly JobRunTracker $jobRunTracker,
    ) {
    }

    public function __invoke(RunLogEntryPruneMessage $message): void
    {
        $now = new \DateTimeImmutable();
        $this->jobRunTracker->track('log_entry_prune', $now, fn () => $this->logEntryPruneService->run($now));
    }
}
