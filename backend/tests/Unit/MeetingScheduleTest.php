<?php

namespace Tests\Unit;

use App\Support\MeetingSchedule;
use PHPUnit\Framework\TestCase;

class MeetingScheduleTest extends TestCase
{
    public function test_single_day_meetings_must_end_after_their_start(): void
    {
        $valid = MeetingSchedule::occurrences([
            'type' => 'single',
            'date' => '2026-10-26',
            'startTime' => '09:00',
            'endTime' => '10:00',
        ]);
        $invalid = MeetingSchedule::occurrences([
            'type' => 'single',
            'date' => '2026-10-26',
            'startTime' => '10:00',
            'endTime' => '09:00',
        ]);

        $this->assertSame([['date' => '2026-10-26', 'endDate' => '2026-10-26']], $valid['occurrences']);
        $this->assertSame('Giờ kết thúc phải sau giờ bắt đầu trong cùng một ngày.', $invalid['error']);
    }

    public function test_weekly_recurrence_generates_selected_weekdays_only(): void
    {
        $result = MeetingSchedule::occurrences([
            'type' => 'weekly',
            'date' => '2026-10-26',
            'startTime' => '09:00',
            'endTime' => '10:00',
            'recurrenceDays' => [0, 2],
            'recurrenceEndDate' => '2026-11-09',
        ]);

        $this->assertSame(
            ['2026-10-26', '2026-10-28', '2026-11-02', '2026-11-04', '2026-11-09'],
            array_column($result['occurrences'], 'date'),
        );
    }

    public function test_overnight_meetings_conflict_across_calendar_days_but_adjacent_meetings_do_not(): void
    {
        $overnight = [
            'date' => '2026-10-26',
            'endDate' => '2026-10-27',
            'startTime' => '23:00',
            'endTime' => '01:00',
        ];
        $nextMorning = [
            'date' => '2026-10-27',
            'endDate' => '2026-10-27',
            'startTime' => '00:30',
            'endTime' => '01:30',
        ];
        $adjacent = [
            'date' => '2026-10-27',
            'endDate' => '2026-10-27',
            'startTime' => '01:00',
            'endTime' => '02:00',
        ];

        $this->assertTrue(MeetingSchedule::overlaps($overnight, $nextMorning));
        $this->assertFalse(MeetingSchedule::overlaps($overnight, $adjacent));
    }

    public function test_invalid_calendar_dates_are_rejected(): void
    {
        $this->assertFalse(MeetingSchedule::validDate('2026-02-30'));
        $this->assertTrue(MeetingSchedule::validDate('2024-02-29'));
    }
}
