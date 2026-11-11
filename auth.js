import { createHash, randomBytes, randomUUID, scrypt as scryptCallback, timingSafeEqual } from 'node:crypto'
import { promisify } from 'node:util'
import { createAccountsRepository } from './server/features/accounts/repository.js'

export function authorizationDisabled() {
  return process.env.WORKDESK_DISABLE_AUTHORIZATION === 'true'
}

const scrypt = promisify(scryptCallback)
const SESSION_DURATION_MS = 12 * 60 * 60 * 1000

export function publicUser(user) {
  return {
    id: user.id,
    name: user.name,
    email: user.email,
    role: user.role,
    sealText: user.seal_text,
    signatureName: user.signature_name,
    color: user.color,
  }
}

export async function hashPassword(password) {
  const salt = randomBytes(16)
  const hash = await scrypt(password, salt, 64)
  return `${salt.toString('hex')}:${hash.toString('hex')}`
}

export async function verifyPassword(password, encodedHash) {
  const [saltHex, hashHex] = typeof encodedHash === 'string' ? encodedHash.split(':') : []
  if (saltHex?.length !== 32 || hashHex?.length !== 128
    || !/^[\da-f]+$/i.test(saltHex) || !/^[\da-f]+$/i.test(hashHex)) {
    return false
  }
  const expected = Buffer.from(hashHex, 'hex')
  const actual = await scrypt(password, Buffer.from(saltHex, 'hex'), expected.length)
  return expected.length === actual.length && timingSafeEqual(expected, actual)
}

function hashToken(token) {
  return createHash('sha256').update(token).digest('hex')
}

export function readCookie(request, cookieName) {
  const cookies = request.headers.cookie?.split(';') ?? []
  const entry = cookies.map((cookie) => cookie.trim()).find((cookie) => cookie.startsWith(`${cookieName}=`))
  return entry ? entry.slice(cookieName.length + 1) : null
}

export function sessionCookie(token, request, maxAgeSeconds = SESSION_DURATION_MS / 1000) {
  const secure = request.secure ? '; Secure' : ''
  return `workdesk_session=${token}; HttpOnly; SameSite=Strict; Path=/; Max-Age=${maxAgeSeconds}${secure}`
}

export async function createSession(db, userId) {
  const token = randomBytes(32).toString('base64url')
  const expiresAt = Date.now() + SESSION_DURATION_MS
  await createAccountsRepository(db).createSession({
    tokenHash: hashToken(token),
    userId,
    expiresAt,
  })
  return { token, expiresAt }
}

export async function findSessionUser(db, token) {
  if (!token || token.length > 128) return null
  return createAccountsRepository(db).findSessionUser(hashToken(token), Date.now())
}

export async function deleteSession(db, token) {
  if (token && token.length <= 128) {
    await createAccountsRepository(db).deleteSession(hashToken(token))
  }
}

export async function bootstrapAdmin(db) {
  const email = process.env.BOOTSTRAP_ADMIN_EMAIL
  const password = process.env.BOOTSTRAP_ADMIN_PASSWORD
  const configuredName = process.env.BOOTSTRAP_ADMIN_NAME
  delete process.env.BOOTSTRAP_ADMIN_EMAIL
  delete process.env.BOOTSTRAP_ADMIN_PASSWORD
  delete process.env.BOOTSTRAP_ADMIN_NAME

  const accounts = createAccountsRepository(db)
  if (await accounts.hasActiveAccount()) return false

  if (!email && !password && !configuredName) return false
  if (!email || !password) {
    throw new Error('BOOTSTRAP_ADMIN_EMAIL và BOOTSTRAP_ADMIN_PASSWORD phải được cấu hình cùng nhau.')
  }
  const normalizedEmail = email.trim().toLowerCase()
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(normalizedEmail)) {
    throw new Error('BOOTSTRAP_ADMIN_EMAIL không hợp lệ.')
  }
  if (password.length < 6) {
    throw new Error('BOOTSTRAP_ADMIN_PASSWORD phải có ít nhất 6 ký tự.')
  }
  const name = (configuredName || 'Quản trị viên').trim()
  if (!name || name.length > 120) throw new Error('BOOTSTRAP_ADMIN_NAME phải từ 1 đến 120 ký tự.')

  const id = randomUUID()
  const passwordHash = await hashPassword(password)
  const seed = (name[0] || 'A').toUpperCase()
  await accounts.createBootstrapAdmin({
    id,
    name,
    sealText: seed,
    signatureName: name,
    color: '#1d4ed8',
    email: normalizedEmail,
    passwordHash,
  })
  return id
}

export function createAuthMiddleware(db) {
  return async (request, response, next) => {
    const token = readCookie(request, 'workdesk_session')
    const user = await findSessionUser(db, token)
    if (!user) {
      response.status(401).json({ error: 'Vui lòng đăng nhập để tiếp tục.' })
      return
    }

    if (!authorizationDisabled()) {
      if (!['GET', 'HEAD', 'OPTIONS'].includes(request.method)) {
        const origin = request.get('origin')
        if (origin && origin !== `${request.protocol}://${request.get('host')}`) {
          response.status(403).json({ error: 'Yêu cầu không cùng nguồn bị từ chối.' })
          return
        }
      }
    }

    request.user = user
    if (authorizationDisabled()) {
      request.user.role = 'admin'
    }
    next()
  }
}
