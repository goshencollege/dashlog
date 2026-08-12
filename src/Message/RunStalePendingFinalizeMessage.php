<?php

namespace App\Message;

/**
 * Dispatched on a schedule (see App\Scheduler\StalePendingFinalizeSchedule)
 * to trigger a StalePendingObjectFinalizer sweep — carries no data, it's
 * purely a trigger.
 */
class RunStalePendingFinalizeMessage
{
}
