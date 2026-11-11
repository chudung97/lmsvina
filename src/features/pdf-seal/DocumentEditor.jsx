import { useCallback, useEffect, useRef, useState } from 'react'
import { useRealtimeRefresh } from '../../realtime'
import { SearchableSelect } from '../../components/SearchableControls'
import { useLocale } from '../../locale-context'
import PdfPages from './PdfPages'
import SealMark from './SealMark'

const fallbackPage = { width: 773, height: 1094 }
const clamp = (value, min, max) => Math.min(Math.max(value, min), max)

const requestApi = async (url, options = {}) => {
  const response = await fetch(url, options)
  const result = await response.json()
  if (!response.ok) throw new Error(result.error || 'Không thể kết nối máy chủ.')
  return result
}

const sendJson = (url, method, body) => requestApi(url, {
  method,
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify(body ?? {}),
})

function useDateFormatter() {
  const { locale } = useLocale()
  return (value) => {
    if (!value) return ''
    const date = new Date(`${String(value).replace(' ', 'T')}Z`)
    return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat(locale, { dateStyle: 'short', timeStyle: 'short' }).format(date)
  }
}

function routeText(route) {
  if (route.action === 'upload') return `${route.toName || 'Người tạo'} tải tài liệu lên`
  if (route.action === 'forward') return `${route.fromName} chuyển cho ${route.toName}`
  if (route.action === 'complete') return `${route.fromName} hoàn tất và khóa tài liệu`
  if (route.action === 'stamp') return `${route.fromName} đóng dấu (quy trình cũ)`
  return route.action
}

/**
 * Mở một tài liệu. Người đang giữ được thêm/kéo/đổi cỡ/xóa con dấu của mình (nhiều dấu,
 * nhiều trang), chọn người chuyển tiếp ở thanh trên cùng hoặc hoàn tất. Người khác chỉ xem.
 */
function DocumentEditor({ documentId, sessionUser, onBack, onOpenSettings, onChanged, backLabel = '← Tài liệu của tôi' }) {
  const refreshCounter = useRealtimeRefresh('pdf')
  const formatDate = useDateFormatter()
  const pagesRef = useRef([])
  const dirtyRef = useRef(new Set())
  const draggingRef = useRef(null)
  const [detail, setDetail] = useState(null)
  const [seals, setSeals] = useState([])
  const [file, setFile] = useState(null)
  const [pageSizes, setPageSizes] = useState([])
  const [visiblePage, setVisiblePage] = useState(1)
  const [dragging, setDragging] = useState(null)
  const [dirtyVersion, setDirtyVersion] = useState(0)
  const [selectedSealId, setSelectedSealId] = useState(null)
  const [recipients, setRecipients] = useState([])
  const [forwardTo, setForwardTo] = useState('')
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  const totalPages = pageSizes.length || 1
  const canEdit = Boolean(detail?.canEdit)
  const myId = String(sessionUser.id)

  /** Nhận dữ liệu mới từ máy chủ; giữ nguyên vị trí dấu đang kéo/chưa lưu. */
  const applyDetail = useCallback((next, force = false) => {
    setDetail(next)
    if (force || (!dirtyRef.current.size && !draggingRef.current)) setSeals(next.seals)
  }, [])

  useEffect(() => {
    let cancelled = false
    requestApi(`/api/documents/${documentId}`)
      .then((next) => {
        if (!cancelled) applyDetail(next)
      })
      .catch((loadError) => {
        if (!cancelled) setError(loadError.message)
      })
    return () => { cancelled = true }
  }, [applyDetail, documentId, refreshCounter])

  useEffect(() => {
    let cancelled = false
    fetch(`/api/documents/${documentId}/file`)
      .then(async (response) => {
        if (!response.ok) throw new Error('Không thể tải PDF.')
        const blob = await response.blob()
        if (!cancelled) setFile(new File([blob], `document-${documentId}.pdf`, { type: 'application/pdf' }))
      })
      .catch((loadError) => {
        if (!cancelled) setError(loadError.message)
      })
    return () => { cancelled = true }
  }, [documentId])

  useEffect(() => {
    if (!detail?.canForward || recipients.length) return
    requestApi('/api/documents/recipients').then(setRecipients).catch(() => {})
  }, [detail?.canForward, recipients.length])

  const pageSize = useCallback((pageNumber) => pageSizes[pageNumber - 1] || fallbackPage, [pageSizes])

  const clampToPage = useCallback((seal) => {
    const size = pageSize(seal.page)
    const width = clamp(seal.width, 30, size.width * 0.9)
    const height = seal.aspect ? width / seal.aspect : clamp(seal.height, 20, size.height * 0.9)
    return {
      ...seal,
      width,
      height,
      x: clamp(seal.x, 0, Math.max(0, size.width - width)),
      y: clamp(seal.y, 0, Math.max(0, size.height - height)),
    }
  }, [pageSize])

  const updateLocal = useCallback((id, updater) => {
    setSeals((current) => current.map((seal) => (seal.id === id ? clampToPage(updater(seal)) : seal)))
    dirtyRef.current.add(id)
    setDirtyVersion((version) => version + 1)
  }, [clampToPage])

  const saveDirty = useCallback(async () => {
    const ids = [...dirtyRef.current]
    dirtyRef.current.clear()
    let latest = null
    for (const id of ids) {
      const seal = seals.find((candidate) => candidate.id === id)
      if (!seal) continue
      latest = await sendJson(`/api/documents/${documentId}/seals/${id}`, 'PATCH', {
        page: seal.page, x: seal.x, y: seal.y, width: seal.width, height: seal.height,
      })
    }
    if (latest) setDetail(latest)
  }, [documentId, seals])

  // Tự lưu vị trí/kích thước sau khi ngừng kéo.
  useEffect(() => {
    if (!dirtyVersion || dragging) return undefined
    const timeout = window.setTimeout(() => {
      saveDirty().catch((saveError) => setError(`Không lưu được vị trí con dấu: ${saveError.message}`))
    }, 450)
    return () => window.clearTimeout(timeout)
  }, [dirtyVersion, dragging, saveDirty])

  const pageScale = (pageNumber) => {
    const element = pagesRef.current[pageNumber - 1]
    return element ? element.getBoundingClientRect().width / pageSize(pageNumber).width : 1
  }

  const startDrag = (seal, mode) => (event) => {
    event.preventDefault()
    setSelectedSealId(seal.id)
    const rect = pagesRef.current[seal.page - 1]?.getBoundingClientRect()
    const scale = pageScale(seal.page)
    const next = mode === 'move'
      ? { id: seal.id, mode, offsetX: (event.clientX - (rect?.left || 0)) / scale - seal.x, offsetY: (event.clientY - (rect?.top || 0)) / scale - seal.y }
      : { id: seal.id, mode, startX: event.clientX, startWidth: seal.width, aspect: seal.width / seal.height }
    draggingRef.current = next
    setDragging(next)
  }

  useEffect(() => {
    if (!dragging) return undefined
    const move = (event) => {
      if (dragging.mode === 'resize') {
        updateLocal(dragging.id, (seal) => {
          const width = dragging.startWidth + (event.clientX - dragging.startX) / pageScale(seal.page)
          return { ...seal, width, height: width / dragging.aspect }
        })
        return
      }
      updateLocal(dragging.id, (seal) => {
        let pageNumber = seal.page
        pagesRef.current.forEach((element, index) => {
          if (!element) return
          const rect = element.getBoundingClientRect()
          if (event.clientY >= rect.top && event.clientY <= rect.bottom) pageNumber = index + 1
        })
        const rect = pagesRef.current[pageNumber - 1]?.getBoundingClientRect()
        const scale = pageScale(pageNumber)
        return {
          ...seal,
          page: pageNumber,
          x: (event.clientX - (rect?.left || 0)) / scale - dragging.offsetX,
          y: (event.clientY - (rect?.top || 0)) / scale - dragging.offsetY,
        }
      })
    }
    const stop = () => {
      draggingRef.current = null
      setDragging(null)
    }
    window.addEventListener('pointermove', move)
    window.addEventListener('pointerup', stop)
    window.addEventListener('pointercancel', stop)
    return () => {
      window.removeEventListener('pointermove', move)
      window.removeEventListener('pointerup', stop)
      window.removeEventListener('pointercancel', stop)
    }
  })

  const scrollToPage = (pageNumber) => {
    pagesRef.current[pageNumber - 1]?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }

  const run = async (action, success) => {
    setBusy(true)
    setError('')
    setNotice('')
    try {
      if (dirtyRef.current.size) await saveDirty()
      const next = await action()
      if (next) applyDetail(next, true)
      if (success) setNotice(success)
      onChanged?.()
      return next
    } catch (actionError) {
      setError(actionError.message)
      return null
    } finally {
      setBusy(false)
    }
  }

  const addSeal = (pageNumber, point) => {
    if (!canEdit) return
    const size = pageSize(pageNumber)
    const aspect = detail.sealProfile?.configured ? detail.sealProfile.aspect : 1.5
    const width = aspect >= 1 ? 140 : 110 * aspect
    const height = width / aspect
    const x = clamp((point ? point.x : size.width / 2) - width / 2, 0, size.width - width)
    const y = clamp((point ? point.y : size.height / 2) - height / 2, 0, size.height - height)
    run(() => sendJson(`/api/documents/${documentId}/seals`, 'POST', { page: pageNumber, x, y, width, height }), `Đã đóng dấu vào trang ${pageNumber}. Kéo để chỉnh vị trí.`)
  }

  const deleteSeal = (seal) => {
    if (!window.confirm(`Xóa con dấu ở trang ${seal.page}?`)) return
    dirtyRef.current.delete(seal.id)
    run(() => requestApi(`/api/documents/${documentId}/seals/${seal.id}`, { method: 'DELETE' }), 'Đã xóa con dấu.')
  }

  const forward = () => {
    const recipient = recipients.find((person) => person.id === forwardTo)
    if (!recipient) {
      setError('Chọn người nhận chuyển tiếp.')
      return
    }
    const message = `Chuyển tài liệu cho ${recipient.name}?\n\nSau khi chuyển, bạn không sửa được con dấu của mình cho đến khi tài liệu được chuyển lại cho bạn.`
      + (recipient.canSign ? '' : `\n\nLưu ý: ${recipient.name} chưa có quyền đóng dấu PDF (chỉ xem và chuyển tiếp được).`)
    if (!window.confirm(message)) return
    run(() => sendJson(`/api/documents/${documentId}/forward`, 'POST', { toUserId: Number(forwardTo), note }), `Đã chuyển cho ${recipient.name}.`)
      .then((next) => {
        if (next) {
          setForwardTo('')
          setNote('')
        }
      })
  }

  const complete = () => {
    if (!window.confirm('Hoàn tất và khóa tài liệu? Sau khi khóa, không ai sửa được con dấu nữa.')) return
    run(() => sendJson(`/api/documents/${documentId}/complete`, 'POST'), 'Đã hoàn tất và khóa tài liệu.')
  }

  if (!detail) {
    return (
      <section className="document-editor">
        <button type="button" className="return-workflow" onClick={onBack}>{backLabel}</button>
        {error ? <p role="alert" className="app-error">{error}</p> : <p className="completed-empty">Đang mở tài liệu...</p>}
      </section>
    )
  }

  const mySeals = seals.filter((seal) => seal.userId === myId)
  const recipientOptions = recipients.map((person) => ({
    value: person.id,
    label: `${person.name}${person.userCode ? ` · ${person.userCode}` : ''}${person.canSign ? '' : ' (chưa có quyền đóng dấu)'}`,
    searchText: person.departmentName || '',
    department: person.departmentName || null,
  }))

  return (
    <section className={`document-editor${dragging ? ' is-dragging' : ''}`}>
      <div className="document-topbar">
        <div className="document-title">
          <button type="button" className="return-workflow" onClick={onBack}>{backLabel}</button>
          <div>
            <h2>{detail.name}</h2>
            <p>
              Người tạo: {detail.ownerName || '—'} ·{' '}
              {detail.status === 'completed'
                ? `Đã hoàn tất ${formatDate(detail.completedAt)}`
                : detail.isHolder ? 'Đang ở chỗ tôi' : `Đang ở chỗ ${detail.holderName}`}
            </p>
          </div>
        </div>

        {detail.canForward ? (
          <div className="forward-bar">
            <label className="forward-recipient">
              <span>Chuyển tiếp cho</span>
              <SearchableSelect label="Người nhận chuyển tiếp" value={forwardTo} options={recipientOptions} placeholder="Tìm tên, mã NV, phòng ban..." onChange={setForwardTo} />
            </label>
            <label className="forward-note">
              <span>Lời nhắn (tùy chọn)</span>
              <input maxLength={500} value={note} placeholder="VD: Nhờ anh đóng dấu trang 3" onChange={(event) => setNote(event.target.value)} />
            </label>
            <button type="button" className="primary-action" disabled={busy || !forwardTo} onClick={forward}>Chuyển tiếp</button>
            <button type="button" className="complete-action" disabled={busy || !detail.canComplete} title={detail.canComplete ? '' : 'Cần ít nhất một con dấu'} onClick={complete}>
              Hoàn tất &amp; khóa
            </button>
          </div>
        ) : detail.status === 'completed' ? (
          <div className="forward-bar locked">
            <span>Tài liệu đã khóa.</span>
            <a className="completed-download primary-link" href={detail.stampedFileUrl}>Tải PDF đã đóng dấu</a>
            <a className="completed-download" href={detail.fileUrl}>Tải bản gốc</a>
          </div>
        ) : (
          <div className="forward-bar locked">
            <span>Tài liệu đang ở chỗ <strong>{detail.holderName}</strong>. Bạn chỉ xem; khi được chuyển lại, bạn sửa được con dấu của mình.</span>
          </div>
        )}
      </div>

      {error && <p role="alert" className="app-error">{error}</p>}
      {notice && <p role="status" className="app-notice">{notice}</p>}

      <div className="main-layout">
        <aside className="sidebar">
          {canEdit && (
            <section className="my-seal-card">
              <h3>Đóng dấu</h3>
              {detail.sealProfile?.imageUrl ? <img src={detail.sealProfile.imageUrl} alt="Mẫu con dấu của tôi" /> : <p>Chưa thiết kế con dấu – sẽ dùng kiểu dấu chữ mặc định.</p>}
              <button type="button" className="primary-action" disabled={busy} onClick={() => addSeal(Math.min(visiblePage, totalPages))}>
                ＋ Đóng dấu vào trang {Math.min(visiblePage, totalPages)}
              </button>
              <small>Hoặc nhấp đúp vào vị trí bất kỳ trên trang để đóng dấu tại đó. Có thể đóng nhiều dấu trên nhiều trang.</small>
              <button type="button" className="link-button" onClick={onOpenSettings}>Đổi mẫu con dấu</button>
            </section>
          )}
          {detail.isHolder && detail.status === 'in_progress' && !detail.canStamp && (
            <p className="signer-waiting">Bạn chưa có quyền đóng dấu PDF; bạn vẫn có thể chuyển tiếp tài liệu.</p>
          )}

          <section className="user-panel">
            <h3>Con dấu trên tài liệu ({seals.length})</h3>
            {seals.map((seal) => (
              <div key={seal.id} className={`user-row ${seal.id === selectedSealId ? 'selected' : ''}`}>
                <div className="user-badge" style={{ background: seal.imageUrl ? '#fff' : seal.color }}>
                  {seal.imageUrl ? <img src={seal.imageUrl} alt="" /> : seal.sealText}
                </div>
                <div>
                  <strong>{seal.name}{seal.userId === myId ? ' (tôi)' : ''}</strong>
                  <small>Trang {seal.page} · {seal.editable ? 'đang sửa được' : 'đã khóa'}</small>
                </div>
                <button type="button" className="link-button" onClick={() => { setSelectedSealId(seal.id); scrollToPage(seal.page) }}>Xem</button>
                {seal.editable && <button type="button" className="link-button danger" onClick={() => deleteSeal(seal)}>Xóa</button>}
              </div>
            ))}
            {!seals.length && <p className="completed-empty">Chưa có con dấu nào.</p>}
            {canEdit && mySeals.length > 0 && <p className="seal-page-info">Dấu của bạn được lưu tự động khi kéo/đổi cỡ. Sau khi chuyển tiếp, dấu bị khóa cho đến khi tài liệu quay lại.</p>}
          </section>

          <section className="route-history">
            <h3>Lịch sử chuyển tay</h3>
            <ol>
              {detail.routes.map((route) => (
                <li key={route.id}>
                  <strong>{routeText(route)}</strong>
                  <small>{formatDate(route.createdAt)}</small>
                  {route.note && <em>“{route.note}”</em>}
                </li>
              ))}
            </ol>
          </section>
        </aside>

        <main className="viewer-wrap">
          <div className="viewer-toolbar">
            <div>
              <strong>{detail.name}</strong>
              <span>Đang xem trang {Math.min(visiblePage, totalPages)} / {totalPages}</span>
            </div>
            {pageSizes.length > 1 && (
              <div className="page-nav">
                <button type="button" aria-label="Trang trước" disabled={visiblePage <= 1} onClick={() => scrollToPage(visiblePage - 1)}>←</button>
                <strong>{Math.min(visiblePage, totalPages)}/{totalPages}</strong>
                <button type="button" aria-label="Trang sau" disabled={visiblePage >= totalPages} onClick={() => scrollToPage(visiblePage + 1)}>→</button>
              </div>
            )}
          </div>
          {canEdit && <p className="viewer-hint">Kéo dấu để di chuyển (kéo sang trang khác được), kéo góc dưới phải để đổi cỡ, bấm × để xóa, nhấp đúp vào trang để đóng thêm dấu.</p>}
          <div className="pdf-stage">
            <PdfPages
              file={file}
              pagesRef={pagesRef}
              onLoaded={setPageSizes}
              onVisiblePageChange={setVisiblePage}
              onPageDoubleClick={canEdit ? addSeal : undefined}
              renderOverlay={(pageNumber, size) => seals
                .filter((seal) => seal.page === pageNumber)
                .map((seal) => (
                  <SealMark
                    key={seal.id}
                    seal={seal}
                    pageSize={size}
                    mode={seal.editable ? 'active' : 'signed'}
                    selected={seal.id === selectedSealId}
                    title={seal.editable ? 'Kéo để di chuyển, kéo góc để đổi cỡ' : `${seal.name} – trang ${seal.page}`}
                    onMoveStart={startDrag(seal, 'move')}
                    onResizeStart={startDrag(seal, 'resize')}
                    onDelete={() => deleteSeal(seal)}
                  />
                ))}
            />
          </div>
        </main>
      </div>
    </section>
  )
}

export default DocumentEditor
