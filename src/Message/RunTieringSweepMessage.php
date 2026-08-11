<?php

namespace App\Message;

/**
 * Dispatched on a schedule (see App\Scheduler\TieringSchedule) to trigger a
 * TieringService sweep — carries no data, it's purely a trigger.
 */
class RunTieringSweepMessage
{
}
