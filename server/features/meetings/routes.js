import { randomUUID } from 'node:crypto'
import { Router } from 'express'
import { createMeetingsRepository } from './repository.js'
import { authorizationDisabled } from '../../../auth.js'

const dayMilliseconds = 24 * 60 * 60 * 1000
const maximumRecurringOccurrences = 520
const maximumRecurrenceDays = 3660

function isValidDate(value) {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return false
  const date = new Date(`${value}T00:00:00.000Z`)
  return !Number.isNaN(date.valueOf()) && date.toISOString().slice(0, 10) === value
}

function isValidTime(value) {
  return typeof value === 'string' && /^([01]\d|2[0-3]):[0-5]\d$/.test(value)
}

function dateAtOffset(value, offset) {
  const timestamp = new Date(`${value}T00:00:00.000Z`).valueOf() + offset * dayMilliseconds
  return new Date(timestamp).toISOString().slice(0, 10)
}

function weekdayForDate(value) {
  return (new Date(`${value}T00:00:00.000Z`).getUTCDay() + 6) % 7
}

function toMinutes(time) {
  const [hours, minutes] = time.split(':').map(Number)
  return hours * 60 + minutes
}

function meetingInterval(meeting) {
  const start = new Date(`${meeting.date}T00:00:00.000Z`).valueOf()
    + toMinutes(meeting.startTime) * 60_000
  const end = new Date(`${meeting.endDate}T00:00:00.000Z`).valueOf()
    + toMinutes(meeting.endTime) * 60_000
  return { start, end }
}

function findOverlap(first, second) {
  const firstInterval = meetingInterval(first)
  const secondInterval = meetingInterval(second)
  return firstInterval.start < secondInterval.end && secondInterval.start < firstInterval.end
}

function createOccurrences(input) {
  const {
    type, date, endDate, startTime, endTime, recurrenceDays, recurrenceEndDate,
  } = input

  if (type === 'single') {
    if (endTime <= startTime) {
      return { error: 'Giờ kết thúc phải sau giờ bắt đầu trong cùng một ngày.' }
    }
    return { occurrences: [{ date, endDate: date }] }
  }

  if (type === 'overnight') {
    if (!isValidDate(endDate) || endDate <= date) {
      return { error: 'Ngày kết thúc phải sau ngày bắt đầu.' }
    }
    return { occurrences: [{ date, endDate }] }
  }

  if (type !== 'weekly') return { error: 'Loại lịch họp không hợp lệ.' }
  if (!isValidTime(endTime) || endTime <= startTime) {
    return { error: 'Lịch lặp hàng tuần phải kết thúc sau giờ bắt đầu trong ngày.' }
  }
  if (!isValidDate(recurrenceEndDate) || recurrenceEndDate < date) {
    return { error: 'Ngày kết thúc lặp phải từ ngày bắt đầu trở đi.' }
  }
  const rangeDays = (new Date(`${recurrenceEndDate}T00:00:00.000Z`).valueOf()
    - new Date(`${date}T00:00:00.000Z`).valueOf()) / dayMilliseconds
  if (rangeDays > maximumRecurrenceDays) {
    return { error: 'Ngày kết thúc lặp không được quá 10 năm kể từ ngày bắt đầu.' }
  }
  if (!Array.isArray(recurrenceDays) || !recurrenceDays.length
    || recurrenceDays.some((day) => !Number.isInteger(day) || day < 0 || day > 6)
    || new Set(recurrenceDays).size !== recurrenceDays.length) {
    return { error: 'Chọn ít nhất một thứ trong tuần cho lịch lặp.' }
  }

  const selectedDays = new Set(recurrenceDays)
  const occurrences = []
  for (let offset = 0; offset <= rangeDays; offset += 1) {
    const occurrenceDate = dateAtOffset(date, offset)
    if (!selectedDays.has(weekdayForDate(occurrenceDate))) continue
    occurrences.push({ date: occurrenceDate, endDate: occurrenceDate })
    if (occurrences.length > maximumRecurringOccurrences) {
      return { error: `Lịch lặp không được vượt quá ${maximumRecurringOccurrences} cuộc họp.` }
    }
  }
  if (!occurrences.length) {
    return { error: 'Không có buổi họp nào khớp với các thứ đã chọn trong khoảng ngày này.' }
  }

  return { occurrences }
}

export function createMeetingsRouter(db) {
  const router = Router()
  const meetings = createMeetingsRepository(db)

  async function toMeeting(meeting) {
    return {
      ...meeting,
      recurrenceDays: meeting.recurrenceDays ? JSON.parse(meeting.recurrenceDays) : [],
      participants: await meetings.listParticipants(meeting.id),
    }
  }

  router.get('/users', async (_request, response) => {
    const users = await meetings.listUsers()
    response.json(users)
  })

  router.get('/rooms', async (request, response) => {
    const includeInactive = authorizationDisabled() || request.user.role === 'admin'
    const rooms = await meetings.listRooms(includeInactive)
    response.json(rooms)
  })

  router.post('/rooms', async (request, response) => {
    if (!authorizationDisabled() && request.user.role !== 'admin') {
      return response.status(403).json({ error: 'Chỉ quản trị viên mới được quản lý phòng họp.' })
    }
    const name = typeof request.body?.name === 'string' ? request.body.name.trim() : ''
    if (!name || name.length > 80) {
      return response.status(400).json({ error: 'Tên phòng họp phải từ 1 đến 80 ký tự.' })
    }
    if (await meetings.roomNameExists(name)) {
      return response.status(409).json({ error: 'Tên phòng họp này đã tồn tại.' })
    }
    response.status(201).json(await meetings.createRoom(name))
  })

  router.patch('/rooms/:id', async (request, response) => {
    if (!authorizationDisabled() && request.user.role !== 'admin') {
      return response.status(403).json({ error: 'Chỉ quản trị viên mới được quản lý phòng họp.' })
    }
    const roomId = Number(request.params.id)
    const { active, name } = request.body ?? {}
    if (!Number.isSafeInteger(roomId) || roomId < 1
      || (active !== undefined && typeof active !== 'boolean')
      || (name !== undefined && (typeof name !== 'string' || !name.trim() || name.trim().length > 80))) {
      return response.status(400).json({ error: 'Thông tin phòng họp không hợp lệ.' })
    }
    const room = await meetings.findRoom(roomId)
    if (!room) return response.status(404).json({ error: 'Không tìm thấy phòng họp.' })
    const cleanName = name === undefined ? null : name.trim()
    if (cleanName && await meetings.roomNameExists(cleanName, roomId)) {
      return response.status(409).json({ error: 'Tên phòng họp này đã tồn tại.' })
    }
    response.json(await meetings.updateRoom(
      roomId,
      cleanName,
      active === undefined ? null : Number(active),
    ))
  })

  async function getSchedule(request) {
    let recurrenceDays = request.body?.recurrenceDays
    if (request.method === 'GET' && request.query.recurrenceDays) {
      try {
        recurrenceDays = JSON.parse(request.query.recurrenceDays)
      } catch {
        return { error: 'Danh sách ngày lặp không hợp lệ.' }
      }
    }
    const source = request.method === 'GET' ? request.query : request.body ?? {}
    const roomId = source.roomId ? Number(source.roomId) : null
    if (roomId !== null && (!Number.isSafeInteger(roomId) || roomId < 1)) {
      return { error: 'Phòng họp không hợp lệ.' }
    }
    const room = roomId === null
      ? null
      : await meetings.findActiveRoom(roomId)
    if (roomId !== null && !room) {
      return { error: 'Phòng họp không còn hoạt động. Hãy chọn phòng khác.' }
    }
    const schedule = {
      type: source.type ?? 'single',
      date: source.date,
      endDate: source.endDate,
      startTime: source.startTime,
      endTime: source.endTime,
      recurrenceDays,
      recurrenceEndDate: source.recurrenceEndDate,
    }
    if (!isValidDate(schedule.date)) return { error: 'Ngày bắt đầu họp không hợp lệ.' }
    if (!isValidTime(schedule.startTime) || !isValidTime(schedule.endTime)) {
      return { error: 'Giờ bắt đầu hoặc kết thúc không hợp lệ.' }
    }
    const result = createOccurrences(schedule)
    if (result.error) return result
    return { room, occurrences: result.occurrences, schedule }
  }

  function findScheduleConflicts(occurrences, roomId, existingMeetings, startTime, endTime) {
    const conflicts = []
    for (const occurrence of occurrences) {
      const candidate = {
        ...occurrence,
        startTime,
        endTime,
      }
      for (const meeting of existingMeetings) {
        if (!findOverlap(candidate, meeting)) continue
        conflicts.push({
          ...meeting,
          date: occurrence.date,
          conflictType: roomId !== null && meeting.roomId === roomId ? 'room' : 'schedule',
        })
      }
    }
    return conflicts
  }

  router.get('/availability', async (request, response) => {
    const prepared = await getSchedule(request)
    if (prepared.error) return response.status(400).json({ error: prepared.error })
    const conflicts = findScheduleConflicts(
      prepared.occurrences,
      prepared.room?.id ?? null,
      await meetings.findConflicts(),
      prepared.schedule.startTime,
      prepared.schedule.endTime,
    )
    response.json({
      available: !conflicts.length,
      conflicts: conflicts.slice(0, 20),
      busyRoomIds: [...new Set(conflicts.map((meeting) => meeting.roomId).filter(Boolean))],
    })
  })

  router.get('/', async (_request, response) => {
    const items = await meetings.listMeetings()
    response.json(await Promise.all(items.map(toMeeting)))
  })

  router.post('/', async (request, response) => {
    const {
      title, attendeeIds,
    } = request.body ?? {}
    const cleanTitle = typeof title === 'string' ? title.trim() : ''

    if (!cleanTitle || cleanTitle.length > 120) {
      return response.status(400).json({ error: 'Tiêu đề cuộc họp phải từ 1 đến 120 ký tự.' })
    }
    const prepared = await getSchedule(request)
    if (prepared.error) return response.status(400).json({ error: prepared.error })
    const { schedule, room, occurrences } = prepared
    if (!room) return response.status(400).json({ error: 'Chọn phòng họp.' })
    const { startTime, endTime, recurrenceDays, recurrenceEndDate } = schedule
    if (!Array.isArray(attendeeIds) || !attendeeIds.length || attendeeIds.length > 100
      || attendeeIds.some((id) => typeof id !== 'string')
      || new Set(attendeeIds).size !== attendeeIds.length) {
      return response.status(400).json({ error: 'Chọn ít nhất một người tham gia hợp lệ.' })
    }
    const validAttendees = await meetings.countActiveAttendees(attendeeIds)
    if (validAttendees !== attendeeIds.length) {
      return response.status(400).json({ error: 'Danh sách có tài khoản không còn hoạt động.' })
    }

    const recurrenceGroupId = schedule.type === 'weekly' ? randomUUID() : null
    const serializedDays = schedule.type === 'weekly' ? JSON.stringify([...recurrenceDays].sort()) : null
    const result = await meetings.createMeetings({
      title: cleanTitle,
      startTime,
      endTime,
      roomId: room.id,
      createdBy: request.user.id,
      type: schedule.type,
      recurrenceGroupId,
      recurrenceDays: serializedDays,
      recurrenceEndDate: schedule.type === 'weekly' ? recurrenceEndDate : null,
      occurrences,
      findConflict: (existingMeetings) => findScheduleConflicts(
        occurrences,
        room.id,
        existingMeetings,
        startTime,
        endTime,
      )[0],
    }, attendeeIds)
    if (result.conflict) {
      const dateLabel = result.conflict.date
      const timeLabel = `${result.conflict.startTime}–${result.conflict.endTime}`
      return response.status(409).json({
        error: result.conflict.conflictType === 'room'
          ? `Phòng "${result.conflict.roomName}" đang bận ngày ${dateLabel}, ${timeLabel} (lịch "${result.conflict.title}").`
          : `Lịch công ty bị trùng với "${result.conflict.title}" ngày ${dateLabel}, ${timeLabel}.`,
        conflict: result.conflict,
      })
    }
    const createdMeetings = await Promise.all(result.meetingIds.map(async (meetingId) =>
      toMeeting(await meetings.getMeeting(meetingId))))
    response.status(201).json({
      ...createdMeetings[0],
      createdCount: createdMeetings.length,
      createdMeetings,
    })
  })

  return router
}
