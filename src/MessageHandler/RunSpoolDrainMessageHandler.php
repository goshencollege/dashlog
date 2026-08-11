<?php

namespace App\MessageHandler;

use App\Message\RunSpoolDrainMessage;
use App\Service\SpoolDrainService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class RunSpoolDrainMessageHandler
{
    public function __construct(
        private readonly SpoolDrainService $spoolDrainService,
    ) {
    }

    public function __invoke(RunSpoolDrainMessage $message): void
    {
        $this->spoolDrainService->run();
    }
}
