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
  const [divisionForm, setDivisionForm] = useState({ name: '', managerUserId: '' })
  const [teamForm, setTeamForm] = useState({ departmentId: '', memberUserIds: [] })
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [saving, setSaving] = useState(false)
  const [editingId, setEditingId] = useState(null)
  const [editForm, setEditForm] = useState({ name: '', managerUserId: '', divisionId: '' })
  const [editingDivisionId, setEditingDivisionId] = useState(null)
  const [divisionEditForm, setDivisionEditForm] = useState({ name: '', managerUserId: '' })
  const [editingTeamId, setEditingTeamId] = useState(null)
  const [teamEditForm, setTeamEditForm] = useState({ memberUserIds: [] })
  const isOrganizationAdmin = currentUser.role === 'admin' || currentUser.orgLevel === 'ceo'
  const canCreateDepartments = isOrganizationAdmin || currentUser.orgLevel === 'division_head'
  const canCreateTeams = canCreateDepartments || currentUser.orgLevel === 'department_head'
  const managerCandidates = (divisionId, currentManagerId = null) => users.filter((user) => (
    currentManagerId !== null && currentManagerId !== undefined
      && String(user.id) === String(currentManagerId)
      || (user.role !== 'admin'
        && user.orgLevel === 'employee'
        && (!divisionId || !user.divisionId || String(user.divisionId) === String(divisionId)))
  ))
  const teamForDepartment = (departmentId) => organization.teams.find(
    (team) => String(team.departmentId) === String(departmentId),
  )
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
  const divisionList = useSearchPagination(
    organization.divisions,
    (division) => `${division.name} ${division.managerName || ''}`,
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
      setDivisionForm({ name: '', managerUserId: '' })
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

  const saveTeamMembers = async (event) => {
    event.preventDefault()
    setError(''); setNotice(''); setSaving(true)
    try {
      await requestApi('/api/organization/teams', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(teamForm) })
      setTeamForm({ departmentId: '', memberUserIds: [] })
      setNotice('Đã lưu thành viên team.')
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
            <p>Quản lý bộ phận, phòng ban, team và người phụ trách.</p>
          </header>
          {error && <p className="meeting-error" role="alert">{error}</p>}
          {notice && <p className="account-notice" role="status">{notice}</p>}

          <section className="organization-summary" aria-label="Tổng quan cơ cấu tổ chức">
            <article><span>Bộ phận</span><strong>{organization.divisions.length}</strong></article>
            <article><span>Phòng ban</span><strong>{departments.length}</strong></article>
            <article><span>Team</span><strong>{organization.teams.length}</strong></article>
          </section>

          <section className="organization-section">
            <div className="organization-section-heading">
              <div><p className="meetings-eyebrow">THIẾT LẬP</p><h2>Thêm đơn vị</h2></div>
              <span>Tạo theo thứ tự: bộ phận, phòng ban, sau đó là team.</span>
            </div>
            <div className="organization-create-grid">
              {(isOrganizationAdmin || currentUser.orgLevel === 'division_head') && (
                <section className="organization-panel">
                  {isOrganizationAdmin ? (
                    <form className="organization-form" onSubmit={createDivision}>
                      <span className="organization-step">01 · BỘ PHẬN</span>
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
                      <button type="submit" disabled={saving}><span aria-hidden="true">＋</span> <span>Tạo bộ phận</span></button>
                    </form>
                  ) : (
                    <div className="organization-scope-note">Bạn đang quản lý bộ phận được phân công.</div>
                  )}
                </section>
              )}
              {canCreateDepartments && (
                <form className="organization-panel organization-form" onSubmit={submit}>
                  <span className="organization-step">02 · PHÒNG BAN</span>
                  <h3>Tạo phòng ban</h3>
                  <label><span>Tên phòng ban</span>
                    <input required maxLength={120} value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} />
                  </label>
                  {organization.divisions.length > 0 && (
                    <div className="organization-search-select"><span>Bộ phận</span>
                      <SearchableSelect
                        label="Bộ phận"
                        required={currentUser.orgLevel === 'division_head'}
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
                  <span className="organization-step">03 · TEAM</span>
                  <h3>Chọn thành viên team</h3>
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
                      onChange={(value) => {
                        const team = teamForDepartment(value)
                        setTeamForm({ departmentId: value, memberUserIds: team?.memberUserIds || [] })
                      }}
                    />
                  </div>
                  {teamForm.departmentId && (
                    <div className="organization-search-select">
                      <span>Thành viên team</span>
                      <SearchableMultiSelect
                        label="Thành viên team"
                        placeholder="Tìm user theo tên hoặc mã nhân viên..."
                        options={teamMemberOptions(
                          teamForm.departmentId,
                          teamForDepartment(teamForm.departmentId)?.id || null,
                        )}
                        selectedValues={teamForm.memberUserIds}
                        onChange={(memberUserIds) => setTeamForm({ ...teamForm, memberUserIds })}
                      />
                    </div>
                  )}
                  {teamForm.departmentId && (
                    <>
                      <span className="organization-scope-note">Team sẽ sử dụng tên phòng ban đã chọn.</span>
                      <button type="submit" disabled={saving}>
                        {saving ? 'Đang lưu...' : 'Lưu thành viên'}
                      </button>
                    </>
                  )}
                </form>
              )}
            </div>
          </section>

          <section className="organization-section">
            <div className="organization-section-heading">
              <div><p className="meetings-eyebrow">ĐƠN VỊ HIỆN CÓ</p><h2>Cây tổ chức</h2></div>
            </div>
            <div className="organization-list-grid">
              {(isOrganizationAdmin || currentUser.orgLevel === 'division_head') && (
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
                              <SearchableSelect
                                label={`Trưởng bộ phận ${division.name}`}
                                value={divisionEditForm.managerUserId}
                                placeholder="Tìm theo tên hoặc mã nhân viên..."
                                emptyOption="-- Chưa gán trưởng bộ phận --"
                                options={managerCandidates(null, division.managerUserId).map((user) => ({
                                  value: user.id,
                                  label: `${user.name}${user.user_code ? ` · ${user.user_code}` : ''}`,
                                  searchText: `${user.name} ${user.user_code || ''}`,
      department: user.departmentName ?? null,
                                }))}
                                onChange={(value) => setDivisionEditForm({ ...divisionEditForm, managerUserId: value })}
                              />
                              <div className="organization-actions">
                                <button type="submit" disabled={saving}>Lưu</button>
                                <button type="button" className="organization-cancel" onClick={() => setEditingDivisionId(null)}>Hủy</button>
                              </div>
                            </form>
                          ) : (
                            <>
                              <div className="organization-unit-copy">
                                <strong>{division.name}</strong>
                                <span>{division.managerName || 'Chưa có trưởng bộ phận'}</span>
                              </div>
                              <div className="organization-actions">
                                <button type="button" onClick={() => {
                                  setEditingDivisionId(division.id)
                                  setDivisionEditForm({ name: division.name, managerUserId: division.managerUserId || '' })
                                }}>Sửa</button>
                                {isOrganizationAdmin && <button type="button" className="organization-delete" onClick={() => removeDivision(division)}>Xóa</button>}
                              </div>
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
                                required={currentUser.orgLevel === 'division_head'}
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
                              {(isOrganizationAdmin || currentUser.orgLevel === 'division_head') && (
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
                            <span className="organization-scope-note">Team sẽ sử dụng tên phòng ban.</span>
                            <div className="organization-actions">
                              <button type="submit" disabled={saving}>Lưu thành viên</button>
                              <button type="button" className="organization-cancel" onClick={() => setEditingTeamId(null)}>Hủy</button>
                            </div>
                          </form>
                        ) : (
                          <>
                            <div className="organization-unit-copy">
                              <strong>{team.name}</strong>
                              <span>{departments.find((department) => department.id === team.departmentId)?.name || 'Phòng ban chưa xác định'}</span>
                              <small>{team.memberUserIds?.length || 0} thành viên · {team.managerName || 'Chưa có team lead'}</small>
                            </div>
                            {(isOrganizationAdmin || currentUser.orgLevel === 'division_head'
                              || currentUser.orgLevel === 'department_head' || team.managerUserId === currentUser.id) && (
                              <div className="organization-actions">
                                <button type="button" onClick={() => {
                                  setEditingTeamId(team.id)
                                  setTeamEditForm({ memberUserIds: team.memberUserIds || [] })
                                }}>Sửa</button>
                                <button type="button" className="organization-delete" onClick={() => removeTeam(team)}>Xóa</button>
                              </div>
                            )}
                          </>
                        )}
                      </li>
                    ))}
                  </ul>
                ) : <p className="organization-empty">Chưa có team. Chọn phòng ban và lưu thành viên để tạo team theo phòng ban.</p>}
                {organization.teams.length > 0 && !teamList.pageItems.length && <p className="organization-empty">Không tìm thấy kết quả.</p>}
              </section>
            </div>
          </section>
        </main>
      </LocalizedTree>
    )
  }

export default Departments
