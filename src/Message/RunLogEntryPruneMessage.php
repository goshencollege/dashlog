<?php

namespace App\Message;

/**
 * Dispatched on a schedule (see App\Scheduler\LogEntryPruneSchedule) to
 * trigger a LogEntryPruneService sweep — carries no data, it's purely a
 * trigger.
 */
class RunLogEntryPruneMessage
{
}
