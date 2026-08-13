<?php

namespace App\Scheduler;

use App\Message\RunSpoolDrainMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule('spool_drain')]
class SpoolDrainSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly int $intervalSeconds,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
            ->add(RecurringMessage::every(sprintf('%d seconds', $this->intervalSeconds), new RunSpoolDrainMessage()));
    }
}
