import 'dotenv/config'
import { existsSync } from 'node:fs'
import path from 'node:path'
import Database from 'better-sqlite3'
import mysql from 'mysql2/promise'
import { createDatabaseClient } from '../server/database.js'
import { initializeSchema } from '../server/database-startup.js'

const tables = [
  'users',
  'meeting_rooms',
  'documents',
  'workflow_state',
  'meetings',
  'meeting_participants',
  'gate_permits',
  'auth_sessions',
  'owner_workflows',
  'document_signers',
  'seal_positions',
  'completed_seals',
  'workflow_events',
]
const legacyDatabasePath = path.resolve(
  process.env.SQLITE_SOURCE_PATH || 'data/seal-demo.sqlite',
)
const requiredSettings = ['MYSQL_USER', 'MYSQL_PASSWORD', 'MYSQL_DATABASE']
const missingSettings = requiredSettings.filter((key) => !process.env[key])

if (missingSettings.length) {
  throw new Error(`Missing MySQL configuration: ${missingSettings.join(', ')}. Configure the .env file.`)
}
if (!existsSync(legacyDatabasePath)) {
  throw new Error(`SQLite database not found: ${legacyDatabasePath}`)
}

const source = new Database(legacyDatabasePath, { readonly: true, fileMustExist: true })
const pool = mysql.createPool({
  host: process.env.MYSQL_HOST || '127.0.0.1',
  port: Number(process.env.MYSQL_PORT) || 3306,
  user: process.env.MYSQL_USER,
  password: process.env.MYSQL_PASSWORD,
  database: process.env.MYSQL_DATABASE,
  waitForConnections: true,
  connectionLimit: 2,
  queueLimit: 0,
  charset: 'utf8mb4',
  dateStrings: true,
})
const target = createDatabaseClient(pool)

try {
  await initializeSchema(target)
  const countsBefore = {}
  for (const table of tables) {
    const result = await target.get(`SELECT COUNT(*) AS count FROM \`${table}\``)
    countsBefore[table] = Number(result.count)
  }
  const existingRows = Object.entries(countsBefore).filter(([, count]) => count > 0)
  if (existingRows.length) {
    throw new Error(
      `Migration stopped: target database is not empty (${existingRows.map(([table]) => table).join(', ')}).`,
    )
  }

  const sourceTables = new Set(source.prepare(`
    SELECT name FROM sqlite_master WHERE type = 'table'
  `).all().map(({ name }) => name))
  const defaultedColumns = new Set((await target.all(`
    SELECT TABLE_NAME AS tableName, COLUMN_NAME AS columnName
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = ? AND IS_NULLABLE = 'NO' AND COLUMN_DEFAULT IS NOT NULL
  `, [process.env.MYSQL_DATABASE])).map(
    ({ tableName, columnName }) => `${tableName}.${columnName}`,
  ))
  const expectedCounts = {}
  const defaultedNullCounts = new Map()

  await target.transaction(async (transaction) => {
    for (const table of tables) {
      if (!sourceTables.has(table)) {
        expectedCounts[table] = 0
        continue
      }

      const columns = source.prepare(`PRAGMA table_info(\`${table}\`)`).all().map(({ name }) => name)
      const rows = source.prepare(`SELECT * FROM \`${table}\``).all()
      expectedCounts[table] = rows.length
      if (!rows.length) continue

      for (const row of rows) {
        const rowColumns = columns.filter((column) => {
          const hasDefault = defaultedColumns.has(`${table}.${column}`)
          if (row[column] !== null || !hasDefault) return true

          const key = `${table}.${column}`
          defaultedNullCounts.set(key, (defaultedNullCounts.get(key) || 0) + 1)
          return false
        })
        const placeholders = rowColumns.map(() => '?').join(', ')
        const columnList = rowColumns.map((column) => `\`${column}\``).join(', ')
        const insert = `INSERT INTO \`${table}\` (${columnList}) VALUES (${placeholders})`
        await transaction.run(insert, rowColumns.map((column) => row[column]))
      }
    }
  })

  for (const table of tables) {
    const result = await target.get(`SELECT COUNT(*) AS count FROM \`${table}\``)
    const actual = Number(result.count)
    if (actual !== expectedCounts[table]) {
      throw new Error(
        `Migration verification failed for ${table}: expected ${expectedCounts[table]}, found ${actual}.`,
      )
    }
  }

  console.log(`Migrated SQLite data from ${legacyDatabasePath} to MySQL database "${process.env.MYSQL_DATABASE}".`)
  for (const table of tables) {
    console.log(`${table}: ${expectedCounts[table]} row(s)`)
  }
  if (defaultedNullCounts.size) {
    console.log('Applied MySQL column defaults for legacy NULL values:')
    for (const [column, count] of defaultedNullCounts) {
      console.log(`${column}: ${count} value(s)`)
    }
  }
  console.log('PDF files remain in the existing uploads folder; no files were moved or deleted.')
} finally {
  source.close()
  await target.close()
}
