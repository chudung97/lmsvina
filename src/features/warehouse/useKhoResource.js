import { useCallback, useEffect, useState } from 'react'
import { useRealtimeRefresh } from '../../realtime'
import { requestApi } from './warehouseApi'

/**
 * Tải một API kho, tự tải lại khi có sự kiện realtime "kho" hoặc khi gọi reload().
 * stamp đổi mỗi lần dữ liệu mới về – dùng làm key để form khởi tạo lại từ dữ liệu.
 */
export function useKhoResource(url) {
  const realtimeVersion = useRealtimeRefresh('kho')
  const [version, setVersion] = useState(0)
  const requestKey = `${url}|${version}|${realtimeVersion}`
  const [state, setState] = useState({ key: null, data: null, error: '' })

  useEffect(() => {
    if (!url) return undefined
    const controller = new AbortController()
    requestApi(url, { signal: controller.signal })
      .then((data) => setState({ key: requestKey, data, error: '' }))
      .catch((error) => {
        if (error.name !== 'AbortError') setState((current) => ({ ...current, key: requestKey, error: error.message }))
      })
    return () => controller.abort()
  }, [url, requestKey])

  const reload = useCallback(() => setVersion((current) => current + 1), [])

  return { data: state.data, error: state.error, loading: state.key !== requestKey, stamp: state.key, reload }
}
