<?php

namespace App\MessageHandler;

use App\Message\RunLogEntryPruneMessage;
use App\Service\LogEntryPruneService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class RunLogEntryPruneMessageHandler
{
    public function __construct(
        private readonly LogEntryPruneService $logEntryPruneService,
    ) {
    }

    public function __invoke(RunLogEntryPruneMessage $message): void
    {
        $this->logEntryPruneService->run(new \DateTimeImmutable());
    }
}
