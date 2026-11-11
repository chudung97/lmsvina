import assert from 'node:assert/strict'
import { once } from 'node:events'
import express from 'express'
import test from 'node:test'
import Database from 'better-sqlite3'
import { createMeetingsRouter } from '../server/features/meetings/routes.js'
import { migrateMeetingsSchema } from '../server/features/meetings/schema.js'

function createDatabase() {
  const db = new Database(':memory:')
  db.pragma('foreign_keys = ON')
  db.exec(`
    CREATE TABLE users (
      id TEXT PRIMARY KEY,
      name TEXT NOT NULL,
      email TEXT,
      active INTEGER NOT NULL
    );
    INSERT INTO users (id, name, email, active) VALUES
      ('requester', 'Người lập', 'requester@example.test', 1),
      ('participant', 'Người tham gia', 'participant@example.test', 1),
      ('inactive', 'Tài khoản khóa', 'inactive@example.test', 0);
    CREATE TABLE meeting_rooms (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      name TEXT NOT NULL COLLATE NOCASE UNIQUE,
      active INTEGER NOT NULL DEFAULT 1
    );
    INSERT INTO meeting_rooms (id, name) VALUES (1, 'Phòng họp A'), (2, 'Phòng họp B');
    CREATE TABLE meetings (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      title TEXT NOT NULL,
      meeting_date TEXT NOT NULL,
      end_date TEXT NOT NULL,
      start_time TEXT NOT NULL,
      end_time TEXT NOT NULL,
      room_id INTEGER REFERENCES meeting_rooms(id),
      created_by TEXT,
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      recurrence_type TEXT NOT NULL DEFAULT 'single',
      recurrence_group_id TEXT,
      recurrence_days TEXT,
      recurrence_end_date TEXT
    );
    CREATE TABLE meeting_participants (
      meeting_id INTEGER NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
      user_id TEXT NOT NULL REFERENCES users(id),
      PRIMARY KEY (meeting_id, user_id)
    );
  `)
  return db
}

async function startApi(context, db) {
  const app = express()
  app.use(express.json())
  app.use((request, _response, next) => {
    request.user = {
      id: 'requester',
      name: 'Người lập',
      role: request.get('x-user-role') || 'member',
    }
    next()
  })
  app.use('/api/meetings', createMeetingsRouter(db))
  const server = app.listen(0, '127.0.0.1')
  await once(server, 'listening')
  context.after(() => {
    server.close()
    db.close()
  })
  const base = `http://127.0.0.1:${server.address().port}/api/meetings`
  return async (path, body, userId = 'requester', role = 'member', method) => {
    const response = await fetch(`${base}${path}`, {
      method: method || (body ? 'POST' : 'GET'),
      headers: {
        'x-user-id': userId,
        'x-user-role': role,
        ...(body ? { 'content-type': 'application/json' } : {}),
      },
      ...(body ? { body: JSON.stringify(body) } : {}),
    })
    return { status: response.status, data: await response.json() }
  }
}

function meetingPayload(overrides = {}) {
  return {
    title: 'Họp kiểm thử',
    type: 'single',
    date: '2026-10-26',
    endDate: '2026-10-26',
    startTime: '09:00',
    endTime: '10:00',
    roomId: 1,
    attendeeIds: ['requester'],
    ...overrides,
  }
}

test('single-day meetings reject global overlaps but allow adjacent times', async (context) => {
  const db = createDatabase()
  const call = await startApi(context, db)
  const created = await call('/', meetingPayload())
  assert.equal(created.status, 201)
  assert.equal(created.data.createdCount, 1)
  assert.deepEqual(created.data.participants.map((user) => user.id), ['requester'])

  const overlap = await call('/', meetingPayload({
    title: 'Lịch của người khác',
    startTime: '09:59',
    endTime: '10:30',
    attendeeIds: ['participant'],
  }))
  assert.equal(overlap.status, 409)
  assert.match(overlap.data.error, /Họp kiểm thử/)

  const adjacent = await call('/', meetingPayload({
    title: 'Lịch liền kề',
    startTime: '10:00',
    endTime: '10:30',
  }))
  assert.equal(adjacent.status, 201)
  assert.equal((await call('/')).data.length, 2)
})

test('room availability is checked immediately and enforced for all room bookings', async (context) => {
  const db = createDatabase()
  const call = await startApi(context, db)
  const created = await call('/', meetingPayload({ title: 'Phòng A đã đặt' }))
  assert.equal(created.status, 201)
  assert.equal(created.data.roomId, 1)
  assert.equal(created.data.roomName, 'Phòng họp A')

  const availabilityQuery = new URLSearchParams({
    type: 'single',
    date: '2026-10-26',
    startTime: '09:30',
    endTime: '10:30',
  })
  const availability = await call(`/availability?${availabilityQuery}`)
  assert.equal(availability.status, 200)
  assert.equal(availability.data.available, false)
  assert.deepEqual(availability.data.busyRoomIds, [1])

  const roomConflict = await call('/', meetingPayload({
    title: 'Đặt phòng A trùng giờ',
    startTime: '09:30',
    endTime: '10:30',
  }))
  assert.equal(roomConflict.status, 409)
  assert.match(roomConflict.data.error, /Phòng "Phòng họp A" đang bận/)

  const companyConflict = await call('/', meetingPayload({
    title: 'Đặt phòng B trùng giờ công ty',
    roomId: 2,
    startTime: '09:30',
    endTime: '10:30',
  }))
  assert.equal(companyConflict.status, 409)
  assert.match(companyConflict.data.error, /Lịch công ty bị trùng với/)

  const roomB = await call('/', meetingPayload({
    title: 'Phòng B còn trống',
    roomId: 2,
    startTime: '10:00',
    endTime: '11:00',
  }))
  assert.equal(roomB.status, 201)
  assert.equal(roomB.data.roomName, 'Phòng họp B')
})

test('room catalogue is admin-managed and inactive rooms cannot be booked', async (context) => {
  const db = createDatabase()
  const call = await startApi(context, db)

  assert.equal((await call('/rooms')).data.length, 2)
  assert.equal((await call('/rooms', { name: 'Phòng họp C' })).status, 403)
  const created = await call('/rooms', { name: '  Phòng họp C  ' }, 'requester', 'admin')
  assert.equal(created.status, 201)
  assert.equal(created.data.name, 'Phòng họp C')
  assert.equal((await call('/rooms', { name: 'Phòng họp C' }, 'requester', 'admin')).status, 409)
  assert.equal((await call('/rooms/3', { name: 'Phòng họp D' }, 'requester', 'member', 'PATCH')).status, 403)
  const renamed = await call('/rooms/3', { name: 'Phòng họp D' }, 'requester', 'admin', 'PATCH')
  assert.equal(renamed.data.name, 'Phòng họp D')
  assert.equal((await call('/rooms/3', { active: false }, 'requester', 'admin', 'PATCH')).data.active, 0)

  const disabledRoom = await call('/', meetingPayload({ roomId: 3 }))
  assert.equal(disabledRoom.status, 400)
  assert.match(disabledRoom.data.error, /không còn hoạt động/)
})

test('overnight meetings block conflicts on the next calendar day', async (context) => {
  const db = createDatabase()
  const call = await startApi(context, db)
  const overnight = await call('/', meetingPayload({
    type: 'overnight',
    date: '2026-10-25',
    endDate: '2026-10-26',
    startTime: '22:00',
    endTime: '02:00',
  }))
  assert.equal(overnight.status, 201)
  assert.equal(overnight.data.date, '2026-10-25')
  assert.equal(overnight.data.endDate, '2026-10-26')

  const overlap = await call('/', meetingPayload({
    date: '2026-10-26',
    startTime: '01:30',
    endTime: '03:00',
  }))
  assert.equal(overlap.status, 409)

  const adjacent = await call('/', meetingPayload({
    date: '2026-10-26',
    startTime: '02:00',
    endTime: '03:00',
  }))
  assert.equal(adjacent.status, 201)

  const recurrenceConflict = await call('/', meetingPayload({
    title: 'Lịch lặp trùng phòng',
    roomId: 1,
    type: 'weekly',
    date: '2026-10-26',
    recurrenceDays: [0],
    recurrenceEndDate: '2026-11-02',
    startTime: '01:00',
    endTime: '03:00',
  }))
  assert.equal(recurrenceConflict.status, 409)
  assert.match(recurrenceConflict.data.error, /Phòng "Phòng họp A" đang bận/)
})

test('weekly meetings create each weekday occurrence and fail atomically on a conflict', async (context) => {
  const db = createDatabase()
  const call = await startApi(context, db)
  const weekly = await call('/', meetingPayload({
    type: 'weekly',
    date: '2026-10-26',
    recurrenceDays: [0, 2],
    recurrenceEndDate: '2026-11-04',
    startTime: '11:00',
    endTime: '12:00',
  }))
  assert.equal(weekly.status, 201)
  assert.equal(weekly.data.createdCount, 4)
  assert.deepEqual(weekly.data.createdMeetings.map((meeting) => meeting.date), [
    '2026-10-26',
    '2026-10-28',
    '2026-11-02',
    '2026-11-04',
  ])
  assert.deepEqual(weekly.data.recurrenceDays, [0, 2])
  assert.ok(weekly.data.recurrenceGroupId)
  assert.equal((await call('/')).data.length, 4)

  const conflicting = await call('/', meetingPayload({
    title: 'Lặp bị trùng',
    type: 'weekly',
    date: '2026-10-26',
    recurrenceDays: [0],
    recurrenceEndDate: '2026-11-09',
    startTime: '11:30',
    endTime: '12:30',
  }))
  assert.equal(conflicting.status, 409)
  assert.equal((await call('/')).data.length, 4)
})

test('meeting validation rejects invalid date ranges, times and recurrence rules', async (context) => {
  const db = createDatabase()
  const call = await startApi(context, db)

  assert.equal((await call('/', meetingPayload({ date: '2026-02-30' }))).status, 400)
  assert.equal((await call('/', meetingPayload({ endTime: '09:00' }))).status, 400)
  assert.equal((await call('/', meetingPayload({
    type: 'overnight',
    endDate: '2026-10-26',
  }))).status, 400)
  assert.equal((await call('/', meetingPayload({
    type: 'weekly',
    recurrenceDays: [],
    recurrenceEndDate: '2026-11-02',
  }))).status, 400)
  assert.equal((await call('/', meetingPayload({
    type: 'weekly',
    recurrenceDays: [1, 1],
    recurrenceEndDate: '2026-11-02',
  }))).status, 400)
  assert.equal((await call('/', meetingPayload({
    type: 'weekly',
    recurrenceDays: [0],
    recurrenceEndDate: '2026-10-25',
  }))).status, 400)
  assert.equal((await call('/', meetingPayload({ attendeeIds: ['inactive'] }))).status, 400)
})

test('legacy meeting migration preserves records and participant foreign keys', () => {
  const db = new Database(':memory:')
  db.pragma('foreign_keys = ON')
  db.exec(`
    CREATE TABLE users (id TEXT PRIMARY KEY);
    INSERT INTO users VALUES ('u1');
    CREATE TABLE meeting_rooms (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      name TEXT NOT NULL COLLATE NOCASE UNIQUE,
      active INTEGER NOT NULL DEFAULT 1
    );
    INSERT INTO meeting_rooms VALUES (1, 'Phòng họp A', 1);
    CREATE TABLE meetings (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      title TEXT NOT NULL,
      meeting_date TEXT NOT NULL,
      start_time TEXT NOT NULL,
      end_time TEXT NOT NULL,
      room_id INTEGER REFERENCES meeting_rooms(id),
      created_by TEXT REFERENCES users(id),
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      CHECK (end_time > start_time)
    );
    INSERT INTO meetings (title, meeting_date, start_time, end_time, created_by)
      VALUES ('Lịch cũ', '2026-10-26', '09:00', '10:00', 'u1');
    CREATE TABLE meeting_participants (
      meeting_id INTEGER NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
      user_id TEXT NOT NULL REFERENCES users(id),
      PRIMARY KEY (meeting_id, user_id)
    );
    INSERT INTO meeting_participants VALUES (1, 'u1');
  `)

  migrateMeetingsSchema(db)

  assert.deepEqual(db.prepare(`
    SELECT     id, title, meeting_date, end_date, recurrence_type, room_id FROM meetings
  `).all(), [{
    id: 1,
    title: 'Lịch cũ',
    meeting_date: '2026-10-26',
    end_date: '2026-10-26',
    recurrence_type: 'single',
    room_id: null,
  }])
  assert.deepEqual(db.prepare('SELECT * FROM meeting_participants').all(), [{
    meeting_id: 1,
    user_id: 'u1',
  }])
  assert.deepEqual(db.pragma('foreign_key_check'), [])
  const overnight = db.prepare(`
    INSERT INTO meetings (title, meeting_date, end_date, start_time, end_time)
    VALUES ('Qua ngày', '2026-10-26', '2026-10-27', '22:00', '02:00')
  `).run()
  assert.equal(db.prepare('SELECT end_time FROM meetings WHERE id = ?').get(overnight.lastInsertRowid).end_time, '02:00')
  db.close()
})

test('meeting schema migration retains room assignments when rebuilding a table', () => {
  const db = new Database(':memory:')
  db.pragma('foreign_keys = ON')
  db.exec(`
    CREATE TABLE users (id TEXT PRIMARY KEY);
    INSERT INTO users VALUES ('u1');
    CREATE TABLE meeting_rooms (
      id INTEGER PRIMARY KEY,
      name TEXT NOT NULL,
      active INTEGER NOT NULL DEFAULT 1
    );
    INSERT INTO meeting_rooms VALUES (7, 'Phòng đã đặt', 1);
    CREATE TABLE meetings (
      id INTEGER PRIMARY KEY,
      title TEXT NOT NULL,
      meeting_date TEXT NOT NULL,
      end_date TEXT NOT NULL,
      start_time TEXT NOT NULL,
      end_time TEXT NOT NULL,
      room_id INTEGER REFERENCES meeting_rooms(id),
      created_by TEXT REFERENCES users(id),
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      recurrence_type TEXT NOT NULL DEFAULT 'single',
      CHECK (end_time > start_time)
    );
    INSERT INTO meetings (id, title, meeting_date, end_date, start_time, end_time, room_id)
      VALUES (2, 'Giữ nguyên phòng', '2026-10-26', '2026-10-26', '09:00', '10:00', 7);
  `)

  migrateMeetingsSchema(db)

  assert.equal(db.prepare('SELECT room_id FROM meetings WHERE id = 2').get().room_id, 7)
  assert.deepEqual(db.pragma('foreign_key_check'), [])
  db.close()
})
