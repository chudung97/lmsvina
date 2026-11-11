import { createDatabaseClient } from '../../database.js'

export function createAccountsRepository(db) {
  const database = createDatabaseClient(db)
  return {
    async hasActiveAccount() {
      return Boolean(await database.get(`
        SELECT 1 FROM users
        WHERE active = 1 AND email IS NOT NULL
        LIMIT 1
      `))
    },

    async findLoginUser(email) {
      return database.get(`
        SELECT * FROM users
        WHERE email = ? AND active = 1 AND password_hash IS NOT NULL
      `, [email])
    },

    async createBootstrapAdmin(user) {
      await database.run(`
        INSERT INTO users (id, name, seal_text, signature_name, color, email, password_hash, role, active)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'admin', 1)
      `, [
        user.id,
        user.name,
        user.sealText,
        user.signatureName,
        user.color,
        user.email,
        user.passwordHash,
      ])
    },

    async createSession(session) {
      await database.run(`
        INSERT INTO auth_sessions (token_hash, user_id, expires_at)
        VALUES (?, ?, ?)
      `, [session.tokenHash, session.userId, session.expiresAt])
    },

    async findSessionUser(tokenHash, now) {
      return database.get(`
        SELECT users.* FROM auth_sessions
        JOIN users ON users.id = auth_sessions.user_id
        WHERE auth_sessions.token_hash = ?
          AND auth_sessions.expires_at > ?
          AND users.active = 1
      `, [tokenHash, now])
    },

    async deleteSession(tokenHash) {
      await database.run('DELETE FROM auth_sessions WHERE token_hash = ?', [tokenHash])
    },

    async deleteExpiredSessions(now) {
      await database.run('DELETE FROM auth_sessions WHERE expires_at <= ?', [now])
    },

    async listAdminUsers() {
      return database.all(`
        SELECT id, name, email, role, active, created_at AS createdAt
        FROM users
        WHERE email IS NOT NULL
        ORDER BY active DESC, name
      `)
    },

    async emailExists(email, excludeUserId = null) {
      const existing = excludeUserId === null
        ? await database.get('SELECT 1 FROM users WHERE email = ?', [email])
        : await database.get('SELECT 1 FROM users WHERE email = ? AND id != ?', [email, excludeUserId])
      return Boolean(existing)
    },

    async createMember(user) {
      await database.run(`
        INSERT INTO users (id, name, seal_text, signature_name, color, email, password_hash, role, active)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'member', 1)
      `, [
        user.id,
        user.name,
        user.sealText,
        user.signatureName,
        user.color,
        user.email,
        user.passwordHash,
      ])
    },

    async findAdminProfileTarget(userId) {
      return database.get(`
        SELECT id, name FROM users WHERE id = ? AND email IS NOT NULL
      `, [userId])
    },

    async updateAdminProfile(userId, previousName, name, email) {
      await database.run(`
        UPDATE users
        SET name = ?, email = ?,
          signature_name = CASE WHEN signature_name = ? THEN ? ELSE signature_name END
        WHERE id = ?
      `, [name, email, previousName, name, userId])
      return database.get(`
        SELECT id, name, email, role, active, created_at AS createdAt
        FROM users WHERE id = ?
      `, [userId])
    },

    async findActiveAdminTarget(userId) {
      return database.get(`
        SELECT id FROM users
        WHERE id = ? AND active = 1 AND email IS NOT NULL
      `, [userId])
    },

    async updatePasswordAndRevokeSessions(userId, passwordHash) {
      await database.transaction(async (transaction) => {
        await transaction.run('UPDATE users SET password_hash = ? WHERE id = ?', [passwordHash, userId])
        await transaction.run('DELETE FROM auth_sessions WHERE user_id = ?', [userId])
      })
    },

    async updateOwnProfile(userId, previousName, name, email) {
      await database.run(`
        UPDATE users
        SET name = ?, email = ?,
          signature_name = CASE WHEN signature_name = ? THEN ? ELSE signature_name END
        WHERE id = ?
      `, [name, email, previousName, name, userId])
      return database.get('SELECT * FROM users WHERE id = ?', [userId])
    },
  }
}
