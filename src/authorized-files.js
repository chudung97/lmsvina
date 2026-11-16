// Tải file/ảnh từ API khi đăng nhập bằng token Bearer.
// Thẻ <img src> và <a href> không gửi được header Authorization, nên các URL /api/... được tải
// bằng fetch (đã tự gắn token, xem auth-token.js) rồi đổi thành blob: URL để hiển thị hoặc tải về.
import { useEffect, useState } from 'react'

const needsAuth = (url) => typeof url === 'string' && url.startsWith('/api/')
const objectUrls = new Map()

async function fetchBlob(url) {
  const response = await fetch(url)
  if (!response.ok) {
    let message = `Không tải được file (HTTP ${response.status}).`
    try {
      message = (await response.json()).error || message
    } catch {
      // Không phải JSON: giữ thông báo mặc định.
    }
    throw new Error(message)
  }
  return response
}

/** blob: URL của ảnh /api (có cache theo URL); URL khác (blob:, data:, http…) trả về nguyên vẹn. */
export function loadAuthorizedObjectUrl(url) {
  if (!needsAuth(url)) return Promise.resolve(url ?? null)
  if (!objectUrls.has(url)) {
    const pending = fetchBlob(url)
      .then((response) => response.blob())
      .then((blob) => URL.createObjectURL(blob))
    pending.catch(() => objectUrls.delete(url))
    objectUrls.set(url, pending)
  }
  return objectUrls.get(url)
}

export function useAuthorizedUrl(url) {
  const [state, setState] = useState({ url: null, resolved: needsAuth(url) ? null : url ?? null })

  useEffect(() => {
    if (!needsAuth(url)) return undefined
    let active = true
    loadAuthorizedObjectUrl(url)
      .then((resolved) => {
        if (active) setState({ url, resolved })
      })
      .catch(() => {
        if (active) setState({ url, resolved: null })
      })
    return () => {
      active = false
    }
  }, [url])

  if (!needsAuth(url)) return url ?? null
  return state.url === url ? state.resolved : null
}

function filenameFrom(response, fallback) {
  const header = response.headers.get('Content-Disposition') || ''
  const encoded = header.match(/filename\*=UTF-8''([^;]+)/i)
  if (encoded) {
    try {
      return decodeURIComponent(encoded[1])
    } catch {
      // Tên mã hóa sai: dùng tên thường bên dưới.
    }
  }
  const plain = header.match(/filename="?([^";]+)"?/i)
  return plain ? plain[1] : fallback
}

/** Tải file /api về máy người dùng (kèm token). */
export async function downloadAuthorizedFile(url, fallbackName = 'download') {
  const response = await fetchBlob(url)
  const name = filenameFrom(response, fallbackName)
  const objectUrl = URL.createObjectURL(await response.blob())
  const link = document.createElement('a')
  link.href = objectUrl
  link.download = name
  document.body.appendChild(link)
  link.click()
  link.remove()
  window.setTimeout(() => URL.revokeObjectURL(objectUrl), 60_000)
}
