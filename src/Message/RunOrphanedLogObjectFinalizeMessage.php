<?php

namespace App\Message;

/**
 * Dispatched on a schedule (see App\Scheduler\OrphanedLogObjectFinalizeSchedule)
 * to trigger an OrphanedLogObjectFinalizer sweep — carries no data, it's
 * purely a trigger.
 */
class RunOrphanedLogObjectFinalizeMessage
{
}
