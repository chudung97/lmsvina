<?php

namespace App\Http\Controllers;

use App\Support\MeetingSchedule;
use App\Support\WorkdeskBroadcast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MeetingsController extends ApiController
{
    public function users(): JsonResponse
    {
        return response()->json(DB::table('users')
            ->leftJoin('departments', 'departments.id', '=', 'users.department_id')
            ->where('users.active', 1)
            ->orderBy('users.name')
            ->get(['users.id', 'users.user_code', 'users.name', 'users.email', 'departments.name as departmentName'])
            ->map(fn ($user) => [...(array) $user, 'id' => $this->responseUserId($user->id)]));
    }

    public function rooms(Request $request): JsonResponse
    {
        $query = DB::table('meeting_rooms');
        if (! $this->hasPermission($this->user($request), 'meeting_admin')) {
            $query->where('active', 1);
        }

        return response()->json($query->orderBy('name')->get(['id', 'name', 'active']));
    }

    public function createRoom(Request $request): JsonResponse
    {
        if (! $this->hasPermission($this->user($request), 'meeting_admin')) {
            return $this->error('Chỉ quản trị viên mới được quản lý phòng họp.', 403);
        }
        $name = is_string($request->input('name')) ? trim($request->input('name')) : '';
        if ($name === '' || mb_strlen($name) > 80) {
            return $this->error('Tên phòng họp phải từ 1 đến 80 ký tự.', 400);
        }
        if (DB::table('meeting_rooms')->where('name', $name)->exists()) {
            return $this->error('Tên phòng họp này đã tồn tại.', 409);
        }

        $id = DB::table('meeting_rooms')->insertGetId(['name' => $name]);
        $this->broadcastAll('meetings');

        return response()->json(DB::table('meeting_rooms')
            ->where('id', $id)
            ->first(['id', 'name', 'active']), 201);
    }

    public function updateRoom(Request $request, string $id): JsonResponse
    {
        if (! $this->hasPermission($this->user($request), 'meeting_admin')) {
            return $this->error('Chỉ quản trị viên mới được quản lý phòng họp.', 403);
        }
        $roomId = filter_var($id, FILTER_VALIDATE_INT);
        $active = $request->input('active');
        $name = $request->input('name');
        if (! $roomId || ($active !== null && ! is_bool($active))
            || ($name !== null && (! is_string($name) || trim($name) === '' || mb_strlen(trim($name)) > 80))) {
            return $this->error('Thông tin phòng họp không hợp lệ.', 400);
        }

        $room = DB::table('meeting_rooms')->where('id', $roomId)->first();
        if (! $room) {
            return $this->error('Không tìm thấy phòng họp.', 404);
        }
        $cleanName = $name === null ? null : trim($name);
        if ($cleanName && DB::table('meeting_rooms')->where('name', $cleanName)->where('id', '!=', $roomId)->exists()) {
            return $this->error('Tên phòng họp này đã tồn tại.', 409);
        }
        $updates = [];
        if ($cleanName !== null) {
            $updates['name'] = $cleanName;
        }
        if ($active !== null) {
            $updates['active'] = (int) $active;
        }
        if ($updates) {
            DB::table('meeting_rooms')->where('id', $roomId)->update($updates);
            $this->broadcastAll('meetings');
        }

        return response()->json(DB::table('meeting_rooms')
            ->where('id', $roomId)
            ->first(['id', 'name', 'active']));
    }

    public function availability(Request $request): JsonResponse
    {
        $prepared = $this->prepareSchedule($request);
        if (isset($prepared['error'])) {
            return $this->error($prepared['error'], 400);
        }

        $conflicts = $this->findConflicts(
            $prepared['occurrences'],
            $prepared['room']?->id,
            $prepared['schedule']['startTime'],
            $prepared['schedule']['endTime'],
        );

        return response()->json([
            'available' => ! $conflicts,
            'conflicts' => array_slice($conflicts, 0, 20),
            'busyRoomIds' => array_values(array_unique(array_filter(array_column($conflicts, 'roomId')))),
        ]);
    }

    public function index(): JsonResponse
    {
        $meetings = DB::table('meetings')
            ->leftJoin('meeting_rooms', 'meeting_rooms.id', '=', 'meetings.room_id')
            ->leftJoin('users as creator', 'creator.id', '=', 'meetings.created_by')
            ->orderBy('meetings.meeting_date')
            ->orderBy('meetings.start_time')
            ->orderBy('meetings.id')
            ->get([
                'meetings.id',
                'meetings.title',
                'meetings.meeting_date as date',
                'meetings.end_date as endDate',
                'meetings.start_time as startTime',
                'meetings.end_time as endTime',
                'meetings.recurrence_type as type',
                'meetings.room_id as roomId',
                'meeting_rooms.name as roomName',
                'meetings.recurrence_group_id as recurrenceGroupId',
                'meetings.recurrence_days as recurrenceDays',
                'meetings.recurrence_end_date as recurrenceEndDate',
                'meetings.created_at as createdAt',
                'meetings.created_by as createdBy',
                'creator.name as creatorName',
            ]);

        foreach ($meetings as $meeting) {
            $meeting->createdBy = $this->responseUserId($meeting->createdBy);
            $meeting->recurrenceDays = $meeting->recurrenceDays ? json_decode($meeting->recurrenceDays, true) : [];
            $meeting->participants = DB::table('meeting_participants')
                ->join('users', 'users.id', '=', 'meeting_participants.user_id')
                ->where('meeting_participants.meeting_id', $meeting->id)
                ->orderBy('users.name')
                ->get(['users.id', 'users.name', 'users.email'])
                ->map(fn ($user) => [...(array) $user, 'id' => $this->responseUserId($user->id)]);
        }

        return response()->json($meetings);
    }

    public function store(Request $request): JsonResponse
    {
        $title = is_string($request->input('title')) ? trim($request->input('title')) : '';
        if ($title === '' || mb_strlen($title) > 120) {
            return $this->error('Tiêu đề cuộc họp phải từ 1 đến 120 ký tự.', 400);
        }
        $prepared = $this->prepareSchedule($request);
        if (isset($prepared['error'])) {
            return $this->error($prepared['error'], 400);
        }
        if (! $prepared['room']) {
            return $this->error('Chọn phòng họp.', 400);
        }

        $attendeeIds = $request->input('attendeeIds');
        if (! is_array($attendeeIds) || ! $attendeeIds || count($attendeeIds) > 100
            || array_filter($attendeeIds, fn ($id) => ! is_string($id))
            || count(array_unique($attendeeIds)) !== count($attendeeIds)) {
            return $this->error('Chọn ít nhất một người tham gia hợp lệ.', 400);
        }
        if (! $this->activeUsersExist($attendeeIds)) {
            return $this->error('Danh sách có tài khoản không còn hoạt động.', 400);
        }

        $schedule = $prepared['schedule'];
        $recurrenceGroupId = $schedule['type'] === 'weekly' ? (string) Str::uuid() : null;
        $recurrenceDays = null;
        if ($schedule['type'] === 'weekly') {
            $days = array_values(array_map('intval', $schedule['recurrenceDays']));
            sort($days, SORT_NUMERIC);
            $recurrenceDays = json_encode($days, JSON_THROW_ON_ERROR);
        }
        $created = DB::transaction(function () use (
            $prepared,
            $schedule,
            $title,
            $attendeeIds,
            $recurrenceGroupId,
            $recurrenceDays,
        ): array {
            DB::table('meeting_booking_lock')->where('id', 1)->lockForUpdate()->first();
            $conflict = $this->findConflicts(
                $prepared['occurrences'],
                $prepared['room']->id,
                $schedule['startTime'],
                $schedule['endTime'],
            )[0] ?? null;
            if ($conflict) {
                return ['conflict' => $conflict];
            }

            $ids = [];
            foreach ($prepared['occurrences'] as $occurrence) {
                $id = DB::table('meetings')->insertGetId([
                    'title' => $title,
                    'meeting_date' => $occurrence['date'],
                    'end_date' => $occurrence['endDate'],
                    'start_time' => $schedule['startTime'],
                    'end_time' => $schedule['endTime'],
                    'room_id' => $prepared['room']->id,
                    'created_by' => request()->attributes->get('workdesk_user')->id,
                    'recurrence_type' => $schedule['type'],
                    'recurrence_group_id' => $recurrenceGroupId,
                    'recurrence_days' => $recurrenceDays,
                    'recurrence_end_date' => $schedule['type'] === 'weekly'
                        ? $schedule['recurrenceEndDate']
                        : null,
                ]);
                $ids[] = $id;
                foreach ($attendeeIds as $userId) {
                    DB::table('meeting_participants')->insert([
                        'meeting_id' => $id,
                        'user_id' => $userId,
                    ]);
                }
            }

            return ['ids' => $ids];
        });

        if (isset($created['conflict'])) {
            $conflict = $created['conflict'];
            $timeLabel = $conflict['startTime'].'–'.$conflict['endTime'];
            $message = $conflict['conflictType'] === 'room'
                ? "Phòng \"{$conflict['roomName']}\" đang bận ngày {$conflict['date']}, {$timeLabel} (lịch \"{$conflict['title']}\")."
                : "Lịch công ty bị trùng với \"{$conflict['title']}\" ngày {$conflict['date']}, {$timeLabel}.";

            return response()->json(['error' => $message, 'conflict' => $conflict], 409);
        }

        $createdMeetings = array_map(fn ($id) => $this->findMeeting((int) $id), $created['ids']);
        $this->broadcastAll('meetings');

        return response()->json([
            ...$createdMeetings[0],
            'createdCount' => count($createdMeetings),
            'createdMeetings' => $createdMeetings,
        ], 201);
    }

    private function prepareSchedule(Request $request): array
    {
        $source = $request->isMethod('GET') ? $request->query() : $request->all();
        $recurrenceDays = $source['recurrenceDays'] ?? null;
        if ($request->isMethod('GET') && is_string($recurrenceDays) && $recurrenceDays !== '') {
            $recurrenceDays = json_decode($recurrenceDays, true);
            if (! is_array($recurrenceDays)) {
                return ['error' => 'Danh sách ngày lặp không hợp lệ.'];
            }
        }
        $roomId = empty($source['roomId']) ? null : filter_var($source['roomId'], FILTER_VALIDATE_INT);
        if (! empty($source['roomId']) && (! $roomId || $roomId < 1)) {
            return ['error' => 'Phòng họp không hợp lệ.'];
        }
        $room = $roomId === null
            ? null
            : DB::table('meeting_rooms')->where('id', $roomId)->where('active', 1)->first();
        if ($roomId !== null && ! $room) {
            return ['error' => 'Phòng họp không còn hoạt động. Hãy chọn phòng khác.'];
        }

        $schedule = [
            'type' => $source['type'] ?? 'single',
            'date' => $source['date'] ?? null,
            'endDate' => $source['endDate'] ?? null,
            'startTime' => $source['startTime'] ?? null,
            'endTime' => $source['endTime'] ?? null,
            'recurrenceDays' => $recurrenceDays,
            'recurrenceEndDate' => $source['recurrenceEndDate'] ?? null,
        ];
        if (! MeetingSchedule::validDate($schedule['date'])) {
            return ['error' => 'Ngày bắt đầu họp không hợp lệ.'];
        }
        if (! MeetingSchedule::validTime($schedule['startTime'])
            || ! MeetingSchedule::validTime($schedule['endTime'])) {
            return ['error' => 'Giờ bắt đầu hoặc kết thúc không hợp lệ.'];
        }
        $result = MeetingSchedule::occurrences($schedule);
        if (isset($result['error'])) {
            return $result;
        }

        return ['room' => $room, 'occurrences' => $result['occurrences'], 'schedule' => $schedule];
    }

    private function findConflicts(array $occurrences, ?int $roomId, string $startTime, string $endTime): array
    {
        $existing = DB::table('meetings')
            ->leftJoin('meeting_rooms', 'meeting_rooms.id', '=', 'meetings.room_id')
            ->get([
                'meetings.id',
                'meetings.meeting_date as date',
                'meetings.end_date as endDate',
                'meetings.start_time as startTime',
                'meetings.end_time as endTime',
                'meetings.title',
                'meetings.room_id as roomId',
                'meeting_rooms.name as roomName',
            ]);
        $conflicts = [];
        foreach ($occurrences as $occurrence) {
            $candidate = [...$occurrence, 'startTime' => $startTime, 'endTime' => $endTime];
            foreach ($existing as $meeting) {
                if (! MeetingSchedule::overlaps($candidate, $meeting)) {
                    continue;
                }
                $conflict = (array) $meeting;
                $conflict['date'] = $occurrence['date'];
                $conflict['conflictType'] = $roomId !== null && (int) $meeting->roomId === $roomId
                    ? 'room'
                    : 'schedule';
                $conflicts[] = $conflict;
            }
        }

        return $conflicts;
    }

    private function findMeeting(int $id): array
    {
        $meeting = DB::table('meetings')
            ->leftJoin('meeting_rooms', 'meeting_rooms.id', '=', 'meetings.room_id')
            ->leftJoin('users as creator', 'creator.id', '=', 'meetings.created_by')
            ->where('meetings.id', $id)
            ->first([
                'meetings.id',
                'meetings.title',
                'meetings.meeting_date as date',
                'meetings.end_date as endDate',
                'meetings.start_time as startTime',
                'meetings.end_time as endTime',
                'meetings.recurrence_type as type',
                'meetings.room_id as roomId',
                'meeting_rooms.name as roomName',
                'meetings.recurrence_group_id as recurrenceGroupId',
                'meetings.recurrence_days as recurrenceDays',
                'meetings.recurrence_end_date as recurrenceEndDate',
                'meetings.created_at as createdAt',
                'meetings.created_by as createdBy',
                'creator.name as creatorName',
            ]);
        $data = (array) $meeting;
        $data['createdBy'] = $this->responseUserId($meeting->createdBy);
        $data['recurrenceDays'] = $meeting->recurrenceDays ? json_decode($meeting->recurrenceDays, true) : [];
        $data['participants'] = DB::table('meeting_participants')
            ->join('users', 'users.id', '=', 'meeting_participants.user_id')
            ->where('meeting_participants.meeting_id', $id)
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.email'])
            ->map(fn ($user) => [...(array) $user, 'id' => $this->responseUserId($user->id)])
            ->all();

        return $data;
    }

    private function broadcastAll(string $resource): void
    {
        WorkdeskBroadcast::users(
            DB::table('users')->where('active', 1)->pluck('id'),
            $resource,
        );
    }
}
