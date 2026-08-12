<?php

namespace App\MessageHandler;

use App\Message\RunOrphanedLogObjectFinalizeMessage;
use App\Service\OrphanedLogObjectFinalizer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class RunOrphanedLogObjectFinalizeMessageHandler
{
    public function __construct(
        private readonly OrphanedLogObjectFinalizer $orphanedLogObjectFinalizer,
    ) {
    }

    public function __invoke(RunOrphanedLogObjectFinalizeMessage $message): void
    {
        $this->orphanedLogObjectFinalizer->run(new \DateTimeImmutable());
    }
}
