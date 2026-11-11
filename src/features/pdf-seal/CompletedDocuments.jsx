import { useState } from 'react'
import { SearchPagination } from '../../components/SearchableControls'
import { useSearchPagination } from '../../hooks/useSearchPagination'
import { useLocale } from '../../locale-context'

const roleLabels = { owner: 'Tôi tạo', participant: 'Tôi tham gia' }

/** Danh sách tài liệu đã hoàn tất (tôi tạo hoặc đã tham gia); bấm vào để xem chi tiết. */
function CompletedDocuments({ documents, loading, error, onOpen }) {
  const { locale } = useLocale()
  const [role, setRole] = useState('')
  const filtered = documents.filter((document) => !role || document.role === role)
  const list = useSearchPagination(filtered, (document) => `${document.name} ${document.ownerName || ''}`, 12)
  const formatDate = (value) => {
    if (!value) return '—'
    const date = new Date(`${String(value).replace(' ', 'T')}Z`)
    return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(date)
  }

  return (
    <section className="completed-panel">
      <div className="completed-heading">
        <div>
          <p className="eyebrow">Lưu trữ</p>
          <h2>Tài liệu đã đóng dấu</h2>
          <p className="completed-note">Bấm vào tài liệu để xem chi tiết: các con dấu theo trang, lịch sử chuyển tay, xem PDF và tải bản đã đóng dấu.</p>
        </div>
        <label className="completed-filter">
          <span>Hiển thị</span>
          <select value={role} onChange={(event) => setRole(event.target.value)}>
            <option value="">Tất cả ({documents.length})</option>
            <option value="owner">Tôi tạo</option>
            <option value="participant">Tôi tham gia</option>
          </select>
        </label>
      </div>
      {error && <p role="alert" className="app-error">{error}</p>}
      <SearchPagination list={list} />
      {loading && !documents.length ? (
        <p className="completed-empty">Đang tải danh sách...</p>
      ) : (
        <ul className="completed-list">
          {list.pageItems.map((document) => (
            <li key={document.id}>
              <button type="button" className="completed-document" onClick={() => onOpen(document.id)}>
                <div className="completed-file-icon" aria-hidden="true">PDF</div>
                <div className="completed-document-info">
                  <strong>{document.name}</strong>
                  <small>Hoàn tất {formatDate(document.completedAt)} · {document.sealCount} con dấu · Người tạo: {document.ownerName || '—'}</small>
                </div>
                <span className={`completed-role ${document.role === 'owner' ? 'owner' : 'signer'}`}>{roleLabels[document.role]}</span>
                <span className="completed-chevron" aria-hidden="true">›</span>
              </button>
            </li>
          ))}
        </ul>
      )}
      {!loading && !documents.length && <p className="completed-empty">Chưa có tài liệu nào hoàn tất.</p>}
      {documents.length > 0 && !list.pageItems.length && <p className="completed-empty">Không tìm thấy tài liệu phù hợp.</p>}
    </section>
  )
}

export default CompletedDocuments
