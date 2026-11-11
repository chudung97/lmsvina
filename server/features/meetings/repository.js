import { createDatabaseClient } from '../../database.js'

export function createMeetingsRepository(db) {
  const database = createDatabaseClient(db)
  const meetingFields = `
    meetings.id, meetings.title, meetings.meeting_date AS date,
    meetings.end_date AS endDate, meetings.start_time AS startTime,
    meetings.end_time AS endTime, meetings.recurrence_type AS type,
    meetings.room_id AS roomId, meeting_rooms.name AS roomName,
    meetings.recurrence_group_id AS recurrenceGroupId,
    meetings.recurrence_days AS recurrenceDays,
    meetings.recurrence_end_date AS recurrenceEndDate,
    meetings.created_at AS createdAt, meetings.created_by AS createdBy,
    creator.name AS creatorName
  `
  const meetingSelect = `
    SELECT ${meetingFields}
    FROM meetings
    LEFT JOIN meeting_rooms ON meeting_rooms.id = meetings.room_id
    LEFT JOIN users AS creator ON creator.id = meetings.created_by
  `
  const conflictSelect = `
    SELECT meetings.id, meetings.meeting_date AS date, meetings.end_date AS endDate,
      meetings.start_time AS startTime, meetings.end_time AS endTime,
      meetings.title, meetings.room_id AS roomId, meeting_rooms.name AS roomName
    FROM meetings
    LEFT JOIN meeting_rooms ON meeting_rooms.id = meetings.room_id
  `

  return {
    async listUsers() {
      return database.all(`
        SELECT id, name, email
        FROM users
        WHERE active = 1 AND email IS NOT NULL
        ORDER BY name
      `)
    },

    async listRooms(includeInactive) {
      return database.all(`
        SELECT id, name, active FROM meeting_rooms
        ${includeInactive ? '' : 'WHERE active = 1'}
        ORDER BY name
      `)
    },

    async roomNameExists(name, exceptId = null) {
      const row = exceptId === null
        ? await database.get('SELECT 1 FROM meeting_rooms WHERE name = ?', [name])
        : await database.get('SELECT 1 FROM meeting_rooms WHERE name = ? AND id != ?', [name, exceptId])
      return Boolean(row)
    },

    async createRoom(name) {
      const result = await database.run('INSERT INTO meeting_rooms (name) VALUES (?)', [name])
      return database.get('SELECT id, name, active FROM meeting_rooms WHERE id = ?', [result.insertId])
    },

    async findRoom(roomId) {
      return database.get('SELECT id FROM meeting_rooms WHERE id = ?', [roomId])
    },

    async findActiveRoom(roomId) {
      return database.get(
        'SELECT id, name FROM meeting_rooms WHERE id = ? AND active = 1',
        [roomId],
      )
    },

    async updateRoom(roomId, name, active) {
      await database.run(`
        UPDATE meeting_rooms
        SET name = COALESCE(?, name), active = COALESCE(?, active)
        WHERE id = ?
      `, [name, active, roomId])
      return database.get('SELECT id, name, active FROM meeting_rooms WHERE id = ?', [roomId])
    },

    async listMeetings() {
      return database.all(`${meetingSelect}
        ORDER BY meetings.meeting_date, meetings.start_time, meetings.id
      `)
    },

    async getMeeting(id) {
      return database.get(`${meetingSelect} WHERE meetings.id = ?`, [id])
    },

    async listParticipants(meetingId) {
      return database.all(`
        SELECT users.id, users.name, users.email
        FROM meeting_participants
        JOIN users ON users.id = meeting_participants.user_id
        WHERE meeting_participants.meeting_id = ?
        ORDER BY users.name
      `, [meetingId])
    },

    async findConflicts() {
      return database.all(conflictSelect)
    },

    async countActiveAttendees(attendeeIds) {
      if (!attendeeIds.length) return 0
      const placeholders = attendeeIds.map(() => '?').join(',')
      const result = await database.get(`
        SELECT COUNT(*) AS count FROM users
        WHERE id IN (${placeholders}) AND active = 1 AND email IS NOT NULL
      `, attendeeIds)
      return Number(result.count)
    },

    async createMeetings(input, attendees) {
      return database.transaction(async (transaction) => {
        if (!database.isSqlite) {
          await transaction.get('SELECT id FROM meeting_booking_lock WHERE id = 1 FOR UPDATE')
        }
        const existingMeetings = await transaction.all(conflictSelect)
        const conflict = input.findConflict(existingMeetings)
        if (conflict) return { conflict }

        const meetingIds = []
        for (const occurrence of input.occurrences) {
          const result = await transaction.run(`
            INSERT INTO meetings (
              title, meeting_date, end_date, start_time, end_time, room_id, created_by,
              recurrence_type, recurrence_group_id, recurrence_days, recurrence_end_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
          `, [
            input.title,
            occurrence.date,
            occurrence.endDate,
            input.startTime,
            input.endTime,
            input.roomId,
            input.createdBy,
            input.type,
            input.recurrenceGroupId,
            input.recurrenceDays,
            input.recurrenceEndDate,
          ])
          meetingIds.push(result.insertId)
        }

        for (const meetingId of meetingIds) {
          for (const userId of attendees) {
            await transaction.run(`
              INSERT INTO meeting_participants (meeting_id, user_id) VALUES (?, ?)
            `, [meetingId, userId])
          }
        }

        return { meetingIds }
      })
    },
  }
}
