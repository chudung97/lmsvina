import { useEffect, useState } from 'react'
import { SearchableMultiSelect } from '../../components/SearchableControls'
import { useLocale } from '../../locale-context'
import { useRealtimeRefresh } from '../../realtime'
import '../attendance/Attendance.css'
import './Overtime.css'

async function requestApi(url, options = {}) {
  const response = await fetch(url, options)
  const result = await response.json()
  if (!response.ok) throw new Error(result.error || 'Không thể kết nối máy chủ.')
  return result
}

function sendJson(url, method, body) {
  return requestApi(url, {
    method,
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  })
}

const pad = (value) => String(value).padStart(2, '0')
const toIsoDate = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`

function currentMonth() {
  const now = new Date()
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}`
}

function addDays(isoDate, days) {
  const date = new Date(`${isoDate}T00:00:00`)
  date.setDate(date.getDate() + days)
  return toIsoDate(date)
}

/** Khoảng ngày còn được nhập trong tháng (sau ngày chốt công). */
function editableRange(month, lockedUntil) {
  const [year, monthNumber] = month.split('-').map(Number)
  const max = `${month}-${pad(new Date(year, monthNumber, 0).getDate())}`
  let min = `${month}-01`
  if (lockedUntil && lockedUntil >= min) min = addDays(lockedUntil, 1)
  return { min, max, fullyLocked: min > max }
}

function defaultDate(month, lockedUntil) {
  const { min, max } = editableRange(month, lockedUntil)
  const today = toIsoDate(new Date())
  return today >= min && today <= max ? today : min
}

function formatDate(value, locale, withWeekday = true) {
  if (!value) return ''
  const date = new Date(`${value}T00:00:00`)
  if (Number.isNaN(date.getTime())) return value
  return new Intl.DateTimeFormat(locale, {
    ...(withWeekday ? { weekday: 'short' } : {}),
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(date)
}

const formatHours = (value, locale) => Number(value || 0).toLocaleString(locale, { maximumFractionDigits: 1 })

function StatusBadge({ entry }) {
  return <span className={`overtime-status overtime-status-${entry.status}`}>{entry.statusLabel}</span>
}

function ApprovalLine({ steps }) {
  if (!steps?.length) return <span className="overtime-muted">—</span>
  return (
    <ol className="overtime-line">
      {steps.map((step) => (
        <li
          key={step.sequence}
          className={`overtime-line-step overtime-line-${step.status}`}
          title={step.note ? `${step.name}: ${step.note}` : step.name}
        >
          <span aria-hidden="true">{step.status === 'approved' ? '✓' : step.status === 'rejected' ? '✕' : step.sequence}</span>
          {step.name}
          {step.status === 'rejected' && step.note && <em> — {step.note}</em>}
        </li>
      ))}
    </ol>
  )
}

/** Form nhập/sửa tăng ca. showApprovers=false khi quản lý sửa dòng của nhân viên. */
function EntryForm({ meta, month, editing, showApprovers, onSaved, onCancel, employeeName }) {
  const range = editableRange(month, meta.lockedUntil)
  const blankForm = () => ({
    shift: meta.shifts[0]?.key ?? '',
    date: defaultDate(month, meta.lockedUntil),
    hours: '',
    note: '',
    approverIds: meta.lastApproverIds ?? [],
  })
  const [form, setForm] = useState(() => (editing
    ? {
      shift: editing.shift,
      date: editing.date,
      hours: String(editing.hours),
      note: editing.note || '',
      approverIds: editing.approvalSteps.map((step) => step.userId),
    }
    : blankForm()))
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const approverById = new Map(meta.approvers.map((user) => [user.id, user]))

  const moveApprover = (index, offset) => {
    const next = [...form.approverIds]
    const target = index + offset
    if (target < 0 || target >= next.length) return
    ;[next[index], next[target]] = [next[target], next[index]]
    setForm({ ...form, approverIds: next })
  }

  const submit = async (event) => {
    event.preventDefault()
    if (showApprovers && form.approverIds.length === 0) {
      setError('Vui lòng chọn line duyệt (ít nhất một người duyệt).')
      return
    }
    setError('')
    setSaving(true)
    try {
      const body = { shift: form.shift, date: form.date, hours: Number(form.hours), note: form.note }
      if (showApprovers) body.approverIds = form.approverIds
      await sendJson(editing ? `/api/overtime/${editing.id}` : '/api/overtime', editing ? 'PATCH' : 'POST', body)
      if (!editing) setForm((current) => ({ ...blankForm(), shift: current.shift, approverIds: current.approverIds }))
      onSaved(editing ? 'Đã cập nhật tăng ca.' : 'Đã thêm tăng ca, đang chờ duyệt.')
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setSaving(false)
    }
  }

  if (!editing && range.fullyLocked) {
    return <p className="overtime-locked-note">Tháng này đã chốt công, không thể nhập thêm tăng ca.</p>
  }

  return (
    <form className="overtime-form" onSubmit={submit}>
      {employeeName && <p className="overtime-editing-for">Nhân viên: <strong>{employeeName}</strong></p>}
      <div className="overtime-form-row">
        <label>
          <span>Ca</span>
          <select required value={form.shift} onChange={(event) => setForm({ ...form, shift: event.target.value })}>
            {meta.shifts.map((shift) => <option key={shift.key} value={shift.key}>{shift.label}</option>)}
          </select>
        </label>
        <label>
          <span>Số giờ</span>
          <input
            type="number"
            required
            min="0.5"
            max="24"
            step="0.5"
            inputMode="decimal"
            placeholder="VD: 2"
            value={form.hours}
            onChange={(event) => setForm({ ...form, hours: event.target.value })}
          />
        </label>
      </div>
      <label>
        <span>Ngày</span>
        <input
          type="date"
          required
          min={range.min}
          max={range.max}
          value={form.date}
          onChange={(event) => setForm({ ...form, date: event.target.value })}
        />
      </label>
      <label>
        <span>Ghi chú</span>
        <textarea
          rows="3"
          maxLength="500"
          placeholder="Nội dung công việc tăng ca..."
          value={form.note}
          onChange={(event) => setForm({ ...form, note: event.target.value })}
        />
      </label>
      {showApprovers && (
        <div className="overtime-approvers">
          <span className="overtime-field-label">Line duyệt <b>*</b> <small>(duyệt lần lượt theo thứ tự)</small></span>
          <SearchableMultiSelect
            label="Line duyệt tăng ca"
            selectedValues={form.approverIds}
            placeholder="Tìm theo tên hoặc mã nhân viên..."
            options={meta.approvers.map((user) => ({
              value: user.id,
              label: `${user.name}${user.userCode ? ` · ${user.userCode}` : ''}`,
              searchText: `${user.name} ${user.userCode || ''}`,
              department: user.departmentName ?? null,
            }))}
            onChange={(approverIds) => setForm({ ...form, approverIds })}
          />
          {form.approverIds.length > 0 && (
            <ol className="overtime-approver-order">
              {form.approverIds.map((userId, index) => (
                <li key={userId}>
                  <span className="overtime-approver-index">{index + 1}</span>
                  <span className="overtime-approver-name">{approverById.get(userId)?.name ?? 'Người dùng không còn hoạt động'}</span>
                  <button type="button" aria-label="Chuyển lên" disabled={index === 0} onClick={() => moveApprover(index, -1)}>↑</button>
                  <button type="button" aria-label="Chuyển xuống" disabled={index === form.approverIds.length - 1} onClick={() => moveApprover(index, 1)}>↓</button>
                  <button
                    type="button"
                    aria-label="Bỏ khỏi line duyệt"
                    onClick={() => setForm({ ...form, approverIds: form.approverIds.filter((id) => id !== userId) })}
                  >
                    ✕
                  </button>
                </li>
              ))}
            </ol>
          )}
        </div>
      )}
      {editing && showApprovers && editing.status !== 'pending' && (
        <p className="overtime-hint">Lưu thay đổi sẽ gửi duyệt lại từ người đầu tiên trong line.</p>
      )}
      {error && <p role="alert" className="attendance-error">{error}</p>}
      <div className="overtime-form-actions">
        <button type="submit" className="overtime-primary" disabled={saving}>
          {saving ? 'Đang lưu...' : editing ? 'Lưu thay đổi' : 'Thêm tăng ca'}
        </button>
        {onCancel && <button type="button" className="attendance-refresh" onClick={onCancel}>Hủy</button>}
      </div>
    </form>
  )
}

function MonthInput({ month, onChange }) {
  return (
    <label className="overtime-month">
      <span>Tháng</span>
      <input type="month" required value={month} onChange={(event) => event.target.value && onChange(event.target.value)} />
    </label>
  )
}

function useEntriesLoader(url, deps) {
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    const controller = new AbortController()
    setLoading(true)
    requestApi(url, { signal: controller.signal })
      .then((result) => {
        setData(result)
        setError('')
      })
      .catch((requestError) => {
        if (requestError.name !== 'AbortError') setError(`Không thể tải dữ liệu tăng ca: ${requestError.message}`)
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false)
      })
    return () => controller.abort()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [url, ...deps])

  return { data, loading, error }
}

async function deleteEntry(entry, locale) {
  const what = `Xóa tăng ca ngày ${formatDate(entry.date, locale)} (${entry.shiftLabel})`
  if (!window.confirm(entry.isOwner ? `${what}?` : `${what} của ${entry.userName}?`)) return false
  await requestApi(`/api/overtime/${entry.id}`, { method: 'DELETE' })
  return true
}

function MyOvertime({ meta, month, onMonthChange, version, onChanged }) {
  const { locale } = useLocale()
  const [editing, setEditing] = useState(null)
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')
  const { data, loading, error: loadError } = useEntriesLoader(`/api/overtime/mine?month=${month}`, [version])
  const entries = data?.entries ?? []

  const saved = (message) => {
    setEditing(null)
    setError('')
    setNotice(message)
    onChanged()
  }

  const remove = async (entry) => {
    setNotice('')
    setError('')
    try {
      if (await deleteEntry(entry, locale)) {
        if (editing?.id === entry.id) setEditing(null)
        saved('Đã xóa tăng ca.')
      }
    } catch (requestError) {
      setError(requestError.message)
    }
  }

  return (
    <div className="overtime-layout">
      <section className="attendance-list-card overtime-form-card" aria-labelledby="overtime-form-title">
        <div className="attendance-card-heading">
          <div className="attendance-heading-icon" aria-hidden="true">＋</div>
          <div>
            <h2 id="overtime-form-title">{editing ? 'Sửa tăng ca' : 'Nhập tăng ca'}</h2>
            <p>Chọn ca, ngày, số giờ, ghi chú và line duyệt.</p>
          </div>
        </div>
        <EntryForm
          key={`${editing?.id ?? 'new'}-${month}-${meta.lockedUntil ?? ''}`}
          meta={meta}
          month={month}
          editing={editing}
          showApprovers
          onSaved={saved}
          onCancel={editing ? () => setEditing(null) : null}
        />
        {notice && <p role="status" className="overtime-notice">{notice}</p>}
        {error && <p role="alert" className="attendance-error">{error}</p>}
      </section>

      <section className="attendance-list-card" aria-labelledby="overtime-mine-title">
        <div className="attendance-card-heading">
          <div className="attendance-heading-icon" aria-hidden="true">⏱</div>
          <div>
            <h2 id="overtime-mine-title">Tăng ca trong tháng</h2>
            <p>
              Tổng {formatHours(data?.totalHours, locale)} giờ · Đã duyệt {formatHours(data?.approvedHours, locale)} giờ
              · Chờ duyệt {formatHours(data?.pendingHours, locale)} giờ
            </p>
          </div>
          <MonthInput month={month} onChange={(value) => { setEditing(null); onMonthChange(value) }} />
        </div>
        {loadError && <p role="alert" className="attendance-error">{loadError}</p>}
        <div className="attendance-table-wrap" aria-busy={loading}>
          <table className="attendance-table overtime-table">
            <thead>
              <tr>
                <th scope="col">Ngày</th>
                <th scope="col">Ca</th>
                <th scope="col">Số giờ</th>
                <th scope="col">Ghi chú</th>
                <th scope="col">Trạng thái</th>
                <th scope="col">Line duyệt</th>
                <th scope="col"><span className="overtime-sr-only">Thao tác</span></th>
              </tr>
            </thead>
            <tbody>
              {!loading && entries.map((entry) => (
                <tr key={entry.id} className={editing?.id === entry.id ? 'overtime-editing' : ''}>
                  <td data-label="Ngày">{formatDate(entry.date, locale)}</td>
                  <td data-label="Ca">{entry.shiftLabel}</td>
                  <td data-label="Số giờ">{formatHours(entry.hours, locale)}</td>
                  <td data-label="Ghi chú" className="overtime-note">{entry.note || '—'}</td>
                  <td data-label="Trạng thái"><StatusBadge entry={entry} /></td>
                  <td data-label="Line duyệt"><ApprovalLine steps={entry.approvalSteps} /></td>
                  <td data-label="Thao tác" className="overtime-row-actions">
                    <div>
                      {entry.locked && <span className="overtime-lock-tag" title="Đã chốt công">🔒 Đã chốt</span>}
                      {entry.canEdit && <button type="button" onClick={() => { setNotice(''); setEditing(entry) }}>Sửa</button>}
                      {entry.canDelete && <button type="button" className="overtime-danger" onClick={() => remove(entry)}>Xóa</button>}
                    </div>
                  </td>
                </tr>
              ))}
              {loading && <tr><td className="attendance-empty" colSpan="7">Đang tải dữ liệu tăng ca...</td></tr>}
              {!loading && !loadError && entries.length === 0 && (
                <tr><td className="attendance-empty" colSpan="7">Chưa có tăng ca trong tháng này.</td></tr>
              )}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  )
}

function ApprovalsTab({ month, onMonthChange, version, onChanged }) {
  const { locale } = useLocale()
  const [mode, setMode] = useState('pending')
  const [selected, setSelected] = useState([])
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')
  const url = mode === 'pending' ? '/api/overtime/approvals?mode=pending' : `/api/overtime/approvals?mode=history&month=${month}`
  const { data, loading, error: loadError } = useEntriesLoader(url, [version])
  const entries = data?.entries ?? []
  const decidable = entries.filter((entry) => entry.canDecide)
  const selectedIds = selected.filter((id) => decidable.some((entry) => entry.id === id))

  const decide = async (ids, decision) => {
    let note = ''
    if (decision === 'reject') {
      note = window.prompt('Lý do từ chối:')?.trim() ?? ''
      if (!note) return
    }
    setBusy(true)
    setError('')
    setNotice('')
    try {
      if (ids.length === 1) {
        await sendJson(`/api/overtime/${ids[0]}/decision`, 'POST', { decision, note })
        setNotice(decision === 'approve' ? 'Đã duyệt.' : 'Đã từ chối.')
      } else {
        const result = await sendJson('/api/overtime/approvals/decision', 'POST', { ids, decision, note })
        setNotice(`Đã ${decision === 'approve' ? 'duyệt' : 'từ chối'} ${result.updated} dòng${result.skipped ? `, bỏ qua ${result.skipped} dòng đã được xử lý` : ''}.`)
      }
      setSelected([])
      onChanged()
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="attendance-list-card" aria-labelledby="overtime-approvals-title">
      <div className="attendance-card-heading">
        <div className="attendance-heading-icon" aria-hidden="true">✓</div>
        <div>
          <h2 id="overtime-approvals-title">{mode === 'pending' ? 'Chờ tôi duyệt' : 'Tăng ca tôi nằm trong line duyệt'}</h2>
          <p>{entries.length} dòng · {formatHours(data?.totalHours, locale)} giờ</p>
        </div>
        <div className="overtime-segmented" role="group" aria-label="Chế độ xem">
          <button type="button" className={mode === 'pending' ? 'active' : ''} onClick={() => setMode('pending')}>Đến lượt tôi</button>
          <button type="button" className={mode === 'history' ? 'active' : ''} onClick={() => setMode('history')}>Theo tháng</button>
        </div>
        {mode === 'history' && <MonthInput month={month} onChange={onMonthChange} />}
      </div>

      {decidable.length > 0 && (
        <div className="overtime-bulk-bar">
          <label>
            <input
              type="checkbox"
              checked={selectedIds.length === decidable.length}
              onChange={(event) => setSelected(event.target.checked ? decidable.map((entry) => entry.id) : [])}
            />
            Chọn tất cả ({decidable.length})
          </label>
          <button type="button" className="overtime-primary" disabled={busy || selectedIds.length === 0} onClick={() => decide(selectedIds, 'approve')}>
            Duyệt đã chọn ({selectedIds.length})
          </button>
          <button type="button" className="overtime-danger-button" disabled={busy || selectedIds.length === 0} onClick={() => decide(selectedIds, 'reject')}>
            Từ chối đã chọn
          </button>
        </div>
      )}
      {notice && <p role="status" className="overtime-notice">{notice}</p>}
      {(error || loadError) && <p role="alert" className="attendance-error">{error || loadError}</p>}

      <div className="attendance-table-wrap" aria-busy={loading}>
        <table className="attendance-table overtime-table">
          <thead>
            <tr>
              <th scope="col"><span className="overtime-sr-only">Chọn</span></th>
              <th scope="col">Nhân viên</th>
              <th scope="col">Phòng ban</th>
              <th scope="col">Ngày</th>
              <th scope="col">Ca</th>
              <th scope="col">Số giờ</th>
              <th scope="col">Ghi chú</th>
              <th scope="col">Trạng thái</th>
              <th scope="col">Line duyệt</th>
              <th scope="col"><span className="overtime-sr-only">Thao tác</span></th>
            </tr>
          </thead>
          <tbody>
            {!loading && entries.map((entry) => (
              <tr key={entry.id}>
                <td data-label="Chọn">
                  {entry.canDecide && (
                    <input
                      type="checkbox"
                      aria-label={`Chọn tăng ca của ${entry.userName}`}
                      checked={selectedIds.includes(entry.id)}
                      onChange={(event) => setSelected(event.target.checked
                        ? [...selectedIds, entry.id]
                        : selectedIds.filter((id) => id !== entry.id))}
                    />
                  )}
                </td>
                <td data-label="Nhân viên">{entry.userName}<small className="overtime-code">{entry.userCode}</small></td>
                <td data-label="Phòng ban">{entry.departmentName || '—'}</td>
                <td data-label="Ngày">{formatDate(entry.date, locale)}</td>
                <td data-label="Ca">{entry.shiftLabel}</td>
                <td data-label="Số giờ">{formatHours(entry.hours, locale)}</td>
                <td data-label="Ghi chú" className="overtime-note">{entry.note || '—'}</td>
                <td data-label="Trạng thái"><StatusBadge entry={entry} /></td>
                <td data-label="Line duyệt"><ApprovalLine steps={entry.approvalSteps} /></td>
                <td data-label="Thao tác" className="overtime-row-actions">
                  <div>
                    {entry.canDecide && (
                      <>
                        <button type="button" disabled={busy} onClick={() => decide([entry.id], 'approve')}>Duyệt</button>
                        <button type="button" disabled={busy} className="overtime-danger" onClick={() => decide([entry.id], 'reject')}>Từ chối</button>
                      </>
                    )}
                  </div>
                </td>
              </tr>
            ))}
            {loading && <tr><td className="attendance-empty" colSpan="10">Đang tải dữ liệu...</td></tr>}
            {!loading && !loadError && entries.length === 0 && (
              <tr>
                <td className="attendance-empty" colSpan="10">
                  {mode === 'pending' ? 'Không có tăng ca nào đang chờ bạn duyệt.' : 'Không có dữ liệu trong tháng này.'}
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </section>
  )
}

function TeamOvertime({ meta, month, onMonthChange, version, onChanged }) {
  const { locale } = useLocale()
  const [departmentId, setDepartmentId] = useState('')
  const [status, setStatus] = useState('')
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [editing, setEditing] = useState(null)
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')

  useEffect(() => {
    const timer = window.setTimeout(() => setDebouncedSearch(search.trim()), 250)
    return () => window.clearTimeout(timer)
  }, [search])

  const params = new URLSearchParams({ month })
  if (departmentId) params.set('departmentId', departmentId)
  if (status) params.set('status', status)
  if (debouncedSearch) params.set('search', debouncedSearch)
  const { data, loading, error: loadError } = useEntriesLoader(`/api/overtime/team?${params}`, [version])
  const summary = data?.summary ?? []
  const entries = data?.entries ?? []

  const saved = (message) => {
    setEditing(null)
    setError('')
    setNotice(message)
    onChanged()
  }

  const remove = async (entry) => {
    setNotice('')
    setError('')
    try {
      if (await deleteEntry(entry, locale)) {
        if (editing?.id === entry.id) setEditing(null)
        saved('Đã xóa tăng ca.')
      }
    } catch (requestError) {
      setError(requestError.message)
    }
  }

  return (
    <>
      <section className="attendance-list-card" aria-labelledby="overtime-team-summary">
        <div className="attendance-card-heading">
          <div className="attendance-heading-icon" aria-hidden="true">👥</div>
          <div>
            <h2 id="overtime-team-summary">Tổng hợp theo nhân viên</h2>
            <p>
              Tổng {formatHours(data?.totalHours, locale)} giờ · Đã duyệt {formatHours(data?.approvedHours, locale)} giờ
              · {summary.length} nhân viên
            </p>
          </div>
        </div>
        <div className="attendance-filters overtime-team-filters">
          <MonthInput month={month} onChange={(value) => { setEditing(null); onMonthChange(value) }} />
          <label>
            <span>Phòng ban</span>
            <select value={departmentId} onChange={(event) => setDepartmentId(event.target.value)}>
              <option value="">Tất cả phòng ban</option>
              {(data?.departments ?? []).map((department) => (
                <option key={department.id} value={department.id}>{department.name}</option>
              ))}
            </select>
          </label>
          <label>
            <span>Trạng thái</span>
            <select value={status} onChange={(event) => setStatus(event.target.value)}>
              <option value="">Tất cả</option>
              {Object.entries(meta.statuses).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
            </select>
          </label>
          <label>
            <span>Tìm nhân viên</span>
            <input type="search" placeholder="Mã hoặc tên" value={search} onChange={(event) => setSearch(event.target.value)} />
          </label>
        </div>
        {loadError && <p role="alert" className="attendance-error">{loadError}</p>}
        <div className="attendance-table-wrap" aria-busy={loading}>
          <table className="attendance-table">
            <thead>
              <tr>
                <th scope="col">Mã nhân viên</th>
                <th scope="col">Họ tên</th>
                <th scope="col">Phòng ban</th>
                <th scope="col">Số ngày</th>
                <th scope="col">Tổng giờ</th>
                <th scope="col">Đã duyệt</th>
                <th scope="col">Chờ duyệt</th>
              </tr>
            </thead>
            <tbody>
              {!loading && summary.map((row) => (
                <tr key={row.userId}>
                  <td data-label="Mã nhân viên">{row.userCode || '—'}</td>
                  <td data-label="Họ tên">{row.userName}</td>
                  <td data-label="Phòng ban">{row.departmentName || '—'}</td>
                  <td data-label="Số ngày">{row.days}</td>
                  <td data-label="Tổng giờ"><strong>{formatHours(row.totalHours, locale)}</strong></td>
                  <td data-label="Đã duyệt">{formatHours(row.approvedHours, locale)}</td>
                  <td data-label="Chờ duyệt">{formatHours(row.pendingHours, locale)}</td>
                </tr>
              ))}
              {loading && <tr><td className="attendance-empty" colSpan="7">Đang tải dữ liệu tăng ca...</td></tr>}
              {!loading && !loadError && summary.length === 0 && (
                <tr><td className="attendance-empty" colSpan="7">Không có tăng ca phù hợp trong tháng này.</td></tr>
              )}
            </tbody>
          </table>
        </div>
      </section>

      {editing && (
        <section className="attendance-list-card overtime-detail-card overtime-manager-edit" aria-labelledby="overtime-manager-edit">
          <div className="attendance-card-heading">
            <div className="attendance-heading-icon" aria-hidden="true">✎</div>
            <div>
              <h2 id="overtime-manager-edit">Sửa tăng ca của nhân viên</h2>
              <p>Trạng thái duyệt và line duyệt được giữ nguyên.</p>
            </div>
          </div>
          <EntryForm
            key={editing.id}
            meta={meta}
            month={editing.date.slice(0, 7)}
            editing={editing}
            showApprovers={editing.isOwner}
            employeeName={editing.isOwner ? null : `${editing.userName}${editing.userCode ? ` · ${editing.userCode}` : ''}`}
            onSaved={saved}
            onCancel={() => setEditing(null)}
          />
        </section>
      )}

      <section className="attendance-list-card overtime-detail-card" aria-labelledby="overtime-team-detail">
        <div className="attendance-card-heading">
          <div className="attendance-heading-icon" aria-hidden="true">☰</div>
          <div>
            <h2 id="overtime-team-detail">Chi tiết tăng ca</h2>
            <p>{entries.length} lượt khai báo</p>
          </div>
        </div>
        {notice && <p role="status" className="overtime-notice">{notice}</p>}
        {error && <p role="alert" className="attendance-error">{error}</p>}
        <div className="attendance-table-wrap" aria-busy={loading}>
          <table className="attendance-table overtime-table">
            <thead>
              <tr>
                <th scope="col">Nhân viên</th>
                <th scope="col">Phòng ban</th>
                <th scope="col">Ngày</th>
                <th scope="col">Ca</th>
                <th scope="col">Số giờ</th>
                <th scope="col">Ghi chú</th>
                <th scope="col">Trạng thái</th>
                <th scope="col">Line duyệt</th>
                <th scope="col"><span className="overtime-sr-only">Thao tác</span></th>
              </tr>
            </thead>
            <tbody>
              {!loading && entries.map((entry) => (
                <tr key={entry.id} className={editing?.id === entry.id ? 'overtime-editing' : ''}>
                  <td data-label="Nhân viên">{entry.userName}<small className="overtime-code">{entry.userCode}</small></td>
                  <td data-label="Phòng ban">{entry.departmentName || '—'}</td>
                  <td data-label="Ngày">{formatDate(entry.date, locale)}</td>
                  <td data-label="Ca">{entry.shiftLabel}</td>
                  <td data-label="Số giờ">{formatHours(entry.hours, locale)}</td>
                  <td data-label="Ghi chú" className="overtime-note">{entry.note || '—'}</td>
                  <td data-label="Trạng thái"><StatusBadge entry={entry} /></td>
                  <td data-label="Line duyệt"><ApprovalLine steps={entry.approvalSteps} /></td>
                  <td data-label="Thao tác" className="overtime-row-actions">
                    <div>
                      {entry.locked && <span className="overtime-lock-tag" title="Đã chốt công">🔒 Đã chốt</span>}
                      {entry.canEdit && <button type="button" onClick={() => { setNotice(''); setEditing(entry) }}>Sửa</button>}
                      {entry.canDelete && <button type="button" className="overtime-danger" onClick={() => remove(entry)}>Xóa</button>}
                    </div>
                  </td>
                </tr>
              ))}
              {loading && <tr><td className="attendance-empty" colSpan="9">Đang tải dữ liệu tăng ca...</td></tr>}
              {!loading && !loadError && entries.length === 0 && (
                <tr><td className="attendance-empty" colSpan="9">Không có dữ liệu.</td></tr>
              )}
            </tbody>
          </table>
        </div>
      </section>
    </>
  )
}

function LockBar({ meta, onChanged }) {
  const { locale } = useLocale()
  const [value, setValue] = useState(meta.lockedUntil ?? '')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  const save = async (lockedUntil) => {
    if (lockedUntil && !window.confirm(`Chốt công đến hết ngày ${formatDate(lockedUntil, locale, false)}? Sau khi chốt, mọi người không thể thêm, sửa, xóa tăng ca từ ngày này trở về trước.`)) return
    if (!lockedUntil && !window.confirm('Bỏ chốt công? Mọi ngày sẽ được nhập/sửa tăng ca trở lại.')) return
    setBusy(true)
    setError('')
    try {
      await sendJson('/api/overtime/lock', 'PUT', { lockedUntil: lockedUntil || null })
      onChanged()
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className={`overtime-lock-bar${meta.lockedUntil ? ' is-locked' : ''}`}>
      <div>
        <strong>{meta.lockedUntil ? `🔒 Đã chốt công đến hết ngày ${formatDate(meta.lockedUntil, locale, false)}` : 'Chưa chốt công'}</strong>
        <span>
          {meta.lockedUntil
            ? 'Tăng ca từ ngày này trở về trước không thể thêm, sửa hoặc xóa.'
            : 'Mọi ngày đều có thể nhập và sửa tăng ca.'}
        </span>
      </div>
      {meta.canManageLock && (
        <form
          className="overtime-lock-form"
          onSubmit={(event) => {
            event.preventDefault()
            save(value)
          }}
        >
          <label>
            <span>Ngày chốt công</span>
            <input type="date" required value={value} onChange={(event) => setValue(event.target.value)} />
          </label>
          <button type="submit" className="overtime-primary" disabled={busy || !value || value === meta.lockedUntil}>Chốt công</button>
          {meta.lockedUntil && (
            <button type="button" className="attendance-refresh" disabled={busy} onClick={() => save('')}>Bỏ chốt</button>
          )}
        </form>
      )}
      {error && <p role="alert" className="attendance-error">{error}</p>}
    </div>
  )
}

function Overtime() {
  const [month, setMonth] = useState(currentMonth)
  const [tab, setTab] = useState('mine')
  const [meta, setMeta] = useState(null)
  const [metaError, setMetaError] = useState('')
  const [localVersion, setLocalVersion] = useState(0)
  const realtimeVersion = useRealtimeRefresh('overtime')
  const version = realtimeVersion + localVersion
  const refresh = () => setLocalVersion((current) => current + 1)

  useEffect(() => {
    const controller = new AbortController()
    requestApi('/api/overtime/meta', { signal: controller.signal })
      .then((result) => {
        setMeta(result)
        setMetaError('')
      })
      .catch((requestError) => {
        if (requestError.name !== 'AbortError') setMetaError(`Không thể tải cấu hình tăng ca: ${requestError.message}`)
      })
    return () => controller.abort()
  }, [version])

  const tabs = [
    ['mine', 'Tăng ca của tôi'],
    ['approvals', `Chờ tôi duyệt${meta?.pendingApprovalCount ? ` (${meta.pendingApprovalCount})` : ''}`],
    ...(meta?.canViewTeam ? [['team', 'Danh sách nhân viên']] : []),
  ]

  return (
    <section className="attendance-page">
      <header className="attendance-header">
        <div>
          <p className="meetings-eyebrow">KHAI BÁO TĂNG CA</p>
          <h1>Tăng ca</h1>
          <p className="meetings-subtitle">
            {meta?.canViewTeam
              ? 'Nhập tăng ca, duyệt theo line duyệt và xem tăng ca của nhân viên theo phòng ban, theo tháng.'
              : 'Nhập tăng ca, chọn line duyệt và theo dõi tăng ca của bạn trong tháng.'}
          </p>
        </div>
      </header>

      {metaError && <p role="alert" className="attendance-error overtime-wide">{metaError}</p>}
      {!meta && !metaError && <p className="overtime-wide">Đang tải...</p>}

      {meta && (
        <>
          <LockBar key={meta.lockedUntil ?? 'unlocked'} meta={meta} onChanged={refresh} />
          <div className="overtime-tabs" role="tablist" aria-label="Chế độ xem tăng ca">
            {tabs.map(([key, label]) => (
              <button
                key={key}
                type="button"
                role="tab"
                aria-selected={tab === key}
                className={tab === key ? 'active' : ''}
                onClick={() => setTab(key)}
              >
                {label}
              </button>
            ))}
          </div>

          {tab === 'team' && meta.canViewTeam ? (
            <TeamOvertime meta={meta} month={month} onMonthChange={setMonth} version={version} onChanged={refresh} />
          ) : tab === 'approvals' ? (
            <ApprovalsTab month={month} onMonthChange={setMonth} version={version} onChanged={refresh} />
          ) : (
            <MyOvertime meta={meta} month={month} onMonthChange={setMonth} version={version} onChanged={refresh} />
          )}
        </>
      )}
    </section>
  )
}

export default Overtime
