import { createDatabaseClient } from '../../database.js'

export function createPermitsRepository(db) {
  const database = createDatabaseClient(db)
  const permitFields = `
    gate_permits.id, gate_permits.requester_id AS requesterId,
    gate_permits.approver_id AS approverId, gate_permits.guard_id AS guardId,
    gate_permits.direction, gate_permits.permit_date AS date,
    gate_permits.permit_time AS time, gate_permits.return_time AS returnTime,
    gate_permits.purpose, gate_permits.destination, gate_permits.vehicle_info AS vehicleInfo,
    gate_permits.status, gate_permits.approver_note AS approverNote,
    gate_permits.guard_note AS guardNote, gate_permits.created_at AS createdAt,
    gate_permits.updated_at AS updatedAt, gate_permits.approved_at AS approvedAt,
    gate_permits.guard_confirmed_at AS guardConfirmedAt,
    requester.name AS requesterName, approver.name AS approverName, guard.name AS guardName
  `
  const permitSelect = `
    SELECT ${permitFields}
    FROM gate_permits
    JOIN users AS requester ON requester.id = gate_permits.requester_id
    JOIN users AS approver ON approver.id = gate_permits.approver_id
    JOIN users AS guard ON guard.id = gate_permits.guard_id
  `

  return {
    async listUsers(excludedUserId) {
      return database.all(`
        SELECT id, name
        FROM users
        WHERE active = 1 AND email IS NOT NULL AND id != ?
        ORDER BY name
      `, [excludedUserId])
    },

    async listForUser(userId) {
      return database.all(`${permitSelect}
        WHERE gate_permits.requester_id = ?
          OR gate_permits.approver_id = ?
          OR gate_permits.guard_id = ?
        ORDER BY gate_permits.created_at DESC, gate_permits.id DESC
      `, [userId, userId, userId])
    },

    async findForUser(permitId, userId) {
      return database.get(`${permitSelect}
        WHERE gate_permits.id = ?
          AND (
            gate_permits.requester_id = ?
            OR gate_permits.approver_id = ?
            OR gate_permits.guard_id = ?
          )
      `, [permitId, userId, userId, userId])
    },

    async countActivePeople(userIds) {
      const result = await database.get(`
        SELECT COUNT(*) AS count FROM users
        WHERE id IN (?, ?) AND active = 1 AND email IS NOT NULL
      `, userIds)
      return Number(result.count)
    },

    async create(permit) {
      const result = await database.run(`
        INSERT INTO gate_permits (
          requester_id, approver_id, guard_id, direction, permit_date,
          permit_time, return_time, purpose, destination, vehicle_info
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
      `, [
        permit.requesterId,
        permit.approverId,
        permit.guardId,
        permit.direction,
        permit.date,
        permit.time,
        permit.returnTime,
        permit.purpose,
        permit.destination,
        permit.vehicleInfo,
      ])
      return result.insertId
    },

    async findDecisionState(permitId) {
      return database.get(
        'SELECT approver_id AS approverId, status FROM gate_permits WHERE id = ?',
        [permitId],
      )
    },

    async updateDecision(permitId, status, note) {
      return database.run(`
        UPDATE gate_permits
        SET status = ?, approver_note = ?, approved_at = CURRENT_TIMESTAMP,
          updated_at = CURRENT_TIMESTAMP
        WHERE id = ? AND status = 'pending_approval'
      `, [status, note, permitId])
    },

    async findGuardState(permitId) {
      return database.get(
        'SELECT guard_id AS guardId, status FROM gate_permits WHERE id = ?',
        [permitId],
      )
    },

    async updateGuardDecision(permitId, status, note) {
      return database.run(`
        UPDATE gate_permits
        SET status = ?, guard_note = ?, guard_confirmed_at = CURRENT_TIMESTAMP,
          updated_at = CURRENT_TIMESTAMP
        WHERE id = ? AND status = 'pending_guard'
      `, [status, note, permitId])
    },
  }
}
