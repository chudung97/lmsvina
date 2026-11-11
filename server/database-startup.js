import { readFile } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { bootstrapAdmin } from '../auth.js'

const root = path.dirname(fileURLToPath(import.meta.url))
const defaultSeal = { page: 1, x: 120, y: 180, width: 126, height: 84, rotation: 0 }

export async function initializeSchema(database) {
  const schema = await readFile(path.join(root, '..', 'database.mysql.sql'), 'utf8')
  for (const statement of schema.split(';').map((sql) => sql.trim()).filter(Boolean)) {
    await database.run(statement)
  }
}

export async function initializeDatabase(database) {
  await initializeSchema(database)
  const legacyWorkflow = await database.get('SELECT * FROM workflow_state WHERE id = 1')
  const bootstrapAdminId = await bootstrapAdmin(database)
  const firstAdmin = await database.get(`
    SELECT id FROM users
    WHERE active = 1 AND role = 'admin'
    ORDER BY created_at LIMIT 1
  `)

  if (firstAdmin) {
    await database.transaction(async (transaction) => {
      await transaction.run('UPDATE documents SET owner_id = ? WHERE owner_id IS NULL', [firstAdmin.id])
      await transaction.run('UPDATE workflow_events SET owner_id = ? WHERE owner_id IS NULL', [firstAdmin.id])

      if (legacyWorkflow?.current_document_id) {
        await transaction.run(`
          UPDATE workflow_events SET document_id = ?
          WHERE owner_id = ? AND document_id IS NULL
        `, [legacyWorkflow.current_document_id, firstAdmin.id])
      }

      await transaction.run(`
        INSERT IGNORE INTO owner_workflows (owner_id, current_document_id, finalized)
        VALUES (?, ?, ?)
      `, [
        firstAdmin.id,
        legacyWorkflow?.current_document_id ?? null,
        legacyWorkflow?.finalized ?? 0,
      ])

      if (legacyWorkflow?.current_document_id) {
        const document = await transaction.get(`
          SELECT completed_at FROM documents WHERE id = ? AND owner_id = ?
        `, [legacyWorkflow.current_document_id, firstAdmin.id])
        if (document && !document.completed_at) {
          await transaction.run(`
            INSERT IGNORE INTO document_signers (document_id, user_id, sequence)
            VALUES (?, ?, 0)
          `, [legacyWorkflow.current_document_id, firstAdmin.id])

          const priorPosition = await transaction.get(`
            SELECT page, x, y, width, height, rotation
            FROM seal_positions WHERE document_id = ? AND user_id = ?
          `, [legacyWorkflow.current_document_id, legacyWorkflow.current_user_id])
          const position = priorPosition || defaultSeal
          await transaction.run(`
            INSERT IGNORE INTO seal_positions
              (user_id, document_id, page, x, y, width, height, rotation)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
          `, [
            firstAdmin.id,
            legacyWorkflow.current_document_id,
            position.page,
            position.x,
            position.y,
            position.width,
            position.height,
            position.rotation,
          ])
        }
      }

      await transaction.run(`
        INSERT IGNORE INTO document_signers (document_id, user_id, sequence)
        SELECT documents.id, ?, 0
        FROM documents
        WHERE documents.owner_id = ? AND documents.completed_at IS NULL
          AND NOT EXISTS (
            SELECT 1 FROM document_signers
            WHERE document_signers.document_id = documents.id
          )
      `, [firstAdmin.id, firstAdmin.id])
      await transaction.run(`
        INSERT IGNORE INTO seal_positions
          (user_id, document_id, page, x, y, width, height, rotation)
        SELECT ?, documents.id, ?, ?, ?, ?, ?, ?
        FROM documents
        WHERE documents.owner_id = ? AND documents.completed_at IS NULL
          AND NOT EXISTS (
            SELECT 1 FROM seal_positions
            WHERE seal_positions.document_id = documents.id
              AND seal_positions.user_id = ?
          )
      `, [
        firstAdmin.id,
        ...Object.values(defaultSeal),
        firstAdmin.id,
        firstAdmin.id,
      ])

      await transaction.run(`
        INSERT IGNORE INTO completed_seals (
          document_id, user_id, user_name, seal_text, signature_name, color,
          page, x, y, width, height, rotation
        )
        SELECT documents.id, users.id, users.name, users.seal_text, users.signature_name,
          users.color, positions.page, positions.x, positions.y, positions.width,
          positions.height, positions.rotation
        FROM documents
        JOIN seal_positions AS positions ON positions.document_id = documents.id
        JOIN users ON users.id = positions.user_id
        WHERE documents.completed_at IS NOT NULL
      `)

      await transaction.run(`
        UPDATE seal_positions
        SET signed_at = COALESCE(
          (SELECT documents.completed_at FROM documents
           WHERE documents.id = seal_positions.document_id),
          CURRENT_TIMESTAMP
        )
        WHERE document_id IN (SELECT id FROM documents WHERE completed_at IS NOT NULL)
          AND signed_at IS NULL
      `)
    })
  }

  if (!bootstrapAdminId && !firstAdmin) {
    console.warn('No admin account exists. Set BOOTSTRAP_ADMIN_EMAIL and BOOTSTRAP_ADMIN_PASSWORD, then restart.')
  }
}
