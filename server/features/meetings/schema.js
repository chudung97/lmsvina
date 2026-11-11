const meetingTableSchema = `
  CREATE TABLE meetings_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    meeting_date TEXT NOT NULL,
    end_date TEXT NOT NULL,
    start_time TEXT NOT NULL,
    end_time TEXT NOT NULL,
    room_id INTEGER REFERENCES meeting_rooms(id),
    created_by TEXT REFERENCES users(id),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    recurrence_type TEXT NOT NULL DEFAULT 'single',
    recurrence_group_id TEXT,
    recurrence_days TEXT,
    recurrence_end_date TEXT
  )
`

export function migrateMeetingsSchema(db) {
  const table = db.prepare(`
    SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'meetings'
  `).get()
  if (!table) return

  const columns = new Set(db.prepare('PRAGMA table_info(meetings)').all().map(({ name }) => name))
  const needsRebuild = !columns.has('end_date')
    || !columns.has('recurrence_type')
    || !columns.has('recurrence_group_id')
    || !columns.has('recurrence_days')
    || !columns.has('recurrence_end_date')
    || !columns.has('room_id')
    || /CHECK\s*\(\s*end_time\s*>\s*start_time\s*\)/i.test(table.sql)
  if (!needsRebuild) return

  const roomIdColumn = columns.has('room_id') ? 'room_id' : 'NULL'
  db.pragma('foreign_keys = OFF')
  try {
    db.exec('BEGIN IMMEDIATE')
    db.exec(meetingTableSchema)
    db.exec(`
      INSERT INTO meetings_new (
        id, title, meeting_date, end_date, start_time, end_time,
        room_id, created_by, created_at, recurrence_type
      )
      SELECT id, title, meeting_date, meeting_date, start_time, end_time,
        ${roomIdColumn}, created_by, created_at, 'single'
      FROM meetings
    `)
    db.exec('DROP TABLE meetings')
    db.exec('ALTER TABLE meetings_new RENAME TO meetings')
    db.exec('COMMIT')
  } catch (error) {
    db.exec('ROLLBACK')
    throw error
  } finally {
    db.pragma('foreign_keys = ON')
  }

  const violations = db.pragma('foreign_key_check')
  if (violations.length) {
    throw new Error(`Meeting schema migration left ${violations.length} foreign-key violation(s).`)
  }
}
