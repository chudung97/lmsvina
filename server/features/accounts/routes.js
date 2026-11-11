import { Router } from 'express'
import { randomUUID } from 'node:crypto'
import { createAccountsRepository } from './repository.js'
import {
  createSession,
  deleteSession,
  findSessionUser,
  hashPassword,
  publicUser,
  readCookie,
  sessionCookie,
  verifyPassword,
  authorizationDisabled,
} from '../../../auth.js'

const failures = new Map()
const failureWindowMs = 15 * 60 * 1000
const maxFailures = 8
const dummyHash = hashPassword('workdesk-invalid-login-timing-protection')

function sendError(response, status, message) {
  response.status(status).json({ error: message })
}

export function createAuthRouter(db) {
  const router = Router()
  const accounts = createAccountsRepository(db)

  router.use((request, response, next) => {
    if (authorizationDisabled()) return next()
    if (request.method !== 'POST') return next()
    const origin = request.get('origin')
    if (origin && origin !== `${request.protocol}://${request.get('host')}`) {
      return sendError(response, 403, 'Yêu cầu không cùng nguồn bị từ chối.')
    }
    next()
  })

  router.get('/status', async (request, response) => {
    const token = readCookie(request, 'workdesk_session')
    const user = await findSessionUser(db, token)
    if (user && authorizationDisabled()) user.role = 'admin'
    response.json({
      authenticated: Boolean(user),
      user: user ? publicUser(user) : null,
      setupRequired: !await accounts.hasActiveAccount(),
    })
  })

  router.post('/login', async (request, response) => {
    const email = typeof request.body?.email === 'string' ? request.body.email.trim().toLowerCase() : ''
    const password = typeof request.body?.password === 'string' ? request.body.password : ''
    if (!email || !password || email.length > 254 || password.length > 1024) {
      return sendError(response, 400, 'Vui lòng nhập email và mật khẩu hợp lệ.')
    }

    const key = `${request.ip}:${email}`
    const now = Date.now()
    for (const [attemptKey, attempt] of failures) {
      if (attempt.expiresAt <= now) failures.delete(attemptKey)
    }
    if (failures.size > 5000) failures.delete(failures.keys().next().value)
    const current = failures.get(key)
    if (current?.count >= maxFailures && current.expiresAt > now) {
      response.set('Retry-After', String(Math.ceil((current.expiresAt - now) / 1000)))
      return sendError(response, 429, 'Đăng nhập sai quá nhiều lần. Vui lòng thử lại sau 15 phút.')
    }

    const user = await accounts.findLoginUser(email)
    const verified = await verifyPassword(password, user?.password_hash || await dummyHash)
    if (!user || !verified) {
      failures.set(key, {
        count: current?.expiresAt > now ? current.count + 1 : 1,
        expiresAt: current?.expiresAt > now ? current.expiresAt : now + failureWindowMs,
      })
      return sendError(response, 401, 'Email hoặc mật khẩu không chính xác.')
    }

    failures.delete(key)
    const session = await createSession(db, user.id)
    await accounts.deleteExpiredSessions(now)
    if (authorizationDisabled()) user.role = 'admin'
    response.set('Set-Cookie', sessionCookie(session.token, request))
    response.json({ user: publicUser(user) })
  })

  router.post('/logout', async (request, response) => {
    await deleteSession(db, readCookie(request, 'workdesk_session'))
    response.set('Set-Cookie', sessionCookie('', request, 0))
    response.json({ ok: true })
  })

  return router
}

export function createAdminRouter(db) {
  const router = Router()
  const accounts = createAccountsRepository(db)
  const requireAdmin = (request, response, next) => {
    if (!authorizationDisabled() && request.user.role !== 'admin') {
      return sendError(response, 403, 'Chỉ quản trị viên mới được quản lý tài khoản.')
    }
    next()
  }

  router.use(requireAdmin)
  router.get('/users', async (_request, response) => {
    const users = await accounts.listAdminUsers()
    response.json(users.map((user) => ({ ...user, active: Boolean(user.active) })))
  })

  router.post('/users', async (request, response) => {
    const { name, email, password } = request.body ?? {}
    const cleanName = typeof name === 'string' ? name.trim() : ''
    const cleanEmail = typeof email === 'string' ? email.trim().toLowerCase() : ''
    if (!cleanName || cleanName.length > 120) {
      return sendError(response, 400, 'Tên tài khoản phải từ 1 đến 120 ký tự.')
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(cleanEmail) || cleanEmail.length > 254) {
      return sendError(response, 400, 'Địa chỉ email không hợp lệ.')
    }
    if (typeof password !== 'string' || password.length < 6 || password.length > 1024) {
      return sendError(response, 400, 'Mật khẩu phải có từ 6 ký tự trở lên.')
    }
    if (await accounts.emailExists(cleanEmail)) {
      return sendError(response, 409, 'Email này đã được sử dụng.')
    }

    const id = randomUUID()
    const passwordHash = await hashPassword(password)
    const sealText = (cleanName[0] || 'U').toUpperCase()
    await accounts.createMember({
      id,
      name: cleanName,
      sealText,
      signatureName: cleanName,
      color: '#0f766e',
      email: cleanEmail,
      passwordHash,
    })
    response.status(201).json({
      id,
      name: cleanName,
      email: cleanEmail,
      role: 'member',
      active: true,
    })
  })

  router.patch('/users/:id/profile', async (request, response) => {
    const { name, email } = request.body ?? {}
    const cleanName = typeof name === 'string' ? name.trim() : ''
    const cleanEmail = typeof email === 'string' ? email.trim().toLowerCase() : ''
    if (!cleanName || cleanName.length > 120) {
      return sendError(response, 400, 'Tên tài khoản phải từ 1 đến 120 ký tự.')
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(cleanEmail) || cleanEmail.length > 254) {
      return sendError(response, 400, 'Địa chỉ email không hợp lệ.')
    }
    const target = await accounts.findAdminProfileTarget(request.params.id)
    if (!target) return sendError(response, 404, 'Không tìm thấy tài khoản.')
    if (await accounts.emailExists(cleanEmail, target.id)) {
      return sendError(response, 409, 'Email này đã được sử dụng.')
    }
    const updated = await accounts.updateAdminProfile(target.id, target.name, cleanName, cleanEmail)
    response.json({ ...updated, active: Boolean(updated.active) })
  })

  router.patch('/users/:id/password', async (request, response) => {
    const { password } = request.body ?? {}
    if (typeof password !== 'string' || password.length < 6 || password.length > 1024) {
      return sendError(response, 400, 'Mật khẩu phải có từ 6 ký tự trở lên.')
    }
    const target = await accounts.findActiveAdminTarget(request.params.id)
    if (!target) return sendError(response, 404, 'Không tìm thấy tài khoản đang hoạt động.')
    if (target.id === request.user.id) {
      return sendError(response, 409, 'Hãy đổi mật khẩu của chính bạn trong mục Bảo mật.')
    }

    const passwordHash = await hashPassword(password)
    await accounts.updatePasswordAndRevokeSessions(target.id, passwordHash)
    response.json({ ok: true })
  })

  return router
}

export function createAccountRouter(db) {
  const router = Router()
  const accounts = createAccountsRepository(db)

  router.patch('/profile', async (request, response) => {
    const { name, email } = request.body ?? {}
    const cleanName = typeof name === 'string' ? name.trim() : ''
    const cleanEmail = typeof email === 'string' ? email.trim().toLowerCase() : ''
    if (!cleanName || cleanName.length > 120) {
      return sendError(response, 400, 'Tên tài khoản phải từ 1 đến 120 ký tự.')
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(cleanEmail) || cleanEmail.length > 254) {
      return sendError(response, 400, 'Địa chỉ email không hợp lệ.')
    }
    if (await accounts.emailExists(cleanEmail, request.user.id)) {
      return sendError(response, 409, 'Email này đã được sử dụng.')
    }
    const user = await accounts.updateOwnProfile(
      request.user.id,
      request.user.name,
      cleanName,
      cleanEmail,
    )
    response.json({ user: publicUser(user) })
  })

  router.post('/password', async (request, response) => {
    const currentPassword = typeof request.body?.currentPassword === 'string'
      ? request.body.currentPassword
      : ''
    const newPassword = typeof request.body?.newPassword === 'string'
      ? request.body.newPassword
      : ''
    if (newPassword.length < 6 || newPassword.length > 1024) {
      return sendError(response, 400, 'Mật khẩu mới phải có từ 6 ký tự trở lên.')
    }
    if (!await verifyPassword(currentPassword, request.user.password_hash)) {
      return sendError(response, 403, 'Mật khẩu hiện tại không chính xác.')
    }

    const passwordHash = await hashPassword(newPassword)
    await accounts.updatePasswordAndRevokeSessions(request.user.id, passwordHash)
    const session = await createSession(db, request.user.id)
    response.set('Set-Cookie', sessionCookie(session.token, request))
    response.json({ ok: true })
  })

  return router
}
