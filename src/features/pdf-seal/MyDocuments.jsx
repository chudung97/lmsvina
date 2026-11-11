import { useState } from 'react'
import { SearchPagination } from '../../components/SearchableControls'
import { useSearchPagination } from '../../hooks/useSearchPagination'
import { useLocale } from '../../locale-context'

function useDateFormatter() {
  const { locale } = useLocale()
  return (value) => {
    if (!value) return '—'
    const date = new Date(`${String(value).replace(' ', 'T')}Z`)
    return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(date)
  }
}

function DocumentList({ title, description, documents, empty, onOpen, renderStatus }) {
  const formatDate = useDateFormatter()
  const list = useSearchPagination(documents, (document) => `${document.name} ${document.ownerName || ''} ${document.holderName || ''}`, 8)

  return (
    <section className="completed-panel">
      <div className="completed-heading">
        <div>
          <h2>{title} <span className="completed-count inline">{documents.length}</span></h2>
          <p className="completed-note">{description}</p>
        </div>
      </div>
      {documents.length > 0 && <SearchPagination list={list} />}
      <ul className="completed-list">
        {list.pageItems.map((document) => (
          <li key={document.id}>
            <button type="button" className="completed-document" onClick={() => onOpen(document.id)}>
              <div className="completed-file-icon" aria-hidden="true">PDF</div>
              <div className="completed-document-info">
                <strong>{document.name}</strong>
                <small>
                  Người tạo: {document.ownerName || '—'} · {document.sealCount} con dấu (của tôi: {document.mySealCount}) · Cập nhật {formatDate(document.updatedAt)}
                </small>
                {document.lastFromName && document.isHolder && (
                  <small className="document-from">Từ {document.lastFromName}{document.lastNote ? `: “${document.lastNote}”` : ''}</small>
                )}
              </div>
              {renderStatus(document)}
              <span className="completed-chevron" aria-hidden="true">›</span>
            </button>
          </li>
        ))}
      </ul>
      {!documents.length && <p className="completed-empty">{empty}</p>}
      {documents.length > 0 && !list.pageItems.length && <p className="completed-empty">Không tìm thấy tài liệu phù hợp.</p>}
    </section>
  )
}

/** Tài liệu đang xử lý: tải PDF mới, danh sách đang ở chỗ tôi và đã chuyển đi. */
function MyDocuments({ data, loading, error, onOpen, onUploaded }) {
  const [uploading, setUploading] = useState(false)
  const [uploadError, setUploadError] = useState('')

  const upload = async (event) => {
    const file = event.target.files?.[0]
    event.target.value = ''
    if (!file) return
    setUploading(true)
    setUploadError('')
    try {
      const form = new FormData()
      form.append('file', file)
      const response = await fetch('/api/documents', { method: 'POST', body: form })
      const result = await response.json()
      if (!response.ok) throw new Error(result.error || 'Tải tài liệu thất bại.')
      onUploaded(result.id)
    } catch (requestError) {
      setUploadError(requestError.message)
    } finally {
      setUploading(false)
    }
  }

  return (
    <div className="my-documents">
      <label className="upload-box">
        <span>{uploading ? 'Đang tải lên...' : '＋ Tải tài liệu PDF mới để đóng dấu'}</span>
        <input type="file" accept="application/pdf" disabled={uploading} onChange={upload} />
        <small className="upload-hint">Sau khi tải lên, tài liệu nằm trong "Đang ở chỗ tôi": bạn đóng dấu, sửa/xóa dấu tùy ý, rồi chọn người chuyển tiếp hoặc hoàn tất.</small>
      </label>
      {(uploadError || error) && <p role="alert" className="app-error">{uploadError || error}</p>}
      {loading && !data ? (
        <p className="completed-empty">Đang tải danh sách...</p>
      ) : (
        <>
          <DocumentList
            title="Đang ở chỗ tôi"
            description="Tài liệu bạn tải lên chưa chuyển đi, hoặc được chuyển đến bạn. Bấm vào để đóng dấu, sửa dấu và chuyển tiếp."
            documents={data?.holding || []}
            empty="Không có tài liệu nào đang chờ bạn."
            onOpen={onOpen}
            renderStatus={(document) => <span className="completed-role owner">{document.lastFromName ? 'Được chuyển đến' : 'Chưa chuyển đi'}</span>}
          />
          <DocumentList
            title="Đã chuyển đi"
            description="Tài liệu bạn đã tham gia và đang ở chỗ người khác. Con dấu của bạn bị khóa cho đến khi tài liệu được chuyển lại."
            documents={data?.sent || []}
            empty="Chưa có tài liệu nào đang ở chỗ người khác."
            onOpen={onOpen}
            renderStatus={(document) => <span className="completed-role signer">Đang ở chỗ {document.holderName}</span>}
          />
        </>
      )}
    </div>
  )
}

export default MyDocuments
