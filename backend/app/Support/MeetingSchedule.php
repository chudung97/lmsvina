<?php

namespace App\Support;

use DateTimeImmutable;

class MeetingSchedule
{
    private const DAY_MILLISECONDS = 86400000;

    private const MAXIMUM_RECURRING_OCCURRENCES = 520;

    private const MAXIMUM_RECURRENCE_DAYS = 3660;

    public static function validDate(mixed $value): bool
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        return checkdate(
            (int) substr($value, 5, 2),
            (int) substr($value, 8, 2),
            (int) substr($value, 0, 4),
        );
    }

    public static function validTime(mixed $value): bool
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    public static function occurrences(array $schedule): array
    {
        $type = $schedule['type'] ?? 'single';
        $date = $schedule['date'];
        $endDate = $schedule['endDate'] ?? null;
        $startTime = $schedule['startTime'];
        $endTime = $schedule['endTime'];

        if ($type === 'single') {
            if ($endTime <= $startTime) {
                return ['error' => 'Giờ kết thúc phải sau giờ bắt đầu trong cùng một ngày.'];
            }

            return ['occurrences' => [['date' => $date, 'endDate' => $date]]];
        }

        if ($type === 'overnight') {
            if (! self::validDate($endDate) || $endDate <= $date) {
                return ['error' => 'Ngày kết thúc phải sau ngày bắt đầu.'];
            }

            return ['occurrences' => [['date' => $date, 'endDate' => $endDate]]];
        }

        if ($type !== 'weekly') {
            return ['error' => 'Loại lịch họp không hợp lệ.'];
        }
        if (! self::validTime($endTime) || $endTime <= $startTime) {
            return ['error' => 'Lịch lặp hàng tuần phải kết thúc sau giờ bắt đầu trong ngày.'];
        }

        $recurrenceEndDate = $schedule['recurrenceEndDate'] ?? null;
        if (! self::validDate($recurrenceEndDate) || $recurrenceEndDate < $date) {
            return ['error' => 'Ngày kết thúc lặp phải từ ngày bắt đầu trở đi.'];
        }

        $start = new DateTimeImmutable($date.'T00:00:00Z');
        $end = new DateTimeImmutable($recurrenceEndDate.'T00:00:00Z');
        $rangeDays = (int) $start->diff($end)->format('%a');
        if ($rangeDays > self::MAXIMUM_RECURRENCE_DAYS) {
            return ['error' => 'Ngày kết thúc lặp không được quá 10 năm kể từ ngày bắt đầu.'];
        }

        $days = $schedule['recurrenceDays'] ?? null;
        if (! is_array($days) || ! $days
            || array_filter($days, fn ($day) => ! is_int($day) || $day < 0 || $day > 6)
            || count(array_unique($days)) !== count($days)) {
            return ['error' => 'Chọn ít nhất một thứ trong tuần cho lịch lặp.'];
        }

        $selectedDays = array_fill_keys($days, true);
        $occurrences = [];
        for ($offset = 0; $offset <= $rangeDays; $offset++) {
            $occurrenceDate = $start->modify("+{$offset} days");
            $weekday = ((int) $occurrenceDate->format('N') - 1) % 7;
            if (! isset($selectedDays[$weekday])) {
                continue;
            }
            $dateValue = $occurrenceDate->format('Y-m-d');
            $occurrences[] = ['date' => $dateValue, 'endDate' => $dateValue];
            if (count($occurrences) > self::MAXIMUM_RECURRING_OCCURRENCES) {
                return ['error' => 'Lịch lặp không được vượt quá 520 cuộc họp.'];
            }
        }
        if (! $occurrences) {
            return ['error' => 'Không có buổi họp nào khớp với các thứ đã chọn trong khoảng ngày này.'];
        }

        return ['occurrences' => $occurrences];
    }

    public static function overlaps(array|object $first, array|object $second): bool
    {
        $first = (array) $first;
        $second = (array) $second;
        $firstStart = strtotime($first['date'].' '.$first['startTime'].' UTC');
        $firstEnd = strtotime($first['endDate'].' '.$first['endTime'].' UTC');
        $secondStart = strtotime($second['date'].' '.$second['startTime'].' UTC');
        $secondEnd = strtotime($second['endDate'].' '.$second['endTime'].' UTC');

        return $firstStart < $secondEnd && $secondStart < $firstEnd;
    }
}
