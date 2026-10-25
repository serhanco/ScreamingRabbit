<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class SchedulerTest extends TestCase
{
    public function test_queue_worker_is_scheduled_every_minute_without_overlap(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => $event->description === 'queue-worker');

        $this->assertNotNull($event, 'the queue worker must be scheduled');
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        // It must run inside the cron process: sub-processes are not available on the shared host.
        $this->assertNull($event->command);
    }
}
