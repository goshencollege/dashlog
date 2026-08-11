<?php

namespace App\Message;

/**
 * Dispatched on a schedule (see App\Scheduler\SpoolDrainSchedule) to trigger
 * a SpoolDrainService sweep — carries no data, it's purely a trigger.
 */
class RunSpoolDrainMessage
{
}
