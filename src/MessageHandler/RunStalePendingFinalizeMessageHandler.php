<?php

namespace App\MessageHandler;

use App\Message\RunStalePendingFinalizeMessage;
use App\Service\StalePendingObjectFinalizer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class RunStalePendingFinalizeMessageHandler
{
    public function __construct(
        private readonly StalePendingObjectFinalizer $stalePendingObjectFinalizer,
    ) {
    }

    public function __invoke(RunStalePendingFinalizeMessage $message): void
    {
        $this->stalePendingObjectFinalizer->run(new \DateTimeImmutable());
    }
}
