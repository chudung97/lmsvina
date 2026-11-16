import { useCallback, useEffect, useState } from 'react'
import { useRealtimeRefresh } from '../../realtime'
import { LocalizedTree } from '../../i18n'
import { useLocale } from '../../locale-context'
import {
  SearchPagination,
  SearchableMultiSelect,
  SearchableSelect,
} from '../../components/SearchableControls'
import { useSearchPagination } from '../../hooks/useSearchPagination'

async function requestApi(url, options) {
  const response = await fetch(url, options)
  const result = await response.json()
  if (!response.ok) throw new Error(result.error || 'Không thể kết nối máy chủ.')
  return result
}

function Departments({ currentUser }) {
  const { language } = useLocale()
  const refreshCounter = useRealtimeRefresh('accounts')
  const [departments, setDepartments] = useState([])
  const [users, setUsers] = useState([])
  const [organization, setOrganization] = useState({ divisions: [], departments: [], teams: [] })
  const [form, setForm] = useState({ name: '', managerUserId: '', divisionId: '' })
  const [divisionForm, setDivisionForm] = useState({ name: '', managerUserId: '', divisionManagerUserId: '' })
  const [teamForm, setTeamForm] = useState({ departmentId: '', name: '', memberUserIds: [] })
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [saving, setSaving] = useState(false)
  const [editingId, setEditingId] = useState(null)
  const [editForm, setEditForm] = useState({ name: '', managerUserId: '', divisionId: '' })
  const [editingDivisionId, setEditingDivisionId] = useState(null)
  const [divisionEditForm, setDivisionEditForm] = useState({ name: '', managerUserId: '', divisionManagerUserId: '' })
  const [divisionManagerForm, setDivisionManagerForm] = useState({ userId: '', divisionIds: [] })
  const [editingDivisionManagerId, setEditingDivisionManagerId] = useState(null)
  const [divisionManagerEditIds, setDivisionManagerEditIds] = useState([])
  const [editingTeamId, setEditingTeamId] = useState(null)
  const [teamEditForm, setTeamEditForm] = useState({ name: '', memberUserIds: [] })
  const isOrganizationAdmin = currentUser.role === 'admin' || currentUser.orgLevel === 'ceo'
  // Quản lý bộ phận (có thể quản lý nhiều bộ phận) đứng trên Trưởng bộ phận. Cả hai quản lý phòng ban/team
  // trong bộ phận; Quản lý bộ phận được đổi Trưởng bộ phận, chỉ admin/Tổng giám đốc gán Quản lý bộ phận.
  const isDivisionLevel = ['division_head', 'division_manager'].includes(currentUser.orgLevel)
  const canEditDivisions = isOrganizationAdmin || isDivisionLevel
  const canChangeDivisionHead = isOrganizationAdmin || currentUser.orgLevel === 'division_manager'
  const canCreateDepartments = isOrganizationAdmin || isDivisionLevel
  const canCreateTeams = canCreateDepartments || currentUser.orgLevel === 'department_head'
  const managerCandidates = (divisionId, currentManagerId = null) => users.filter((user) => (
    currentManagerId !== null && currentManagerId !== undefined
      && String(user.id) === String(currentManagerId)
      || (user.role !== 'admin'
        && user.orgLevel === 'employee'
        && (!divisionId || !user.divisionId || String(user.divisionId) === String(divisionId)))
  ))
  // Quản lý bộ phận: nhân viên chưa quản lý đơn vị nào, hoặc người đang là Quản lý bộ phận khác.
  const divisionManagerCandidates = (excludedId, currentManagerId = null) => users.filter((user) => (
    String(user.id) !== String(excludedId || '')
      && (currentManagerId && String(user.id) === String(currentManagerId)
        || user.role !== 'admin' && user.active && ['employee', 'division_manager'].includes(user.orgLevel))
  ))
  const toUserOption = (user) => ({
    value: user.id,
    label: `${user.name}${user.user_code ? ` · ${user.user_code}` : ''}`,
    searchText: `${user.name} ${user.user_code || ''}`,
    department: user.departmentName ?? null,
  })
  const teamMemberOptions = (departmentId, teamId = null) => users
    .filter((user) => user.active
      && (!user.departmentId || String(user.departmentId) === String(departmentId))
      && user.role !== 'admin'
      && user.orgLevel === 'employee'
      && (!user.teamId || String(user.teamId) === String(teamId)))
    .map((user) => ({
      value: String(user.id),
      label: `${user.name}${user.user_code ? ` · ${user.user_code}` : ''}`,
      searchText: `${user.name} ${user.user_code || ''}`,
      department: user.departmentName ?? null,
    }))
  // Cây Quản lý bộ phận: gom các bộ phận theo người quản lý (một người có thể quản lý nhiều bộ phận).
  const divisionManagers = Object.values(organization.divisions.reduce((groups, division) => {
    if (!division.divisionManagerUserId) return groups
    const key = String(division.divisionManagerUserId)
    const user = users.find((candidate) => String(candidate.id) === key)
    groups[key] ??= {
      userId: key,
      name: division.divisionManagerName || user?.name || key,
      userCode: user?.user_code || '',
      divisions: [],
    }
    groups[key].divisions.push(division)
    return groups
  }, {})).sort((left, right) => left.name.localeCompare(right.name))
  const divisionOptions = (userId) => organization.divisions
    .filter((division) => !division.divisionManagerUserId || String(division.divisionManagerUserId) === String(userId || ''))
    .map((division) => ({
      value: division.id,
      label: division.name,
      searchText: `${division.name} ${division.managerName || ''}`,
    }))
  const divisionManagerList = useSearchPagination(
    divisionManagers,
    (manager) => `${manager.name} ${manager.userCode} ${manager.divisions.map((division) => division.name).join(' ')}`,
  )
  const divisionList = useSearchPagination(
    organization.divisions,
    (division) => `${division.name} ${division.managerName || ''} ${division.divisionManagerName || ''}`,
  )
  const departmentList = useSearchPagination(
    departments,
    (department) => `${department.name} ${department.divisionName || ''} ${department.managerName || ''}`,
  )
  const teamList = useSearchPagination(
    organization.teams,
    (team) => `${team.name} ${team.managerName || ''} ${departments.find((department) => String(department.id) === String(team.departmentId))?.name || ''}`,
  )

  const fetchData = useCallback(() => Promise.all([
      requestApi('/api/departments'),
      requestApi('/api/admin/users'),
      requestApi('/api/organization'),
    ]), [])

  const load = useCallback(async () => {
    const [deps, us, units] = await fetchData()
    setDepartments(deps)
    setUsers(us)
    setOrganization(units)
  }, [fetchData])

  useEffect(() => {
    let cancelled = false
    fetchData()
      .then(([deps, us, units]) => {
        if (cancelled) return
        setDepartments(deps)
        setUsers(us)
        setOrganization(units)
      })
      .catch((e) => { if (!cancelled) setError(e.message) })
    return () => { cancelled = true }
  }, [fetchData, refreshCounter])

  const submit = async (event) => {
    event.preventDefault()
    setError(''); setNotice(''); setSaving(true)
    try {
      const body = { name: form.name, managerUserId: form.managerUserId || null, divisionId: form.divisionId || null }
      await requestApi('/api/departments', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      setForm({ name: '', managerUserId: '', divisionId: '' })
      setNotice('Đã tạo phòng ban.')
      await load()
    } catch (e) { setError(e.message) } finally { setSaving(false) }
  }

  const update = async (event, id) => {
    event.preventDefault()
    setError(''); setNotice('')
    try {
      await requestApi(`/api/departments/${encodeURIComponent(id)}`, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ name: editForm.name, managerUserId: editForm.managerUserId || null, divisionId: editForm.divisionId || null }) })
      setEditingId(null)
      setNotice('Đã cập nhật phòng ban.')
      await load()
    } catch (e) { setError(e.message) }
  }

  const remove = async (id) => {
    const message = language === 'en'
      ? 'Delete this department?'
      : language === 'ko' ? '이 부서를 삭제하시겠습니까?' : 'Xóa phòng ban này?'
    if (!confirm(message)) return
    setError(''); setNotice('')
    try {
      await requestApi(`/api/departments/${encodeURIComponent(id)}`, { method: 'DELETE' })
      setNotice('Đã xóa phòng ban.')
      await load()
    } catch (e) { setError(e.message) }
  }

  const createDivision = async (event) => {
    event.preventDefault()
    setError(''); setNotice(''); setSaving(true)
    try {
      await requestApi('/api/organization/divisions', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(divisionForm),
      })
      setDivisionForm({ name: '', managerUserId: '', divisionManagerUserId: '' })
      setNotice('Đã tạo bộ phận.')
      await load()
    } catch (e) { setError(e.message) } finally { setSaving(false) }
  }

  const updateDivision = async (event, id) => {
    event.preventDefault()
    setError(''); setNotice(''); setSaving(true)
    try {
      await requestApi(`/api/organization/divisions/${encodeURIComponent(id)}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(divisionEditForm),
      })
      setEditingDivisionId(null)
      setNotice('Đã cập nhật bộ phận.')
      await load()
    } catch (e) { setError(e.message) } finally { setSaving(false) }
  }

  const removeDivision = async (division) => {
    const message = language === 'en'
      ? `Delete division "${division.name}"?`
      : language === 'ko' ? `"${division.name}" 부서를 삭제하시겠습니까?` : `Xóa bộ phận "${division.name}"?`
    if (!confirm(message)) return
    setError(''); setNotice('')
    try {
      await requestApi(`/api/organization/divisions/${encodeURIComponent(division.id)}`, { method: 'DELETE' })
      setNotice('Đã xóa bộ phận.')
      await load()
    } catch (e) { setError(e.message) }
  }

  const saveDivisionManager = async (userId, divisionIds) => {
    await requestApi(`/api/organization/division-managers/${encodeURIComponent(userId)}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ divisionIds }),
    })
  }

  const createDivisionManager = async (event) => {
    event.preventDefault()
    setError(''); setNotice(''); setSaving(true)
    try {
      const existing = divisionManagers.find((manager) => manager.userId === String(divisionManagerForm.userId))
      const divisionIds = [...new Set([...(existing?.divisions.map((division) => division.id) || []), ...divisionManagerForm.divisionIds])]
      await saveDivisionManager(divisionManagerForm.userId, divisionIds)
      setDivisionManagerForm({ userId: '', divisionIds: [] })
      setNotice('Đã gán Quản lý bộ phận.')
      await load()
    } catch (e) { setError(e.message) } finally { setSaving(false) }
  }

  const updateDivisionManager = async (event, manager) => {
    event.preventDefault()
    setError(''); setNotice(''); setSaving(true)
    try {
      await saveDivisionManager(manager.userId, divisionManagerEditIds)
      setEditingDivisionManagerId(null)
      setNotice('Đã cập nhật Quản lý bộ phận.')
      await load()
    } catch (e) { setError(e.message) } finally { setSaving(false) }
  }

  const removeDivisionManager = async (manager) => {
    const message = language === 'en'
      ? `Remove division manager "${manager.name}" from all divisions?`
      : language === 'ko' ? `"${manager.name}" 본부 관리자를 모든 본부에서 해제하시겠습니까?` : `Gỡ Quản lý bộ phận "${manager.name}" khỏi tất cả bộ phận?`
    if (!confirm(message)) return
    setError(''); setNotice('')
    try {
      await saveDivisionManager(manager.userId, [])
      setNotice('Đã gỡ Quản lý bộ phận.')
      await load()
    } catch (e) { setError(e.message) }
  }

  const saveTeamMembers = async (event) => {
    event.preventDefault()
    setError(''); setNotice(''); setSaving(true)
    try {
      await requestApi('/api/organization/teams', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(teamForm) })
      setTeamForm({ departmentId: '', name: '', memberUserIds: [] })
      setNotice('Đã tạo team.')
      await load()
    } catch (e) { setError(e.message) } finally { setSaving(false) }
  }

  const updateTeam = async (event, id) => {
    event.preventDefault()
    setError(''); setNotice(''); setSaving(true)
    try {
      await requestApi(`/api/organization/teams/${encodeURIComponent(id)}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(teamEditForm),
      })
      setEditingTeamId(null)
      setNotice('Đã cập nhật team.')
      await load()
    } catch (e) { setError(e.message) } finally { setSaving(false) }
  }

  const removeTeam = async (team) => {
    const message = language === 'en'
      ? `Delete team "${team.name}"?`
      : language === 'ko' ? `"${team.name}" 팀을 삭제하시겠습니까?` : `Xóa team "${team.name}"?`
    if (!confirm(message)) return
    setError(''); setNotice('')
    try {
      await requestApi(`/api/organization/teams/${encodeURIComponent(team.id)}`, { method: 'DELETE' })
      setNotice('Đã xóa team.')
      await load()
    } catch (e) { setError(e.message) }
  }

  return (
      <LocalizedTree>
        <main className="account-admin-page organization-page">
          <header>
            <p className="meetings-eyebrow">CƠ CẤU TỔ CHỨC</p>
            <h1>Cơ cấu tổ chức</h1>
            <p>Quản lý bộ phận (một người có thể quản lý nhiều bộ phận) → bộ phận (Trưởng bộ phận) → phòng ban → team.</p>
          </header>
          {error && <p className="meeting-error" role="alert">{error}</p>}
          {notice && <p className="account-notice" role="status">{notice}</p>}

          <section className="organization-summary" aria-label="Tổng quan cơ cấu tổ chức">
            <article><span>Quản lý bộ phận</span><strong>{divisionManagers.length}</strong></article>
            <article><span>Bộ phận</span><strong>{organization.divisions.length}</strong></article>
            <article><span>Phòng ban</span><strong>{departments.length}</strong></article>
            <article><span>Team</span><strong>{organization.teams.length}</strong></article>
          </section>

          <section className="organization-section">
            <div className="organization-section-heading">
              <div><p className="meetings-eyebrow">THIẾT LẬP</p><h2>Thêm đơn vị</h2></div>
              <span>Thứ tự cấp: Quản lý bộ phận (một hoặc nhiều bộ phận) → bộ phận → phòng ban → team.</span>
            </div>
            <div className="organization-create-grid">
              {isOrganizationAdmin && (
                <form className="organization-panel organization-form" onSubmit={createDivisionManager}>
                  <span className="organization-step">01 · QUẢN LÝ BỘ PHẬN</span>
                  <h3>Gán Quản lý bộ phận</h3>
                  <div className="organization-search-select"><span>Quản lý bộ phận</span>
                    <SearchableSelect
                      label="Quản lý bộ phận"
                      required
                      value={divisionManagerForm.userId}
                      placeholder="Tìm theo tên hoặc mã nhân viên..."
                      emptyOption="-- Chọn người --"
                      options={divisionManagerCandidates(null).map(toUserOption)}
                      onChange={(value) => setDivisionManagerForm({ userId: value, divisionIds: [] })}
                    />
                  </div>
                  {divisionManagerForm.userId && (
                    <div className="organization-search-select">
                      <span>Các bộ phận quản lý</span>
                      <SearchableMultiSelect
                        label="Các bộ phận quản lý"
                        placeholder="Tìm bộ phận theo tên..."
                        options={divisionOptions(divisionManagerForm.userId)}
                        selectedValues={divisionManagerForm.divisionIds}
                        onChange={(divisionIds) => setDivisionManagerForm({ ...divisionManagerForm, divisionIds })}
                      />
                    </div>
                  )}
                  <button type="submit" disabled={saving || !divisionManagerForm.userId || !divisionManagerForm.divisionIds.length}>
                    <span aria-hidden="true">＋</span> <span>Gán Quản lý bộ phận</span>
                  </button>
                </form>
              )}
              {(isOrganizationAdmin || isDivisionLevel) && (
                <section className="organization-panel">
                  {isOrganizationAdmin ? (
                    <form className="organization-form" onSubmit={createDivision}>
                      <span className="organization-step">02 · BỘ PHẬN</span>
                      <h3>Tạo bộ phận</h3>
                      <label><span>Tên bộ phận</span>
                        <input required maxLength={120} value={divisionForm.name} onChange={(event) => setDivisionForm({ ...divisionForm, name: event.target.value })} />
                      </label>
                      <div className="organization-search-select"><span>Trưởng bộ phận</span>
                        <SearchableSelect
                          label="Trưởng bộ phận"
                          value={divisionForm.managerUserId}
                          placeholder="Tìm theo tên hoặc mã nhân viên..."
                          emptyOption="-- Chưa gán --"
                          options={managerCandidates(null).map((user) => ({
                            value: user.id,
                            label: `${user.name}${user.user_code ? ` · ${user.user_code}` : ''}`,
                            searchText: `${user.name} ${user.user_code || ''}`,
      department: user.departmentName ?? null,
                          }))}
                          onChange={(value) => setDivisionForm({ ...divisionForm, managerUserId: value })}
                        />
                      </div>
                      <div className="organization-search-select"><span>Quản lý bộ phận</span>
                        <SearchableSelect
                          label="Quản lý bộ phận"
                          value={divisionForm.divisionManagerUserId}
                          placeholder="Tìm theo tên hoặc mã nhân viên..."
                          emptyOption="-- Chưa gán --"
                          options={divisionManagerCandidates(divisionForm.managerUserId).map(toUserOption)}
                          onChange={(value) => setDivisionForm({ ...divisionForm, divisionManagerUserId: value })}
                        />
                      </div>
                      <button type="submit" disabled={saving}><span aria-hidden="true">＋</span> <span>Tạo bộ phận</span></button>
                    </form>
                  ) : (
                    <div className="organization-scope-note">{currentUser.orgLevel === 'division_manager'
                      ? 'Bạn là Quản lý bộ phận: quản lý các bộ phận được phân công, đổi được Trưởng bộ phận, tạo và sửa phòng ban, team.'
                      : 'Bạn đang quản lý bộ phận được phân công.'}</div>
                  )}
                </section>
              )}
              {canCreateDepartments && (
                <form className="organization-panel organization-form" onSubmit={submit}>
                  <span className="organization-step">03 · PHÒNG BAN</span>
                  <h3>Tạo phòng ban</h3>
                  <label><span>Tên phòng ban</span>
                    <input required maxLength={120} value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} />
                  </label>
                  {organization.divisions.length > 0 && (
                    <div className="organization-search-select"><span>Bộ phận</span>
                      <SearchableSelect
                        label="Bộ phận"
                        required={isDivisionLevel}
                        value={form.divisionId}
                        placeholder="Tìm bộ phận theo tên..."
                        emptyOption="-- Chưa gán bộ phận --"
                        options={organization.divisions.map((division) => ({
                          value: division.id,
                          label: division.name,
                          searchText: division.name,
                        }))}
                        onChange={(value) => setForm({ ...form, divisionId: value, managerUserId: '' })}
                      />
                    </div>
                  )}
                  <div className="organization-search-select"><span>Trưởng phòng</span>
                    <SearchableSelect
                      label="Trưởng phòng"
                      value={form.managerUserId}
                      placeholder="Tìm theo tên hoặc mã nhân viên..."
                      emptyOption="-- Chưa gán --"
                      options={managerCandidates(form.divisionId).map((user) => ({
                        value: user.id,
                        label: `${user.name}${user.user_code ? ` · ${user.user_code}` : ''}`,
                        searchText: `${user.name} ${user.user_code || ''}`,
      department: user.departmentName ?? null,
                      }))}
                      onChange={(value) => setForm({ ...form, managerUserId: value })}
                    />
                  </div>
                  <button type="submit" disabled={saving}>
                    {saving ? 'Đang tạo...' : <><span aria-hidden="true">＋</span> <span>Tạo phòng ban</span></>}
                  </button>
                </form>
              )}
              {canCreateTeams && (
                <form className="organization-panel organization-form" onSubmit={saveTeamMembers}>
                  <span className="organization-step">04 · TEAM</span>
                  <h3>Tạo team</h3>
                  <div className="organization-search-select"><span>Phòng ban</span>
                    <SearchableSelect
                      label="Phòng ban"
                      required
                      value={teamForm.departmentId}
                      placeholder="Tìm phòng ban theo tên..."
                      emptyOption="Chọn phòng ban"
                      options={departments.map((department) => ({
                        value: department.id,
                        label: department.name,
                        searchText: `${department.name} ${department.divisionName || ''}`,
                      }))}
                      onChange={(value) => setTeamForm({ ...teamForm, departmentId: value, memberUserIds: [] })}
                    />
                  </div>
                  <label><span>Tên team</span>
                    <input
                      required
                      maxLength={120}
                      placeholder="VD: Team lắp ráp ca 1"
                      value={teamForm.name}
                      onChange={(event) => setTeamForm({ ...teamForm, name: event.target.value })}
                    />
                  </label>
                  {teamForm.departmentId && (
                    <div className="organization-search-select">
                      <span>Thành viên team</span>
                      <SearchableMultiSelect
                        label="Thành viên team"
                        placeholder="Tìm user theo tên hoặc mã nhân viên..."
                        options={teamMemberOptions(teamForm.departmentId, null)}
                        selectedValues={teamForm.memberUserIds}
                        onChange={(memberUserIds) => setTeamForm({ ...teamForm, memberUserIds })}
                      />
                    </div>
                  )}
                  <span className="organization-scope-note">Một phòng ban có thể có nhiều team; tên team không được trùng trong cùng phòng ban.</span>
                  <button type="submit" disabled={saving || !teamForm.departmentId || !teamForm.name.trim()}>
                    {saving ? 'Đang tạo...' : <><span aria-hidden="true">＋</span> <span>Tạo team</span></>}
                  </button>
                </form>
              )}
            </div>
          </section>

          <section className="organization-section">
            <div className="organization-section-heading">
              <div><p className="meetings-eyebrow">ĐƠN VỊ HIỆN CÓ</p><h2>Cây tổ chức</h2></div>
            </div>
            <div className="organization-list-grid">
              {(isOrganizationAdmin || currentUser.orgLevel === 'division_manager') && (
                <section className="organization-list-panel">
                  <div className="organization-list-heading"><h3>Quản lý bộ phận</h3><span>{divisionManagers.length}</span></div>
                  {divisionManagers.length > 0 && <SearchPagination list={divisionManagerList} compact />}
                  {divisionManagers.length ? (
                    <ul>
                      {divisionManagerList.pageItems.map((manager) => (
                        <li key={manager.userId}>
                          {editingDivisionManagerId === manager.userId ? (
                            <form className="organization-edit-form" onSubmit={(event) => updateDivisionManager(event, manager)}>
                              <div className="organization-search-select">
                                <span>Các bộ phận của {manager.name}</span>
                                <SearchableMultiSelect
                                  label={`Các bộ phận của ${manager.name}`}
                                  placeholder="Tìm bộ phận theo tên..."
                                  options={divisionOptions(manager.userId)}
                                  selectedValues={divisionManagerEditIds}
                                  onChange={setDivisionManagerEditIds}
                                />
                              </div>
                              <span className="organization-scope-note">Bỏ chọn hết bộ phận để gỡ người này về cấp nhân viên.</span>
                              <div className="organization-actions">
                                <button type="submit" disabled={saving}>Lưu</button>
                                <button type="button" className="organization-cancel" onClick={() => setEditingDivisionManagerId(null)}>Hủy</button>
                              </div>
                            </form>
                          ) : (
                            <>
                              <div className="organization-unit-copy">
                                <strong>{manager.name}{manager.userCode ? ` · ${manager.userCode}` : ''}</strong>
                                <span>{manager.divisions.map((division) => division.name).join(', ')}</span>
                                <small>{language === 'en'
                                  ? `${manager.divisions.length} ${manager.divisions.length === 1 ? 'division' : 'divisions'}`
                                  : language === 'ko' ? `본부 ${manager.divisions.length}개` : `${manager.divisions.length} bộ phận`}</small>
                              </div>
                              {isOrganizationAdmin && (
                                <div className="organization-actions">
                                  <button type="button" onClick={() => {
                                    setEditingDivisionManagerId(manager.userId)
                                    setDivisionManagerEditIds(manager.divisions.map((division) => division.id))
                                  }}>Sửa</button>
                                  <button type="button" className="organization-delete" onClick={() => removeDivisionManager(manager)}>Gỡ</button>
                                </div>
                              )}
                            </>
                          )}
                        </li>
                      ))}
                    </ul>
                  ) : <p className="organization-empty">Chưa có Quản lý bộ phận. Gán một người để quản lý một hoặc nhiều bộ phận.</p>}
                  {divisionManagers.length > 0 && !divisionManagerList.pageItems.length && <p className="organization-empty">Không tìm thấy kết quả.</p>}
                </section>
              )}
              {(isOrganizationAdmin || isDivisionLevel) && (
                <section className="organization-list-panel">
                  <div className="organization-list-heading"><h3>Bộ phận</h3><span>{organization.divisions.length}</span></div>
                  {organization.divisions.length > 0 && <SearchPagination list={divisionList} compact />}
                  {organization.divisions.length ? (
                    <ul>
                      {divisionList.pageItems.map((division) => (
                        <li key={division.id}>
                          {editingDivisionId === division.id ? (
                            <form className="organization-edit-form" onSubmit={(event) => updateDivision(event, division.id)}>
                              <label>
                                <span>Tên bộ phận</span>
                                <input
                                  required
                                  maxLength={120}
                                  aria-label={`Tên bộ phận ${division.name}`}
                                  value={divisionEditForm.name}
                                  onChange={(event) => setDivisionEditForm({ ...divisionEditForm, name: event.target.value })}
                                />
                              </label>
                              {isOrganizationAdmin && (
                                <SearchableSelect
                                  label={`Quản lý bộ phận ${division.name}`}
                                  value={divisionEditForm.divisionManagerUserId}
                                  placeholder="Tìm theo tên hoặc mã nhân viên..."
                                  emptyOption="-- Chưa gán quản lý bộ phận --"
                                  options={divisionManagerCandidates(divisionEditForm.managerUserId, division.divisionManagerUserId).map(toUserOption)}
                                  onChange={(value) => setDivisionEditForm({ ...divisionEditForm, divisionManagerUserId: value })}
                                />
                              )}
                              {canChangeDivisionHead && (
                                <SearchableSelect
                                  label={`Trưởng bộ phận ${division.name}`}
                                  value={divisionEditForm.managerUserId}
                                  placeholder="Tìm theo tên hoặc mã nhân viên..."
                                  emptyOption="-- Chưa gán trưởng bộ phận --"
                                  options={managerCandidates(isOrganizationAdmin ? null : division.id, division.managerUserId)
                                    .filter((user) => String(user.id) !== String(divisionEditForm.divisionManagerUserId))
                                    .map(toUserOption)}
                                  onChange={(value) => setDivisionEditForm({ ...divisionEditForm, managerUserId: value })}
                                />
                              )}
                              <div className="organization-actions">
                                <button type="submit" disabled={saving}>Lưu</button>
                                <button type="button" className="organization-cancel" onClick={() => setEditingDivisionId(null)}>Hủy</button>
                              </div>
                            </form>
                          ) : (
                            <>
                              <div className="organization-unit-copy">
                                <strong>{division.name}</strong>
                                <span>Quản lý bộ phận: {division.divisionManagerName || 'Chưa có'}</span>
                                <small>Trưởng bộ phận: {division.managerName || 'Chưa có'}</small>
                              </div>
                              {canEditDivisions && (
                                <div className="organization-actions">
                                  <button type="button" onClick={() => {
                                    setEditingDivisionId(division.id)
                                    setDivisionEditForm({
                                      name: division.name,
                                      managerUserId: division.managerUserId || '',
                                      divisionManagerUserId: division.divisionManagerUserId || '',
                                    })
                                  }}>Sửa</button>
                                  {isOrganizationAdmin && <button type="button" className="organization-delete" onClick={() => removeDivision(division)}>Xóa</button>}
                                </div>
                              )}
                            </>
                          )}
                        </li>
                      ))}
                    </ul>
                  ) : <p className="organization-empty">Chưa có bộ phận. Tạo bộ phận để bắt đầu xây dựng cơ cấu.</p>}
                  {organization.divisions.length > 0 && !divisionList.pageItems.length && <p className="organization-empty">Không tìm thấy kết quả.</p>}
                </section>
              )}
              <section className="organization-list-panel">
                <div className="organization-list-heading"><h3>Phòng ban</h3><span>{departments.length}</span></div>
                {departments.length > 0 && <SearchPagination list={departmentList} compact />}
                {departments.length ? (
                  <ul>
                    {departmentList.pageItems.map((department) => (
                      <li key={department.id}>
                        {editingId === department.id ? (
                          <form className="organization-edit-form" onSubmit={(event) => update(event, department.id)}>
                            <input
                              required
                              maxLength={120}
                              aria-label={language === 'en'
                                ? `Department name: ${department.name}`
                                : language === 'ko' ? `부서 이름: ${department.name}` : `Tên phòng ban ${department.name}`}
                              value={editForm.name}
                              onChange={(event) => setEditForm({ ...editForm, name: event.target.value })}
                            />
                            {organization.divisions.length > 0 && (
                              <SearchableSelect
                                label={`Bộ phận của ${department.name}`}
                                required={isDivisionLevel}
                                value={editForm.divisionId}
                                placeholder="Tìm bộ phận theo tên..."
                                emptyOption="-- Chưa gán bộ phận --"
                                options={organization.divisions.map((division) => ({
                                  value: division.id,
                                  label: division.name,
                                  searchText: division.name,
                                }))}
                                onChange={(value) => setEditForm({ ...editForm, divisionId: value, managerUserId: '' })}
                              />
                            )}
                            <SearchableSelect
                              label={`Trưởng phòng ${department.name}`}
                              value={editForm.managerUserId}
                              placeholder="Tìm theo tên hoặc mã nhân viên..."
                              emptyOption="-- Chưa gán trưởng phòng --"
                              options={managerCandidates(editForm.divisionId, department.managerUserId).map((user) => ({
                                value: user.id,
                                label: `${user.name}${user.user_code ? ` · ${user.user_code}` : ''}`,
                                searchText: `${user.name} ${user.user_code || ''}`,
      department: user.departmentName ?? null,
                              }))}
                              onChange={(value) => setEditForm({ ...editForm, managerUserId: value })}
                            />
                            <div className="organization-actions">
                              <button type="submit">Lưu</button>
                              <button type="button" className="organization-cancel" onClick={() => setEditingId(null)}>Hủy</button>
                            </div>
                          </form>
                        ) : (
                          <>
                            <div className="organization-unit-copy">
                              <strong>{department.name}</strong>
                              <span>{department.divisionName || 'Chưa gán bộ phận'} · {department.managerName || 'Chưa có trưởng phòng'}</span>
                              <small>{language === 'en'
                                ? `${department.memberCount} ${department.memberCount === 1 ? 'member' : 'members'}`
                                : language === 'ko' ? `${department.memberCount}명` : `${department.memberCount} thành viên`}</small>
                            </div>
                            <div className="organization-actions">
                              {(isOrganizationAdmin || isDivisionLevel) && (
                                <button type="button" onClick={() => {
                                  setEditingId(department.id)
                                  setEditForm({ name: department.name, managerUserId: department.managerUserId || '', divisionId: department.divisionId || '' })
                                }}>Sửa</button>
                              )}
                              {isOrganizationAdmin && <button type="button" className="organization-delete" onClick={() => remove(department.id)}>Xóa</button>}
                            </div>
                          </>
                        )}
                      </li>
                    ))}
                  </ul>
                ) : <p className="organization-empty">Chưa có phòng ban. Tạo phòng ban để phân công trưởng phòng và thành viên.</p>}
                {departments.length > 0 && !departmentList.pageItems.length && <p className="organization-empty">Không tìm thấy kết quả.</p>}
              </section>
              <section className="organization-list-panel">
                <div className="organization-list-heading"><h3>Team</h3><span>{organization.teams.length}</span></div>
                {organization.teams.length > 0 && <SearchPagination list={teamList} compact />}
                {organization.teams.length ? (
                  <ul>
                    {teamList.pageItems.map((team) => (
                      <li key={team.id}>
                        {editingTeamId === team.id ? (
                          <form className="organization-edit-form" onSubmit={(event) => updateTeam(event, team.id)}>
                            <label>
                              <span>Tên team</span>
                              <input
                                required
                                maxLength={120}
                                aria-label={`Tên team ${team.name}`}
                                value={teamEditForm.name}
                                onChange={(event) => setTeamEditForm({ ...teamEditForm, name: event.target.value })}
                              />
                            </label>
                            <div className="organization-search-select">
                              <span>Thành viên team</span>
                              <SearchableMultiSelect
                                label={`Thành viên team ${team.name}`}
                                placeholder="Tìm user theo tên hoặc mã nhân viên..."
                                options={teamMemberOptions(team.departmentId, team.id)}
                                selectedValues={teamEditForm.memberUserIds}
                                onChange={(memberUserIds) => setTeamEditForm({ ...teamEditForm, memberUserIds })}
                              />
                            </div>
                            <div className="organization-actions">
                              <button type="submit" disabled={saving}>Lưu</button>
                              <button type="button" className="organization-cancel" onClick={() => setEditingTeamId(null)}>Hủy</button>
                            </div>
                          </form>
                        ) : (
                          <>
                            <div className="organization-unit-copy">
                              <strong>{team.name}</strong>
                              <span>{departments.find((department) => String(department.id) === String(team.departmentId))?.name || 'Phòng ban chưa xác định'}</span>
                              <small>{team.memberUserIds?.length || 0} thành viên · {team.managerName || 'Chưa có team lead'}</small>
                            </div>
                            {(isOrganizationAdmin || isDivisionLevel
                              || currentUser.orgLevel === 'department_head' || team.managerUserId === currentUser.id) && (
                              <div className="organization-actions">
                                <button type="button" onClick={() => {
                                  setEditingTeamId(team.id)
                                  setTeamEditForm({ name: team.name, memberUserIds: team.memberUserIds || [] })
                                }}>Sửa</button>
                                <button type="button" className="organization-delete" onClick={() => removeTeam(team)}>Xóa</button>
                              </div>
                            )}
                          </>
                        )}
                      </li>
                    ))}
                  </ul>
                ) : <p className="organization-empty">Chưa có team. Chọn phòng ban, đặt tên team và chọn thành viên để tạo team.</p>}
                {organization.teams.length > 0 && !teamList.pageItems.length && <p className="organization-empty">Không tìm thấy kết quả.</p>}
              </section>
            </div>
          </section>
        </main>
      </LocalizedTree>
    )
  }

export default Departments
