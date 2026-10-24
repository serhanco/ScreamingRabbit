<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class SchedulerTest extends TestCase
{
    public function test_queue_worker_is_scheduled_every_minute_without_overlap(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command, 'queue:work'));

        $this->assertNotNull($event, 'queue:work must be scheduled');
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
