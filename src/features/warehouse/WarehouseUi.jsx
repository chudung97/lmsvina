import { useEffect } from 'react'
import { SearchPagination } from '../../components/SearchableControls'
import { useSearchPagination } from '../../hooks/useSearchPagination'
import { toneOf } from './warehouseApi'

export function Badge({ value, labels }) {
  if (!value) return null
  return <span className={`warehouse-badge ${toneOf(value)}`}>{labels?.[value] ?? value}</span>
}

export function Messages({ error, notice }) {
  return (
    <>
      {error && <p role="alert" className="warehouse-message error">{error}</p>}
      {notice && <p role="status" className="warehouse-message success">{notice}</p>}
    </>
  )
}

/**
 * Bảng dữ liệu có tìm kiếm + phân trang phía trình duyệt (dùng hook sẵn có của Workdesk).
 * columns: [{ key, label, render?, value?, numeric?, csv? }]
 */
export function WarehouseTable({
  columns,
  rows,
  searchText,
  pageSize = 25,
  empty = 'Không có dữ liệu phù hợp.',
  loading = false,
  rowKey = 'id',
}) {
  const list = useSearchPagination(rows, searchText, pageSize)

  return (
    <>
      <SearchPagination list={list} />
      <div className="warehouse-table-wrap" aria-busy={loading}>
        <table className="warehouse-table">
          <thead>
            <tr>
              {columns.map((column) => (
                <th key={column.key} scope="col" className={column.numeric ? 'numeric' : ''}>{column.label}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {!loading && list.pageItems.map((row, index) => (
              <tr key={typeof rowKey === 'function' ? rowKey(row) : row[rowKey] ?? index}>
                {columns.map((column) => (
                  <td key={column.key} data-label={column.label} className={column.numeric ? 'numeric' : ''}>
                    {column.render ? column.render(row) : column.value ? column.value(row) : row[column.key]}
                  </td>
                ))}
              </tr>
            ))}
            {loading && <tr><td className="warehouse-empty" colSpan={columns.length}>Đang tải dữ liệu...</td></tr>}
            {!loading && list.totalItems === 0 && (
              <tr><td className="warehouse-empty" colSpan={columns.length}>{empty}</td></tr>
            )}
          </tbody>
        </table>
      </div>
    </>
  )
}

export function WarehouseModal({ title, onClose, children, wide = false }) {
  useEffect(() => {
    const handleKey = (event) => {
      if (event.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', handleKey)
    return () => window.removeEventListener('keydown', handleKey)
  }, [onClose])

  return (
    <div
      className="warehouse-modal-backdrop"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) onClose()
      }}
    >
      <section className={`warehouse-modal${wide ? ' wide' : ''}`} role="dialog" aria-modal="true" aria-label={title}>
        <div className="warehouse-modal-heading">
          <h2>{title}</h2>
          <button type="button" className="warehouse-close" aria-label="Đóng" onClick={onClose}>×</button>
        </div>
        {children}
      </section>
    </div>
  )
}
