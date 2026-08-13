<?php

namespace App\MessageHandler;

use App\Message\RunOrphanedLogObjectFinalizeMessage;
use App\Service\JobRunTracker;
use App\Service\OrphanedLogObjectFinalizer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class RunOrphanedLogObjectFinalizeMessageHandler
{
    public function __construct(
        private readonly OrphanedLogObjectFinalizer $orphanedLogObjectFinalizer,
        private readonly JobRunTracker $jobRunTracker,
    ) {
    }

    public function __invoke(RunOrphanedLogObjectFinalizeMessage $message): void
    {
        $now = new \DateTimeImmutable();
        $this->jobRunTracker->track('orphaned_log_object_finalize', $now, fn () => $this->orphanedLogObjectFinalizer->run($now));
    }
}
