// Token đăng nhập (Bearer) do POST /api/auth/login trả về.
// Token được lưu ở localStorage để giữ đăng nhập khi tải lại trang, và được tự động gắn vào
// header `Authorization: Bearer <token>` cho mọi request fetch tới /api cùng nguồn.

const STORAGE_KEY = 'workdesk.authToken'
export const UNAUTHORIZED_EVENT = 'workdesk:unauthorized'

let memoryToken = null

export function getAuthToken() {
  try {
    return window.localStorage.getItem(STORAGE_KEY) || memoryToken
  } catch {
    return memoryToken
  }
}

export function setAuthToken(token) {
  memoryToken = token || null
  try {
    if (token) window.localStorage.setItem(STORAGE_KEY, token)
    else window.localStorage.removeItem(STORAGE_KEY)
  } catch {
    // Trình duyệt chặn localStorage: chỉ giữ token trong bộ nhớ của tab này.
  }
}

export const clearAuthToken = () => setAuthToken(null)

/** Header Authorization cho các request không đi qua fetch (ví dụ xác thực kênh realtime). */
export function authHeaders() {
  const token = getAuthToken()
  return token ? { Authorization: `Bearer ${token}` } : {}
}

function apiPath(input) {
  try {
    const url = new URL(input instanceof Request ? input.url : String(input), window.location.href)
    return url.origin === window.location.origin && url.pathname.startsWith('/api/') ? url.pathname : null
  } catch {
    return null
  }
}

/**
 * Bọc window.fetch: tự gắn token vào request /api; khi máy chủ trả 401 (token hết hạn hoặc bị thu hồi)
 * thì xóa token và phát sự kiện để ứng dụng quay về màn hình đăng nhập.
 */
export function installAuthFetch(target = window) {
  if (target.fetch.workdeskAuth) return
  const originalFetch = target.fetch.bind(target)

  const authFetch = async (input, init = {}) => {
    const path = apiPath(input)
    if (!path) return originalFetch(input, init)

    const token = getAuthToken()
    const headers = new Headers(init.headers ?? (input instanceof Request ? input.headers : undefined))
    if (token && !headers.has('Authorization')) headers.set('Authorization', `Bearer ${token}`)
    const response = await originalFetch(input, { ...init, headers })

    if (response.status === 401 && token && path !== '/api/auth/login' && getAuthToken() === token) {
      clearAuthToken()
      target.dispatchEvent(new CustomEvent(UNAUTHORIZED_EVENT))
    }
    return response
  }
  authFetch.workdeskAuth = true
  target.fetch = authFetch
}
