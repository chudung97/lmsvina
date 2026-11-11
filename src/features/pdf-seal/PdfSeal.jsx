import { useCallback, useEffect, useState } from 'react'
import { useRealtimeRefresh } from '../../realtime'
import { LocalizedTree } from '../../i18n'
import CompletedDocuments from './CompletedDocuments'
import DocumentEditor from './DocumentEditor'
import MyDocuments from './MyDocuments'
import './PdfSeal.css'

async function requestApi(url) {
  const response = await fetch(url)
  const result = await response.json()
  if (!response.ok) throw new Error(result.error || 'Không thể kết nối máy chủ.')
  return result
}

/**
 * Đóng dấu PDF kiểu chuyển tay:
 * - "Tài liệu của tôi": tài liệu đang ở chỗ tôi (đóng/sửa/xóa dấu, chuyển tiếp) và đã chuyển đi.
 * - "Tài liệu đã đóng dấu": danh sách riêng các tài liệu đã hoàn tất; bấm vào xem chi tiết.
 */
function PdfSeal({ sessionUser, onOpenSettings }) {
  const refreshCounter = useRealtimeRefresh('pdf')
  const [view, setView] = useState('mine')
  const [openDocumentId, setOpenDocumentId] = useState(null)
  const [reloadKey, setReloadKey] = useState(0)
  const [mine, setMine] = useState({ data: null, loading: true, error: '' })
  const [completed, setCompleted] = useState({ documents: [], loading: true, error: '' })

  useEffect(() => {
    let cancelled = false
    Promise.all([requestApi('/api/documents/mine'), requestApi('/api/documents/completed')])
      .then(([mineData, completedData]) => {
        if (cancelled) return
        setMine({ data: mineData, loading: false, error: '' })
        setCompleted({ documents: completedData, loading: false, error: '' })
      })
      .catch((error) => {
        if (cancelled) return
        setMine((current) => ({ ...current, loading: false, error: error.message }))
        setCompleted((current) => ({ ...current, loading: false, error: error.message }))
      })
    return () => { cancelled = true }
  }, [refreshCounter, reloadKey])

  const reload = useCallback(() => setReloadKey((current) => current + 1), [])
  const switchView = (next) => {
    setView(next)
    setOpenDocumentId(null)
  }
  const holdingCount = mine.data?.holding.length ?? 0

  return (
    <LocalizedTree>
      <div className="page-shell">
        <header className="topbar">
          <div>
            <p className="eyebrow">Workflow đóng dấu</p>
            <h1>Đóng dấu PDF</h1>
          </div>
          <nav className="seal-tabs" aria-label="Màn hình đóng dấu">
            <button type="button" className={view === 'mine' ? 'active' : ''} aria-current={view === 'mine' ? 'page' : undefined} onClick={() => switchView('mine')}>
              Tài liệu của tôi{holdingCount ? ` (${holdingCount} chờ xử lý)` : ''}
            </button>
            <button type="button" className={view === 'completed' ? 'active' : ''} aria-current={view === 'completed' ? 'page' : undefined} onClick={() => switchView('completed')}>
              Tài liệu đã đóng dấu ({completed.documents.length})
            </button>
          </nav>
        </header>

        {openDocumentId ? (
          <DocumentEditor
            key={openDocumentId}
            documentId={openDocumentId}
            sessionUser={sessionUser}
            onOpenSettings={onOpenSettings}
            onChanged={reload}
            backLabel={view === 'completed' ? '← Danh sách đã đóng dấu' : '← Tài liệu của tôi'}
            onBack={() => {
              setOpenDocumentId(null)
              reload()
            }}
          />
        ) : view === 'mine' ? (
          <MyDocuments
            data={mine.data}
            loading={mine.loading}
            error={mine.error}
            onOpen={setOpenDocumentId}
            onUploaded={(id) => {
              reload()
              setOpenDocumentId(id)
            }}
          />
        ) : (
          <CompletedDocuments documents={completed.documents} loading={completed.loading} error={completed.error} onOpen={setOpenDocumentId} />
        )}
      </div>
    </LocalizedTree>
  )
}

export default PdfSeal
