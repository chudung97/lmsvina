import { Router } from 'express'
import { createPermitsRepository } from './repository.js'
import { authorizationDisabled } from '../../../auth.js'

function isValidDate(value) {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return false
  const date = new Date(`${value}T00:00:00.000Z`)
  return !Number.isNaN(date.valueOf()) && date.toISOString().slice(0, 10) === value
}

function isValidTime(value) {
  return typeof value === 'string' && /^([01]\d|2[0-3]):[0-5]\d$/.test(value)
}

export function createPermitsRouter(db) {
  const router = Router()
  const permits = createPermitsRepository(db)

  router.get('/users', async (request, response) => {
    const users = await permits.listUsers(request.user.id)
    response.json(users)
  })

  router.get('/', async (request, response) => {
    response.json(await permits.listForUser(request.user.id))
  })

  router.post('/', async (request, response) => {
    const {
      direction, date, time, returnTime, purpose, destination, vehicleInfo,
      approverId, guardId,
    } = request.body ?? {}
    const cleanPurpose = typeof purpose === 'string' ? purpose.trim() : ''
    const cleanDestination = typeof destination === 'string' ? destination.trim() : ''
    const cleanVehicleInfo = typeof vehicleInfo === 'string' ? vehicleInfo.trim() : ''

    if (!['out', 'in'].includes(direction)) {
      return response.status(400).json({ error: 'Chọn loại phiếu ra cổng hoặc vào cổng.' })
    }
    if (!isValidDate(date) || !isValidTime(time)
      || (returnTime !== '' && returnTime !== undefined && !isValidTime(returnTime))) {
      return response.status(400).json({ error: 'Ngày hoặc thời gian trên phiếu không hợp lệ.' })
    }
    if (returnTime && returnTime <= time) {
      return response.status(400).json({ error: 'Thời gian quay lại phải sau thời gian ra cổng.' })
    }
    if (!cleanPurpose || cleanPurpose.length > 500
      || !cleanDestination || cleanDestination.length > 160
      || cleanVehicleInfo.length > 160) {
      return response.status(400).json({ error: 'Kiểm tra lý do, nơi đến và thông tin phương tiện.' })
    }
    if (typeof approverId !== 'string' || typeof guardId !== 'string'
      || approverId === guardId || approverId === request.user.id || guardId === request.user.id) {
      return response.status(400).json({ error: 'Chọn người duyệt và bảo vệ khác nhau, không trùng người lập.' })
    }
    const selectedCount = await permits.countActivePeople([approverId, guardId])
    if (selectedCount !== 2) {
      return response.status(400).json({ error: 'Người duyệt hoặc bảo vệ không còn hoạt động.' })
    }

    const permitId = await permits.create({
      requesterId: request.user.id,
      approverId,
      guardId,
      direction,
      date,
      time,
      returnTime: returnTime || null,
      purpose: cleanPurpose,
      destination: cleanDestination,
      vehicleInfo: cleanVehicleInfo,
    })
    response.status(201).json(await permits.findForUser(permitId, request.user.id))
  })

  router.post('/:id/decision', async (request, response) => {
    const permitId = Number(request.params.id)
    const { decision, note } = request.body ?? {}
    const cleanNote = typeof note === 'string' ? note.trim() : ''
    if (!Number.isSafeInteger(permitId) || permitId < 1
      || !['approve', 'reject'].includes(decision)
      || cleanNote.length > 500
      || (decision === 'reject' && !cleanNote)) {
      return response.status(400).json({ error: 'Quyết định hoặc ghi chú không hợp lệ.' })
    }
    const permit = await permits.findDecisionState(permitId)
    if (!permit) return response.status(404).json({ error: 'Không tìm thấy phiếu xin phép.' })
    if (!authorizationDisabled() && permit.approverId !== request.user.id) {
      return response.status(403).json({ error: 'Chỉ người được chỉ định duyệt mới có thể xử lý phiếu.' })
    }
    if (permit.status !== 'pending_approval') {
      return response.status(409).json({ error: 'Phiếu đã được xử lý ở bước người duyệt.' })
    }
    const updated = await permits.updateDecision(
      permitId,
      decision === 'approve' ? 'pending_guard' : 'rejected',
      cleanNote,
    )
    if (!updated.affectedRows) {
      return response.status(409).json({ error: 'Phiếu đã được xử lý ở bước người duyệt.' })
    }
    response.json(await permits.findForUser(permitId, request.user.id))
  })

  router.post('/:id/guard', async (request, response) => {
    const permitId = Number(request.params.id)
    const { decision, note } = request.body ?? {}
    const cleanNote = typeof note === 'string' ? note.trim() : ''
    if (!Number.isSafeInteger(permitId) || permitId < 1
      || !['confirm', 'reject'].includes(decision)
      || cleanNote.length > 500
      || (decision === 'reject' && !cleanNote)) {
      return response.status(400).json({ error: 'Xác nhận hoặc ghi chú không hợp lệ.' })
    }
    const permit = await permits.findGuardState(permitId)
    if (!permit) return response.status(404).json({ error: 'Không tìm thấy phiếu xin phép.' })
    if (!authorizationDisabled() && permit.guardId !== request.user.id) {
      return response.status(403).json({ error: 'Chỉ bảo vệ được chỉ định mới có thể xác nhận phiếu.' })
    }
    if (permit.status !== 'pending_guard') {
      return response.status(409).json({ error: 'Phiếu chưa được người duyệt thông qua hoặc đã hoàn tất.' })
    }
    const updated = await permits.updateGuardDecision(
      permitId,
      decision === 'confirm' ? 'approved' : 'rejected',
      cleanNote,
    )
    if (!updated.affectedRows) {
      return response.status(409).json({ error: 'Phiếu đã được xử lý ở bước bảo vệ.' })
    }
    response.json(await permits.findForUser(permitId, request.user.id))
  })

  return router
}
