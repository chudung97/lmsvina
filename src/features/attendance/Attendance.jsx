import { useEffect, useState } from 'react'
import { SearchPagination } from '../../components/SearchableControls'
import { useLocale } from '../../locale-context'
import './Attendance.css'

const pageSize = 25

async function requestApi(url, options = {}) {
  const response = await fetch(url, options)
  const result = await response.json()
  if (!response.ok) throw new Error(result.error || 'Không thể kết nối máy chủ.')
  return result
}

function formatCheckTime(value, locale) {
  const date = new Date(String(value).replace(' ', 'T'))
  if (Number.isNaN(date.getTime())) return value

  return new Intl.DateTimeFormat(locale, {
    dateStyle: 'medium',
    timeStyle: 'medium',
  }).format(date)
}

function Attendance() {
  const { locale } = useLocale()
  const [records, setRecords] = useState([])
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [page, setPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [totalPages, setTotalPages] = useState(1)
  const [refreshKey, setRefreshKey] = useState(0)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    const timer = window.setTimeout(() => setDebouncedSearch(search.trim()), 250)
    return () => window.clearTimeout(timer)
  }, [search])

  useEffect(() => {
    const timer = window.setInterval(() => {
      setRefreshKey((current) => current + 1)
    }, 5 * 60 * 1000)
    return () => window.clearInterval(timer)
  }, [])

  useEffect(() => {
    const controller = new AbortController()
    const params = new URLSearchParams({
      page: String(page),
      perPage: String(pageSize),
    })
    if (debouncedSearch) params.set('search', debouncedSearch)
    if (from) params.set('from', from)
    if (to) params.set('to', to)

    requestApi(`/api/attendance?${params}`, { signal: controller.signal })
      .then((result) => {
        setRecords(result.data)
        setTotal(result.total)
        setTotalPages(result.totalPages)
      })
      .catch((requestError) => {
        if (requestError.name !== 'AbortError') {
          setError(`Không thể tải dữ liệu chấm công: ${requestError.message}`)
        }
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false)
      })

    return () => controller.abort()
  }, [debouncedSearch, from, page, refreshKey, to])

  const list = {
    query: search,
    setQuery: (value) => {
      setSearch(value)
      setPage(1)
      setLoading(true)
      setError('')
    },
    page,
    setPage: (value) => {
      setPage(value)
      setLoading(true)
      setError('')
    },
    pageSize,
    totalPages,
    totalItems: total,
  }

  return (
    <section className="attendance-page">
      <header className="attendance-header">
        <div>
          <p className="meetings-eyebrow">DỮ LIỆU CHẤM CÔNG</p>
          <h1>Chấm công</h1>
          <p className="meetings-subtitle">Tra cứu lượt chấm công theo mã nhân viên, tên và ngày.</p>
        </div>
        <div className="attendance-total" aria-live="polite">
          <span>Tổng lượt</span>
          <strong>{total.toLocaleString(locale)}</strong>
        </div>
      </header>

      <section className="attendance-list-card" aria-labelledby="attendance-list-title">
        <div className="attendance-card-heading">
          <div className="attendance-heading-icon" aria-hidden="true">⌚</div>
          <div>
            <h2 id="attendance-list-title">Lịch sử chấm công</h2>
            <p>Tìm kiếm và lọc lượt chấm công trong phạm vi tài khoản của bạn.</p>
          </div>
          <button
            type="button"
            className="attendance-refresh"
            disabled={loading}
            onClick={() => {
              setError('')
              setLoading(true)
              setRefreshKey((current) => current + 1)
            }}
          >
            Làm mới
          </button>
        </div>

        <div className="attendance-filters">
          <label>
            <span>Từ ngày</span>
            <input
              type="date"
              value={from}
              onChange={(event) => {
                setFrom(event.target.value)
                setPage(1)
                setLoading(true)
                setError('')
              }}
            />
          </label>
          <label>
            <span>Đến ngày</span>
            <input
              type="date"
              value={to}
              onChange={(event) => {
                setTo(event.target.value)
                setPage(1)
                setLoading(true)
                setError('')
              }}
            />
          </label>
        </div>

        <SearchPagination list={list} />

        {error && <p role="alert" className="attendance-error">{error}</p>}
        <div className="attendance-table-wrap" aria-busy={loading}>
          <table className="attendance-table">
            <thead>
              <tr>
                <th scope="col">Mã nhân viên</th>
                <th scope="col">Mã chấm công</th>
                <th scope="col">Họ tên</th>
                <th scope="col">Thời gian chấm công</th>
              </tr>
            </thead>
            <tbody>
              {!loading && !error && records.map((record) => (
                <tr key={record.id}>
                  <td data-label="Mã nhân viên">{record.userCode || '—'}</td>
                  <td data-label="Mã chấm công">{record.timekeepingCode || '—'}</td>
                  <td data-label="Họ tên">{record.userName || 'Không tìm thấy mã chấm công trong users'}</td>
                  <td data-label="Thời gian chấm công">{formatCheckTime(record.checkTime, locale)}</td>
                </tr>
              ))}
              {loading && (
                <tr><td className="attendance-empty" colSpan="4">Đang tải dữ liệu chấm công...</td></tr>
              )}
              {!loading && !error && records.length === 0 && (
                <tr><td className="attendance-empty" colSpan="4">Không có dữ liệu chấm công phù hợp.</td></tr>
              )}
              {error && <tr><td className="attendance-empty" colSpan="4">Không thể hiển thị dữ liệu.</td></tr>}
            </tbody>
          </table>
        </div>
      </section>
    </section>
  )
}

export default Attendance
