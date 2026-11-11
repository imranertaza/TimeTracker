<?php

namespace Tests\Feature;

use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimeFormattingTest extends TestCase
{
    use RefreshDatabase;

    public function test_durations_never_drop_their_seconds(): void
    {
        // A session from 8:20:40 to 8:25:00 is 4m 20s and was reported as
        // "4m", which looked like a missing minute in the daily report.
        $this->assertSame('4m 18s', Format::duration(258));
        $this->assertSame('45s', Format::duration(45));
        $this->assertSame('0s', Format::duration(0));
        $this->assertSame('1m', Format::duration(60));
        $this->assertSame('1h 00m', Format::duration(3600));
        $this->assertSame('1h 01m 18s', Format::duration(3678));
        $this->assertSame('00:04:18', Format::duration(258, true));
    }

    public function test_session_times_show_seconds_and_the_elapsed_length(): void
    {
        $start = CarbonImmutable::parse('2026-11-11 02:20:40', 'UTC');
        $end = CarbonImmutable::parse('2026-11-11 02:25:00', 'UTC');

        $this->assertSame('08:20:40 AM', Format::timeWithSeconds($start));
        $this->assertSame('08:25:00 AM', Format::timeWithSeconds($end));
        $this->assertSame('08:20 AM', Format::time($start));
        $this->assertSame('4m 20s', Format::elapsed($start, $end));
        $this->assertSame('—', Format::elapsed($start, null));
        $this->assertSame('11 Nov 2026, 08:20:40 AM', Format::dateTimeWithSeconds($start));
    }
}
