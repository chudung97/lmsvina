import { useEffect, useState } from 'react'
import { useRealtimeRefresh } from '../../realtime'
import { LocalizedTree } from '../../i18n'
import { SearchPagination, SearchableSelect } from '../../components/SearchableControls'
import { useSearchPagination } from '../../hooks/useSearchPagination'

const organizationLevels = [
  ['ceo', 'Tổng giám đốc'],
  ['division_head', 'Trưởng bộ phận'],
  ['department_head', 'Trưởng phòng'],
  ['team_lead', 'Team lead'],
  ['employee', 'Nhân viên / công nhân'],
]

const businessPermissions = [
  ['meeting_admin', 'Quản lý phòng họp'],
  ['pdf_signer', 'Ký tài liệu PDF'],
  ['meal_hr', 'HR nhập suất ăn Hàn'],
  ['overtime_hr', 'HR chốt công tăng ca'],
]

const adminOnlyPermissions = ['meal_hr', 'overtime_hr']

const levelRanks = { employee: 0, team_lead: 1, department_head: 2, division_head: 3, ceo: 4 }

async function requestApi(url, options) {
  const response = await fetch(url, options)
  const result = await response.json()
  if (!response.ok) throw new Error(result.error || 'Không thể kết nối máy chủ.')
  return result
}

function AdminUsers({ currentUser }) {
  const refreshCounter = useRealtimeRefresh('accounts')
  const [users, setUsers] = useState([])
  const [organization, setOrganization] = useState({ divisions: [], departments: [], teams: [] })
  const [form, setForm] = useState({ name: '', email: '', password: '' })
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [saving, setSaving] = useState(false)
  const [editingTarget, setEditingTarget] = useState(null)
  const [editForm, setEditForm] = useState({ name: '', email: '' })
  const [resetTarget, setResetTarget] = useState(null)
  const [resetPassword, setResetPassword] = useState('')
  const [accessTarget, setAccessTarget] = useState(null)
  const [accessForm, setAccessForm] = useState(null)
  const canCreateUsers = currentUser.role === 'admin' || currentUser.orgLevel === 'ceo'
  const actorRank = currentUser.role === 'admin' ? 5 : (levelRanks[currentUser.orgLevel] ?? 0)
  const assignableLevels = organizationLevels.filter(([level]) => actorRank > levelRanks[level])
  const userList = useSearchPagination(
    users,
    (user) => `${user.name} ${user.email || ''} ${user.user_code || ''}`,
  )

  useEffect(() => {
    let cancelled = false
    Promise.all([
      requestApi('/api/admin/users'),
      requestApi('/api/organization'),
    ])
      .then(([items, units]) => {
        if (!cancelled) {
          setUsers(items)
          setOrganization(units)
        }
      })
      .catch((loadError) => {
        if (!cancelled) setError(loadError.message)
      })
    return () => {
      cancelled = true
    }
  }, [refreshCounter])

  const submit = async (event) => {
    event.preventDefault()
    setError('')
    setNotice('')
    setSaving(true)
    try {
      const user = await requestApi('/api/admin/users', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(form),
      })
      setUsers((current) => [...current, user].sort((left, right) => left.name.localeCompare(right.name)))
      setForm({ name: '', email: '', password: '' })
      setNotice(`Đã tạo tài khoản ${user.email}. Hãy gửi thông tin đăng nhập cho người dùng qua kênh riêng.`)
    } catch (saveError) {
      setError(saveError.message)
    } finally {
      setSaving(false)
    }
  }

  const resetUserPassword = async (event, user) => {
    event.preventDefault()
    setError('')
    setNotice('')
    try {
      await requestApi(`/api/admin/users/${encodeURIComponent(user.id)}/password`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ password: resetPassword }),
      })
      setResetPassword('')
      setResetTarget(null)
      setNotice(`Đã đặt lại mật khẩu cho ${user.email}. Phiên đăng nhập hiện có của tài khoản đã bị thu hồi.`)
    } catch (resetError) {
      setError(resetError.message)
    }
  }

  const editUserProfile = async (event, user) => {
    event.preventDefault()
    setError('')
    setNotice('')
    try {
      const updated = await requestApi(`/api/admin/users/${encodeURIComponent(user.id)}/profile`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(editForm),
      })
      setUsers((current) => current
        .map((item) => item.id === user.id ? { ...item, ...updated } : item)
        .sort((left, right) => left.name.localeCompare(right.name)))
      setEditingTarget(null)
      setNotice(`Đã cập nhật hồ sơ của ${updated.name}.`)
    } catch (editError) {
      setError(editError.message)
    }
  }

  const editUserAccess = async (event, user) => {
    event.preventDefault()
    setError('')
    setNotice('')
    try {
      await requestApi(`/api/admin/users/${encodeURIComponent(user.id)}/access`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          orgLevel: accessForm.orgLevel,
          divisionId: accessForm.orgLevel === 'division_head' ? accessForm.divisionId || null : null,
          departmentId: accessForm.orgLevel === 'division_head' ? null : accessForm.departmentId || null,
          teamId: accessForm.teamId || null,
          permissions: accessForm.permissions,
        }),
      })
      const updatedUsers = await requestApi('/api/admin/users')
      setUsers(updatedUsers)
      setAccessTarget(null)
      setAccessForm(null)
      setNotice(`Đã cập nhật cấp bậc và quyền cho ${user.name}.`)
    } catch (accessError) {
      setError(accessError.message)
    }
  }

  const openAccessEditor = (user) => {
    setAccessTarget(accessTarget === user.id ? null : user.id)
    setAccessForm({
      orgLevel: user.orgLevel || 'employee',
      divisionId: user.divisionId || '',
      departmentId: user.departmentId || '',
      teamId: user.teamId || '',
      permissions: user.permissions || [],
    })
  }

  return (
    <LocalizedTree>
    <main className="account-admin-page">
      <header>
        <p className="meetings-eyebrow">Quản trị</p>
        <h1>Tài khoản người dùng</h1>
        <p>Thêm email đăng nhập cho nhân sự LMS hoặc tạo tài khoản nội bộ mới.</p>
      </header>
      {error && <p className="meeting-error" role="alert">{error}</p>}
      {notice && <p className="account-notice" role="status">{notice}</p>}
      <div className="account-admin-layout">
        {canCreateUsers && <form className="account-form" onSubmit={submit}>
          <h2>Tạo tài khoản nội bộ</h2>
          <p>Nhân sự LMS đã có sẵn trong danh sách; dùng phần hồ sơ để cấp email và mật khẩu đăng nhập.</p>
          <label>
            <span>Họ tên</span>
            <input
              required
              maxLength={120}
              value={form.name}
              onChange={(event) => setForm({ ...form, name: event.target.value })}
            />
          </label>
          <label>
            <span>Email đăng nhập</span>
            <input
              required
              type="email"
              maxLength={254}
              autoComplete="off"
              value={form.email}
              onChange={(event) => setForm({ ...form, email: event.target.value })}
            />
          </label>
          <label>
            <span>Mật khẩu tạm thời</span>
            <input
              required
              type="password"
              minLength={6}
              autoComplete="new-password"
              value={form.password}
              onChange={(event) => setForm({ ...form, password: event.target.value })}
            />
          </label>
          <button type="submit" disabled={saving}>{saving ? 'Đang tạo...' : 'Tạo tài khoản'}</button>
        </form>}
        <section className="account-list">
          <h2>Danh sách tài khoản</h2>
          {users.length > 0 && <SearchPagination list={userList} />}
          {userList.pageItems.length ? (
            <ul>
              {userList.pageItems.map((user) => (
                <li key={user.id}>
                  <div>
                    <strong>{user.name}</strong>
                    <span>{user.email || 'Chưa có email đăng nhập'}</span>
                    {user.user_code && <small>Mã nhân viên: {user.user_code}</small>}
                  </div>
                  <div className="account-row-actions">
                    <small>{user.role === 'admin'
                      ? 'Admin hệ thống'
                      : organizationLevels.find(([level]) => level === user.orgLevel)?.[1] || 'Nhân viên / công nhân'}</small>
                    {user.id !== currentUser.id && (
                      <button
                        type="button"
                        onClick={() => {
                          setEditingTarget(editingTarget === user.id ? null : user.id)
                          setEditForm({ name: user.name, email: user.email || '' })
                        }}
                      >
                        Sửa tên/email
                      </button>
                    )}
                    {user.id !== currentUser.id && user.active && (
                      <button
                        type="button"
                        onClick={() => {
                          setResetTarget(resetTarget === user.id ? null : user.id)
                          setResetPassword('')
                        }}
                      >
                        Đặt lại mật khẩu
                      </button>
                    )}
                    {user.id !== currentUser.id && user.role !== 'admin' && assignableLevels.length > 0 && (
                      <button type="button" onClick={() => openAccessEditor(user)}>
                        {accessTarget === user.id ? 'Đóng phân quyền' : 'Cấp bậc & quyền'}
                      </button>
                    )}
                  </div>
                  {editingTarget === user.id && (
                    <form className="account-reset-form" onSubmit={(event) => editUserProfile(event, user)}>
                      <input
                        required
                        maxLength={120}
                        aria-label={`Họ tên của ${user.name}`}
                        placeholder="Họ tên"
                        value={editForm.name}
                        onChange={(event) => setEditForm({ ...editForm, name: event.target.value })}
                      />
                      <input
                        type="email"
                        maxLength={254}
                        aria-label={`Email của ${user.name}`}
                        placeholder="Email đăng nhập (để trống nếu chưa có)"
                        value={editForm.email}
                        onChange={(event) => setEditForm({ ...editForm, email: event.target.value })}
                      />
                      <button type="submit">Lưu thông tin</button>
                    </form>
                  )}
                  {accessTarget === user.id && accessForm && (
                    <form className="account-reset-form access-editor" onSubmit={(event) => editUserAccess(event, user)}>
                      <div className="account-search-select">
                        <span>Cấp tổ chức</span>
                        <SearchableSelect
                          label="Cấp tổ chức"
                          value={accessForm.orgLevel}
                          options={assignableLevels.map(([level, label]) => ({ value: level, label }))}
                          onChange={(value) => setAccessForm({
                            ...accessForm,
                            orgLevel: value,
                            divisionId: '',
                            departmentId: '',
                            teamId: '',
                          })}
                        />
                      </div>
                      {accessForm.orgLevel === 'division_head' && (
                        <div className="account-search-select">
                          <span>Bộ phận</span>
                          <SearchableSelect
                            label="Bộ phận"
                            required
                            value={accessForm.divisionId}
                            placeholder="Tìm bộ phận theo tên..."
                            emptyOption="Chọn bộ phận"
                            options={organization.divisions.map((division) => ({
                              value: division.id,
                              label: division.name,
                              searchText: division.name,
                            }))}
                            onChange={(value) => setAccessForm({ ...accessForm, divisionId: value })}
                          />
                        </div>
                      )}
                      {['department_head', 'team_lead', 'employee'].includes(accessForm.orgLevel) && (
                        <div className="account-search-select">
                          <span>Phòng ban</span>
                          <SearchableSelect
                            label="Phòng ban"
                            value={accessForm.departmentId}
                            required={accessForm.orgLevel !== 'employee'}
                            placeholder="Tìm phòng ban theo tên..."
                            emptyOption="Chưa gán phòng ban"
                            options={organization.departments.map((department) => ({
                              value: department.id,
                              label: department.name,
                              searchText: `${department.name} ${department.divisionName || ''}`,
                            }))}
                            onChange={(value) => setAccessForm({
                              ...accessForm,
                              departmentId: value,
                              teamId: '',
                            })}
                          />
                        </div>
                      )}
                      {['team_lead', 'employee'].includes(accessForm.orgLevel) && (
                        <div className="account-search-select">
                          <span>Team</span>
                          <SearchableSelect
                            label="Team"
                            value={accessForm.teamId}
                            required={accessForm.orgLevel === 'team_lead'}
                            placeholder="Tìm team theo tên..."
                            emptyOption="Chưa gán team"
                            options={organization.teams
                              .filter((team) => !accessForm.departmentId || String(team.departmentId) === String(accessForm.departmentId))
                              .map((team) => ({
                                value: team.id,
                                label: team.name,
                                searchText: team.name,
                              }))}
                            onChange={(value) => setAccessForm({ ...accessForm, teamId: value })}
                          />
                        </div>
                      )}
                      <fieldset>
                        <legend>Quyền nghiệp vụ</legend>
                        {businessPermissions
                          .filter(([permission]) => !adminOnlyPermissions.includes(permission) || currentUser.role === 'admin')
                          .map(([permission, label]) => (
                          <label key={permission}>
                            <input
                              type="checkbox"
                              checked={accessForm.permissions.includes(permission)}
                              onChange={(event) => setAccessForm({
                                ...accessForm,
                                permissions: event.target.checked
                                  ? [...accessForm.permissions, permission]
                                  : accessForm.permissions.filter((value) => value !== permission),
                              })}
                            />
                            <span>{label}</span>
                          </label>
                        ))}
                      </fieldset>
                      <button type="submit">Lưu cấp bậc &amp; quyền</button>
                    </form>
                  )}
                  {resetTarget === user.id && (
                    <form className="account-reset-form" onSubmit={(event) => resetUserPassword(event, user)}>
                      <input
                        type="password"
                        minLength={6}
                        autoComplete="new-password"
                        required
                        aria-label={`Mật khẩu mới cho ${user.name}`}
                        placeholder="Mật khẩu mới (tối thiểu 6 ký tự)"
                        value={resetPassword}
                        onChange={(event) => setResetPassword(event.target.value)}
                      />
                      <button type="submit">Lưu mật khẩu</button>
                    </form>
                  )}
                </li>
              ))}
            </ul>
          ) : users.length === 0
            ? <p>Chưa có tài khoản.</p>
            : <p>Không tìm thấy tài khoản phù hợp.</p>}
        </section>
      </div>
    </main>
    </LocalizedTree>
  )
}

export default AdminUsers
