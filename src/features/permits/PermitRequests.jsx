import { useEffect, useMemo, useState } from 'react'
import { useRealtimeRefresh } from '../../realtime'
import { LocalizedTree } from '../../i18n'
import { useLocale } from '../../locale-context'
import {
  SearchPagination,
  SearchableMultiSelect,
  SearchableSelect,
} from '../../components/SearchableControls'
import { useSearchPagination } from '../../hooks/useSearchPagination'
import './PermitRequests.css'

const today = new Date()
const dateKey = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`
const emptyForm = {
  direction: 'out',
  date: dateKey,
  time: '',
  returnTime: '',
  purpose: '',
  destination: '',
  vehicleInfo: '',
  approverIds: [],
  hrId: '',
  guardId: '',
}

const statusInfo = {
  pending_approval: { label: 'Chờ duyệt', tone: 'waiting', icon: '◷' },
  pending_guard: { label: 'Chờ bảo vệ xác nhận', tone: 'guard', icon: '◉' },
  approved: { label: 'Đã duyệt hoàn tất', tone: 'approved', icon: '✓' },
  rejected: { label: 'Không được duyệt', tone: 'rejected', icon: '×' },
}

async function requestApi(url, options) {
  const response = await fetch(url, options)
  const result = await response.json()
  if (!response.ok) throw new Error(result.error || 'Không thể kết nối máy chủ.')
  return result
}

function formatDate(value, locale) {
  return new Intl.DateTimeFormat(locale, {
    weekday: 'long',
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(new Date(`${value}T12:00:00`))
}

function formatTimestamp(value, locale) {
  if (!value) return ''
  return new Intl.DateTimeFormat(locale, {
    dateStyle: 'short',
    timeStyle: 'short',
  }).format(new Date(`${value.replace(' ', 'T')}Z`))
}

function PermitRequests({ sessionUser }) {
  const { locale } = useLocale()
  const refreshCounter = useRealtimeRefresh('permits', 'accounts')
  const [permits, setPermits] = useState([])
  const [users, setUsers] = useState([])
  const [selectedPermit, setSelectedPermit] = useState(null)
  const [form, setForm] = useState(emptyForm)
  const [decisionNote, setDecisionNote] = useState('')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [showForm, setShowForm] = useState(false)
  const [filter, setFilter] = useState('all')

  useEffect(() => {
    let cancelled = false

    Promise.all([
      requestApi('/api/permits'),
      requestApi('/api/permits/users'),
    ])
      .then(([items, availableUsers]) => {
        if (!cancelled) {
          setPermits(items)
          setUsers(availableUsers)
        }
      })
      .catch((requestError) => {
        if (!cancelled) setError(`Không thể tải danh sách phiếu: ${requestError.message}`)
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [refreshCounter])

  const filteredPermits = useMemo(() => permits.filter((permit) => {
    if (filter === 'mine') return permit.requesterId === sessionUser.id
    if (filter === 'tasks') {
      return (permit.currentApproverId === sessionUser.id && permit.status === 'pending_approval')
        || (permit.guardId === sessionUser.id && permit.status === 'pending_guard')
    }
    return true
  }), [filter, permits, sessionUser.id])
  const permitList = useSearchPagination(
    filteredPermits,
    (permit) => `${permit.requesterName} ${permit.approvalSteps.map((step) => step.name).join(' ')} ${permit.guardName || ''} ${permit.purpose} ${permit.destination} ${permit.direction} ${permit.date} ${permit.time} ${statusInfo[permit.status]?.label || ''}`,
  )
  const pendingTasks = permits.filter((permit) =>
    (permit.currentApproverId === sessionUser.id && permit.status === 'pending_approval')
    || (permit.guardId === sessionUser.id && permit.status === 'pending_guard'),
  ).length
  const completedCount = permits.filter((permit) =>
    ['approved', 'rejected'].includes(permit.status),
  ).length
  const chosenApprovers = form.approverIds.map((approverId) => users.find((user) => user.id === approverId)).filter(Boolean)
  const chosenHr = users.find((user) => user.id === form.hrId)
  const chosenGuard = users.find((user) => user.id === form.guardId)
  const approvers = users
  const hrUsers = users
  const guards = users
  const hasEligiblePermitRoute = users.length >= 3
  const hasValidPermitRoute = form.approverIds.length > 0
    && Boolean(form.hrId && form.guardId)
    && form.hrId !== form.guardId
    && form.hrId !== sessionUser.id
    && form.guardId !== sessionUser.id
    && new Set(form.approverIds).size === form.approverIds.length
    && !form.approverIds.includes(sessionUser.id)
    && !form.approverIds.includes(form.hrId)
    && !form.approverIds.includes(form.guardId)

  const updateForm = (event) => {
    setForm((current) => ({ ...current, [event.target.name]: event.target.value }))
  }

  const createPermit = async (event) => {
    event.preventDefault()
    setError('')
    setSaving(true)
    try {
      const permit = await requestApi('/api/permits', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(form),
      })
      setPermits((current) => [permit, ...current])
      setForm({ ...emptyForm, date: dateKey })
      setShowForm(false)
      setSelectedPermit(permit)
    } catch (requestError) {
      setError(`Không thể gửi phiếu: ${requestError.message}`)
    } finally {
      setSaving(false)
    }
  }

  const submitDecision = async (kind, decision) => {
    if (!selectedPermit) return
    setError('')
    setSaving(true)
    try {
      const updated = await requestApi(
        `/api/permits/${selectedPermit.id}/${kind === 'guard' ? 'guard' : 'decision'}`,
        {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ decision, note: decisionNote }),
        },
      )
      setPermits((current) => current.map((permit) => permit.id === updated.id ? updated : permit))
      setSelectedPermit(updated)
      setDecisionNote('')
    } catch (requestError) {
      setError(`Không thể cập nhật phiếu: ${requestError.message}`)
    } finally {
      setSaving(false)
    }
  }

  const closeDialog = () => {
    setShowForm(false)
    setSelectedPermit(null)
    setDecisionNote('')
    setError('')
  }

  return (
    <LocalizedTree>
    <main className="permit-page">
      <header className="permit-hero">
        <div className="permit-hero-copy">
          <p className="permit-eyebrow"><span>WORKDESK · HÀNH CHÍNH</span></p>
          <h1>Giấy phép ra vào cổng</h1>
          <p>Tạo phiếu, trình duyệt và xác nhận tại cổng — mọi bước được lưu rõ ràng.</p>
          <button type="button" className="permit-create-button" onClick={() => {
            setError('')
            setForm({ ...emptyForm, date: dateKey })
            setShowForm(true)
          }}>
            <span aria-hidden="true">＋</span> Tạo phiếu xin phép
          </button>
        </div>
        <div className="permit-hero-art" aria-hidden="true">
          <div className="permit-art-ring"></div>
          <div className="permit-art-card">
            <span className="permit-art-icon">↗</span>
            <span className="permit-art-line"></span>
            <span className="permit-art-line short"></span>
            <span className="permit-art-check">✓</span>
          </div>
          <span className="permit-art-dot dot-one"></span>
          <span className="permit-art-dot dot-two"></span>
        </div>
      </header>

      {error && <p role="alert" className="permit-error">{error}</p>}

      <section className="permit-stats" aria-label="Tổng quan phiếu">
        <article className="permit-stat-card">
          <span className="permit-stat-icon blue">▤</span>
          <div><small>Tất cả phiếu</small><strong>{permits.length}</strong></div>
        </article>
        <article className="permit-stat-card">
          <span className="permit-stat-icon amber">◷</span>
          <div><small>Việc cần xử lý</small><strong>{pendingTasks}</strong></div>
        </article>
        <article className="permit-stat-card">
          <span className="permit-stat-icon green">✓</span>
          <div><small>Đã kết thúc</small><strong>{completedCount}</strong></div>
        </article>
      </section>

      <section className="permit-list-panel">
        <div className="permit-list-heading">
          <div>
            <p className="permit-section-eyebrow">THEO DÕI QUY TRÌNH</p>
            <h2>Phiếu ra vào cổng</h2>
          </div>
          <div className="permit-filters" aria-label="Lọc phiếu">
            {[
              ['all', 'Tất cả'],
              ['mine', 'Phiếu của tôi'],
              ['tasks', pendingTasks ? `Cần xử lý · ${pendingTasks}` : 'Cần xử lý'],
            ].map(([value, label]) => (
              <button
                type="button"
                key={value}
                className={filter === value ? 'selected' : ''}
                onClick={() => {
                  setFilter(value)
                  permitList.setPage(1)
                }}
              >
                {label}
              </button>
            ))}
          </div>
        </div>

        {loading ? (
          <div className="permit-empty"><span className="permit-spinner"></span><p>Đang tải danh sách phiếu...</p></div>
        ) : (
          <>
            {filteredPermits.length > 0 && <SearchPagination list={permitList} />}
            {permitList.pageItems.length ? (
              <div className="permit-list">
                {permitList.pageItems.map((permit) => {
                  const status = statusInfo[permit.status]
                  return (
                    <button
                      type="button"
                      className="permit-list-item"
                      key={permit.id}
                      onClick={() => {
                        setSelectedPermit(permit)
                        setDecisionNote('')
                        setError('')
                      }}
                    >
                      <span className={`permit-direction-icon ${permit.direction}`}>
                        {permit.direction === 'out' ? '↗' : '↙'}
                      </span>
                      <span className="permit-list-main">
                        <strong>{permit.direction === 'out' ? 'Xin phép ra cổng' : 'Xin phép vào cổng'}</strong>
                        <span>{permit.purpose}</span>
                        <small>{permit.requesterName} · {formatDate(permit.date, locale)} · {permit.time}</small>
                      </span>
                      <span className={`permit-status ${status.tone}`}>
                        <span aria-hidden="true">{status.icon}</span>{status.label}
                      </span>
                      <span className="permit-chevron" aria-hidden="true">›</span>
                    </button>
                  )
                })}
              </div>
            ) : (
              <div className="permit-empty">
                {filteredPermits.length > 0 ? (
                  <>
                    <h3>Không tìm thấy phiếu phù hợp</h3>
                    <p>Thử tìm theo tên người tạo, nội dung hoặc ngày thực hiện.</p>
                  </>
                ) : (
                  <>
                    <span className="permit-empty-icon">▤</span>
                    <h3>{filter === 'tasks' ? 'Bạn đã xử lý hết!' : 'Chưa có phiếu xin phép'}</h3>
                    <p>{filter === 'tasks'
                      ? 'Khi có phiếu cần bạn duyệt hoặc xác nhận, phiếu sẽ xuất hiện ở đây.'
                      : 'Tạo phiếu đầu tiên để bắt đầu quy trình ra vào cổng.'}</p>
                    {filter !== 'tasks' && (
                      <button type="button" onClick={() => setShowForm(true)}>Tạo phiếu mới</button>
                    )}
                  </>
                )}
              </div>
            )}
          </>
        )}
      </section>

      {(showForm || selectedPermit) && (
        <div className="permit-dialog-backdrop" onMouseDown={(event) => {
          if (event.target === event.currentTarget && !saving) closeDialog()
        }}>
          <section
            className={`permit-dialog ${selectedPermit ? 'detail-dialog' : ''}`}
            role="dialog"
            aria-modal="true"
            aria-labelledby="permit-dialog-title"
          >
            <div className="permit-dialog-heading no-print">
              <div>
                <p className="permit-section-eyebrow">
                  {selectedPermit ? `PHIẾU SỐ GP-${String(selectedPermit.id).padStart(5, '0')}` : 'ĐỀ NGHỊ CẤP PHÉP'}
                </p>
                <h2 id="permit-dialog-title">{selectedPermit ? 'Chi tiết phiếu' : 'Tạo phiếu xin phép'}</h2>
              </div>
              <button type="button" className="permit-close" aria-label="Đóng" onClick={closeDialog}>×</button>
            </div>

            {showForm ? (
              <form className="permit-form no-print" onSubmit={createPermit}>
                {error && <p role="alert" className="permit-error">{error}</p>}
                <div className="permit-direction-picker">
                  <label className={form.direction === 'out' ? 'active' : ''}>
                    <input type="radio" name="direction" value="out" checked={form.direction === 'out'} onChange={updateForm} />
                    <span className="direction-choice-icon">↗</span>
                    <span><strong>Ra khỏi công ty</strong><small>Xin phép ra cổng</small></span>
                  </label>
                  <label className={form.direction === 'in' ? 'active' : ''}>
                    <input type="radio" name="direction" value="in" checked={form.direction === 'in'} onChange={updateForm} />
                    <span className="direction-choice-icon">↙</span>
                    <span><strong>Vào công ty</strong><small>Xin phép vào cổng</small></span>
                  </label>
                </div>

                <div className="permit-form-grid">
                  <label>
                    <span>Ngày thực hiện <b>*</b></span>
                    <input type="date" name="date" required value={form.date} onChange={updateForm} />
                  </label>
                  <label>
                    <span>Thời gian dự kiến <b>*</b></span>
                    <input type="time" name="time" required value={form.time} onChange={updateForm} />
                  </label>
                  {form.direction === 'out' && (
                    <label>
                      <span>Thời gian quay lại</span>
                      <input type="time" name="returnTime" value={form.returnTime} onChange={updateForm} />
                    </label>
                  )}
                  <label className={form.direction === 'out' ? '' : 'span-two'}>
                    <span>Nơi đến / nơi xuất phát <b>*</b></span>
                    <input
                      type="text"
                      name="destination"
                      maxLength={160}
                      required
                      placeholder="Ví dụ: Gặp khách hàng tại Quận 1"
                      value={form.destination}
                      onChange={updateForm}
                    />
                  </label>
                  <label className="span-two">
                    <span>Lý do xin phép <b>*</b></span>
                    <textarea
                      name="purpose"
                      rows="3"
                      maxLength={500}
                      required
                      placeholder="Mô tả ngắn gọn mục đích ra vào cổng..."
                      value={form.purpose}
                      onChange={updateForm}
                    />
                  </label>
                  <label className="span-two">
                    <span>Phương tiện / tài sản mang theo <small>(không bắt buộc)</small></span>
                    <input
                      type="text"
                      name="vehicleInfo"
                      maxLength={160}
                      placeholder="Ví dụ: Xe máy 29A1-123.45, laptop"
                      value={form.vehicleInfo}
                      onChange={updateForm}
                    />
                  </label>
                </div>

                <div className="permit-assignees">
                  <div className="permit-assignees-heading">
                    <span className="assignee-number">01</span>
                    <div><strong>Luồng phê duyệt</strong><small>Line người duyệt không giới hạn; HR và bảo vệ luôn là hai bước cuối.</small></div>
                  </div>
                  {!hasEligiblePermitRoute ? (
                    <p className="permit-inline-note">
                      Cần có ít nhất ba người đang hoạt động ngoài người tạo để chọn line duyệt, HR và bảo vệ.
                    </p>
                  ) : (
                    <div className="permit-approval-route">
                      <div className="permit-search-select">
                        <span>Line người duyệt <b>*</b> <small>(không giới hạn)</small></span>
                        <SearchableMultiSelect
                          label="Line người duyệt"
                          selectedValues={form.approverIds}
                          placeholder="Tìm theo tên hoặc mã nhân viên..."
                          options={approvers.filter((user) => user.id !== form.hrId && user.id !== form.guardId).map((user) => ({
                            value: user.id,
                            label: `${user.name}${user.user_code ? ` · ${user.user_code}` : ''}`,
                            searchText: `${user.name} ${user.user_code || ''}`,
                              department: user.departmentName ?? null,
                          }))}
                          onChange={(approverIds) => setForm((current) => ({ ...current, approverIds }))}
                        />
                      </div>
                      <div className="permit-form-grid">
                        <div className="permit-search-select">
                          <span>HR duyệt cuối line <b>*</b></span>
                          <SearchableSelect
                            label="HR duyệt cuối line"
                            required
                            value={form.hrId}
                            placeholder="Tìm HR theo tên hoặc mã nhân viên..."
                            emptyOption="Chọn người duyệt HR"
                            options={hrUsers.filter((user) => user.id !== sessionUser.id && user.id !== form.guardId).map((user) => ({
                              value: user.id,
                              label: `${user.name}${user.user_code ? ` · ${user.user_code}` : ''}`,
                              searchText: `${user.name} ${user.user_code || ''}`,
                              department: user.departmentName ?? null,
                            }))}
                            onChange={(hrId) => setForm((current) => ({
                              ...current,
                              hrId,
                              approverIds: current.approverIds.filter((userId) => userId !== hrId),
                            }))}
                          />
                        </div>
                        <div className="permit-search-select">
                          <span>Bảo vệ xác nhận cuối cùng <b>*</b></span>
                          <SearchableSelect
                            label="Bảo vệ xác nhận cuối cùng"
                            required
                            value={form.guardId}
                            placeholder="Tìm theo tên hoặc mã nhân viên..."
                            emptyOption="Chọn bảo vệ"
                            options={guards.filter((user) => user.id !== sessionUser.id && user.id !== form.hrId).map((user) => ({
                              value: user.id,
                              label: `${user.name}${user.user_code ? ` · ${user.user_code}` : ''}`,
                              searchText: `${user.name} ${user.user_code || ''}`,
                              department: user.departmentName ?? null,
                            }))}
                            onChange={(guardId) => setForm((current) => ({
                              ...current,
                              guardId,
                              approverIds: current.approverIds.filter((userId) => userId !== guardId),
                            }))}
                          />
                        </div>
                      </div>
                    </div>
                  )}
                  {(chosenApprovers.length > 0 || chosenHr || chosenGuard) && (
                    <p className="permit-route-preview">
                      <span>{sessionUser.name}</span>
                      {chosenApprovers.map((approver, index) => (
                        <span className="permit-route-step" key={approver.id}><b>→</b>{index + 1}. {approver.name}</span>
                      ))}
                      {chosenHr && <span className="permit-route-step"><b>→</b>HR: {chosenHr.name}</span>}
                      {chosenGuard && <span className="permit-route-step"><b>→</b>Bảo vệ: {chosenGuard.name}</span>}
                    </p>
                  )}
                </div>

                <div className="permit-form-actions">
                  <button type="button" className="permit-secondary" onClick={closeDialog} disabled={saving}>Hủy</button>
                  <button type="submit" className="permit-submit" disabled={saving || !hasEligiblePermitRoute || !hasValidPermitRoute}>
                    {saving ? 'Đang gửi...' : 'Gửi duyệt'}
                    {!saving && <span aria-hidden="true">→</span>}
                  </button>
                </div>
              </form>
            ) : selectedPermit && (
              <>
                <div className="permit-detail-toolbar no-print">
                  <span className={`permit-status ${statusInfo[selectedPermit.status].tone}`}>
                    <span aria-hidden="true">{statusInfo[selectedPermit.status].icon}</span>
                    {statusInfo[selectedPermit.status].label}
                  </span>
                  <button type="button" className="permit-print-button" onClick={() => window.print()}>
                    <span aria-hidden="true">▣</span> In phiếu
                  </button>
                </div>
                <article className="permit-print">
                  <header className="permit-paper-header">
                    <div className="permit-paper-brand">
                      <span className="permit-paper-logo">W</span>
                      <span>WORKDESK<small>QUẢN LÝ NỘI BỘ</small></span>
                    </div>
                    <span className="permit-paper-number">SỐ PHIẾU<br /><strong>GP-{String(selectedPermit.id).padStart(5, '0')}</strong></span>
                  </header>
                  <div className="permit-paper-title">
                    <p>GIẤY ĐỀ NGHỊ</p>
                    <h3>{selectedPermit.direction === 'out' ? 'RA KHỎI CỔNG CÔNG TY' : 'VÀO CỔNG CÔNG TY'}</h3>
                    <span>Ngày {formatDate(selectedPermit.date, locale)}</span>
                  </div>
                  <div className="permit-paper-body">
                    <p><span>Người lập phiếu</span><strong>{selectedPermit.requesterName}</strong></p>
                    <p><span>Thời gian dự kiến</span><strong>{selectedPermit.time}{selectedPermit.returnTime ? ` — ${selectedPermit.returnTime}` : ''}</strong></p>
                    <p><span>Nơi đến / xuất phát</span><strong>{selectedPermit.destination}</strong></p>
                    <p className="full-row"><span>Lý do xin phép</span><strong>{selectedPermit.purpose}</strong></p>
                    <p className="full-row"><span>Phương tiện / tài sản</span><strong>{selectedPermit.vehicleInfo || 'Không có'}</strong></p>
                  </div>
                  <div className="permit-paper-route">
                    <section>
                      <h4>NGƯỜI LẬP</h4>
                      <strong>{selectedPermit.requesterName}</strong>
                      <span className="permit-sign-space">Ký và ghi rõ họ tên</span>
                    </section>
                    {selectedPermit.approvalSteps.map((step) => (
                      <section key={`${step.userId}-${step.sequence}`}>
                        <h4>{step.isHr ? 'HR DUYỆT CUỐI LINE' : 'NGƯỜI DUYỆT'}</h4>
                        <strong>{step.name}</strong>
                        {step.decidedAt
                          ? <span className="permit-paper-mark">
                            {step.status === 'rejected' ? 'TỪ CHỐI' : 'ĐÃ DUYỆT'} · {formatTimestamp(step.decidedAt, locale)}
                          </span>
                          : <span className="permit-sign-space">Ký và ghi rõ họ tên</span>}
                        {step.note && <small>Ghi chú: {step.note}</small>}
                      </section>
                    ))}
                    <section>
                      <h4>BẢO VỆ CỔNG</h4>
                      <strong>{selectedPermit.guardName}</strong>
                      {selectedPermit.guardConfirmedAt
                        ? <span className="permit-paper-mark">{selectedPermit.status === 'approved' ? 'ĐÃ XÁC NHẬN' : 'TỪ CHỐI'} · {formatTimestamp(selectedPermit.guardConfirmedAt, locale)}</span>
                        : <span className="permit-sign-space">Xác nhận tại cổng</span>}
                      {selectedPermit.guardNote && <small>Ghi chú: {selectedPermit.guardNote}</small>}
                    </section>
                  </div>
                  <footer className="permit-paper-footer">
                    <span>Phiếu được tạo lúc {formatTimestamp(selectedPermit.createdAt, locale)}</span>
                    <strong>{statusInfo[selectedPermit.status].label.toUpperCase()}</strong>
                  </footer>
                </article>

                {selectedPermit.currentApproverId === sessionUser.id && selectedPermit.status === 'pending_approval' && (
                  <div className="permit-action-panel no-print">
                    <h3>{selectedPermit.currentApproverIsHr ? 'Ý kiến HR duyệt cuối line' : 'Ý kiến người duyệt'}</h3>
                    <textarea
                      value={decisionNote}
                      onChange={(event) => setDecisionNote(event.target.value)}
                      maxLength={500}
                      rows="2"
                      placeholder="Ghi chú hoặc điều kiện phê duyệt (không bắt buộc)..."
                    />
                    <div>
                      <button type="button" className="permit-reject-button" disabled={saving || !decisionNote.trim()} onClick={() => submitDecision('approver', 'reject')}>Từ chối</button>
                      <button type="button" className="permit-approve-button" disabled={saving} onClick={() => submitDecision('approver', 'approve')}>Duyệt phiếu <span>→</span></button>
                    </div>
                  </div>
                )}
                {selectedPermit.guardId === sessionUser.id && selectedPermit.status === 'pending_guard' && (
                  <div className="permit-action-panel no-print">
                    <h3>Xác nhận tại cổng</h3>
                    <textarea
                      value={decisionNote}
                      onChange={(event) => setDecisionNote(event.target.value)}
                      maxLength={500}
                      rows="2"
                      placeholder="Ghi nhận tại cổng (không bắt buộc)..."
                    />
                    <div>
                      <button type="button" className="permit-reject-button" disabled={saving || !decisionNote.trim()} onClick={() => submitDecision('guard', 'reject')}>Từ chối</button>
                      <button type="button" className="permit-approve-button" disabled={saving} onClick={() => submitDecision('guard', 'confirm')}>Xác nhận bảo vệ <span>✓</span></button>
                    </div>
                  </div>
                )}
                {error && <p role="alert" className="permit-error no-print">{error}</p>}
              </>
            )}
          </section>
        </div>
      )}
    </main>
    </LocalizedTree>
  )
}

export default PermitRequests
