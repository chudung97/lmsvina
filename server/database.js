const databaseClient = Symbol('databaseClient')

export function createDatabaseClient(connection) {
  if (connection[databaseClient]) return connection

  const sqlite = typeof connection.prepare === 'function'
    && typeof connection.exec === 'function'
  const client = {
    [databaseClient]: true,
    isSqlite: sqlite,

    async all(sql, values = []) {
      if (sqlite) return connection.prepare(sql).all(...values)
      const [rows] = await connection.execute(sql, values)
      return rows
    },

    async get(sql, values = []) {
      if (sqlite) return connection.prepare(sql).get(...values)
      const [rows] = await connection.execute(sql, values)
      return rows[0]
    },

    async run(sql, values = []) {
      if (sqlite) {
        const result = connection.prepare(sql).run(...values)
        return {
          affectedRows: result.changes,
          insertId: Number(result.lastInsertRowid),
        }
      }
      const [result] = await connection.execute(sql, values)
      return result
    },

    async transaction(work) {
      if (sqlite) {
        connection.exec('BEGIN')
        try {
          const result = await work(client)
          connection.exec('COMMIT')
          return result
        } catch (error) {
          connection.exec('ROLLBACK')
          throw error
        }
      }

      const pool = typeof connection.getConnection === 'function'
      const transactionConnection = pool ? await connection.getConnection() : connection
      await transactionConnection.beginTransaction()
      try {
        const result = await work(createDatabaseClient(transactionConnection))
        await transactionConnection.commit()
        return result
      } catch (error) {
        await transactionConnection.rollback()
        throw error
      } finally {
        if (pool) transactionConnection.release()
      }
    },

    async close() {
      if (sqlite) {
        connection.close()
      } else if (typeof connection.end === 'function') {
        await connection.end()
      }
    },
  }

  return client
}
