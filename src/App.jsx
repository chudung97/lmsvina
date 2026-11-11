import { useCallback, useEffect, useState } from 'react'
import Attendance from './features/attendance/Attendance'
import AccountSecurity from './features/accounts/AccountSecurity'
import AdminUsers from './features/accounts/AdminUsers'
import Departments from './features/accounts/Departments'
import MeetingScheduler from './features/meetings/MeetingScheduler'
import MealReports from './features/meals/MealReports'
import Overtime from './features/overtime/Overtime'
import PdfSeal from './features/pdf-seal/PdfSeal'
import PermitRequests from './features/permits/PermitRequests'
import Warehouse from './features/warehouse/Warehouse'
import { connectRealtime } from './realtime'
import { LanguagePicker, LocalizedTree } from './i18n'
import './App.css'

function App() {
  const [activeFeature, setActiveFeature] = useState('meetings')
  const [auth, setAuth] = useState(null)
  const [authLoading, setAuthLoading] = useState(true)
  const [credentials, setCredentials] = useState({ identifier: '', password: '' })
  const [authError, setAuthError] = useState('')
  const [authBusy, setAuthBusy] = useState(false)
  const canManageUsers = auth?.user?.role === 'admin'
    || ['ceo', 'division_head', 'department_head', 'team_lead'].includes(auth?.user?.orgLevel)
  const canManageDepartments = auth?.user?.role === 'admin'
    || ['ceo', 'division_head', 'department_head'].includes(auth?.user?.orgLevel)
  const updateSessionUser = useCallback((user) => {
    setAuth((current) => ({ ...current, user }))
  }, [])

  useEffect(() => {
    if (!auth?.authenticated || !auth.user?.id) return undefined
    return connectRealtime(auth.user.id)
  }, [auth?.authenticated, auth?.user?.id])

  useEffect(() => {
    if (!auth?.authenticated) return undefined

    const refreshAccount = async (event) => {
      if (!['accounts', '*'].includes(event.detail?.resource)) return
      try {
        const response = await fetch('/api/auth/status')
        const status = await response.json()
        if (!response.ok) throw new Error(status.error || 'Không thể kiểm tra phiên đăng nhập.')
        if (!status.authenticated) {
          setAuth({ authenticated: false, setupRequired: false, user: null })
          setActiveFeature('meetings')
          return
        }
        setAuth(status)
      } catch (error) {
        setAuthError(error.message)
      }
    }

    window.addEventListener('workdesk:updated', refreshAccount)
    return () => window.removeEventListener('workdesk:updated', refreshAccount)
  }, [auth?.authenticated])

  useEffect(() => {
    let cancelled = false
    fetch('/api/auth/status')
      .then(async (response) => {
        const result = await response.json()
        if (!response.ok) throw new Error(result.error || 'Không thể kết nối máy chủ.')
        return result
      })
      .then((result) => {
        if (!cancelled) setAuth(result)
      })
      .catch((error) => {
        if (!cancelled) setAuthError(error.message)
      })
      .finally(() => {
        if (!cancelled) setAuthLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [])

  const login = async (event) => {
    event.preventDefault()
    setAuthError('')
    setAuthBusy(true)
    try {
      const response = await fetch('/api/auth/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(credentials),
      })
      const result = await response.json()
      if (!response.ok) throw new Error(result.error || 'Đăng nhập thất bại.')
      setAuth({ authenticated: true, setupRequired: false, user: result.user })
      setCredentials({ identifier: '', password: '' })
    } catch (error) {
      setAuthError(error.message)
    } finally {
      setAuthBusy(false)
    }
  }

  const logout = async () => {
    setAuthError('')
    try {
      const response = await fetch('/api/auth/logout', { method: 'POST' })
      if (!response.ok) throw new Error('Không thể đăng xuất.')
      setAuth({ authenticated: false, setupRequired: false, user: null })
      setActiveFeature('meetings')
    } catch (error) {
      setAuthError(error.message)
    }
  }

  if (authLoading) return (
    <LocalizedTree>
      <main className="auth-shell"><p>Đang kiểm tra phiên đăng nhập...</p></main>
    </LocalizedTree>
  )

  if (!auth?.authenticated) {
    return (
      <LocalizedTree>
        <main className="auth-shell">
          <form className="login-card" onSubmit={login}>
            <LanguagePicker />
            <p className="meetings-eyebrow">Workdesk nội bộ</p>
            <h1>Đăng nhập</h1>
            {authError && <p role="alert" className="meeting-error">{authError}</p>}
            {auth?.setupRequired && (
              <p className="setup-hint">
                Chưa khởi tạo quản trị viên. Trên máy chủ, chạy
                {' '}php artisan workdesk:provision-admin &lt;mã-nhân-viên&gt; &lt;email&gt;
                {' '}trong thư mục backend và nhập mật khẩu tại lời nhắc bảo mật.
              </p>
            )}
            <label>
              <span>Mã nhân viên hoặc email</span>
              <input
                type="text"
                autoComplete="username"
                required
                value={credentials.identifier}
                onChange={(event) => setCredentials({ ...credentials, identifier: event.target.value })}
              />
            </label>
            <label>
              <span>Mật khẩu</span>
              <input
                type="password"
                autoComplete="current-password"
                required
                value={credentials.password}
                onChange={(event) => setCredentials({ ...credentials, password: event.target.value })}
              />
            </label>
            <button type="submit" disabled={authBusy || auth?.setupRequired}>
              {authBusy ? 'Đang đăng nhập...' : 'Đăng nhập'}
            </button>
          </form>
        </main>
      </LocalizedTree>
    )
  }

  return (
    <LocalizedTree>
      <div className="app-shell">
        <nav className="feature-nav" aria-label="Tính năng">
          <a className="feature-brand" href="#home" onClick={(event) => event.preventDefault()}>
            <span className="feature-brand-mark" aria-hidden="true">W</span>
            <span>Workdesk</span>
          </a>
          <p className="feature-nav-label">KHÔNG GIAN LÀM VIỆC</p>
          <div className="feature-nav-links">
            <button
              type="button"
              className={activeFeature === 'meetings' ? 'active' : ''}
              aria-current={activeFeature === 'meetings' ? 'page' : undefined}
              onClick={() => setActiveFeature('meetings')}
            >
              Lịch họp
            </button>
            <button
              type="button"
              className={activeFeature === 'attendance' ? 'active' : ''}
              aria-current={activeFeature === 'attendance' ? 'page' : undefined}
              onClick={() => setActiveFeature('attendance')}
            >
              Chấm công
            </button>
            <button
              type="button"
              className={activeFeature === 'overtime' ? 'active' : ''}
              aria-current={activeFeature === 'overtime' ? 'page' : undefined}
              onClick={() => setActiveFeature('overtime')}
            >
              Tăng ca
            </button>
            <button
              type="button"
              className={activeFeature === 'meals' ? 'active' : ''}
              aria-current={activeFeature === 'meals' ? 'page' : undefined}
              onClick={() => setActiveFeature('meals')}
            >
              Báo cơm
            </button>
            <button
              type="button"
              className={activeFeature === 'permits' ? 'active' : ''}
              aria-current={activeFeature === 'permits' ? 'page' : undefined}
              onClick={() => setActiveFeature('permits')}
            >
              Giấy phép cổng
            </button>
            <button
              type="button"
              className={activeFeature === 'pdf' ? 'active' : ''}
              aria-current={activeFeature === 'pdf' ? 'page' : undefined}
              onClick={() => setActiveFeature('pdf')}
            >
              Đóng dấu PDF
            </button>
            <button
              type="button"
              className={activeFeature === 'warehouse' ? 'active' : ''}
              aria-current={activeFeature === 'warehouse' ? 'page' : undefined}
              onClick={() => setActiveFeature('warehouse')}
            >
              Quản lý kho
            </button>
            <button
              type="button"
              className={activeFeature === 'security' ? 'active' : ''}
              aria-current={activeFeature === 'security' ? 'page' : undefined}
              onClick={() => setActiveFeature('security')}
            >
              Hồ sơ &amp; bảo mật
            </button>
            {canManageUsers && (
              <button
                type="button"
                className={activeFeature === 'admin' ? 'active' : ''}
                aria-current={activeFeature === 'admin' ? 'page' : undefined}
                onClick={() => setActiveFeature('admin')}
              >
                Tài khoản &amp; phân quyền
              </button>
            )}
            {canManageDepartments && (
              <>
                <button
                  type="button"
                  className={activeFeature === 'departments' ? 'active' : ''}
                  aria-current={activeFeature === 'departments' ? 'page' : undefined}
                  onClick={() => setActiveFeature('departments')}
                >
                  Phòng ban
                </button>
              </>
            )}
          </div>
          <LanguagePicker />
          <div className="feature-user">
            <span className="feature-user-label">Tài khoản</span>
            <strong>{auth.user.name}</strong>
            <button type="button" onClick={logout}>Đăng xuất</button>
          </div>
        </nav>
        <main className="app-main">
          {authError && <p role="alert" className="app-error global-auth-error">{authError}</p>}
          {activeFeature === 'meetings' ? <MeetingScheduler sessionUser={auth.user} />
            : activeFeature === 'attendance' ? <Attendance />
            : activeFeature === 'overtime' ? <Overtime />
              : activeFeature === 'meals' ? <MealReports />
            : activeFeature === 'permits' ? <PermitRequests sessionUser={auth.user} />
            : activeFeature === 'warehouse' ? <Warehouse />
            : activeFeature === 'admin' ? <AdminUsers currentUser={auth.user} />
            : activeFeature === 'departments' ? <Departments currentUser={auth.user} />
              : activeFeature === 'security' ? (
                <AccountSecurity sessionUser={auth.user} onUserUpdated={updateSessionUser} />
              )
                : (
                  <PdfSeal
                    sessionUser={auth.user}
                    onSessionUserUpdated={updateSessionUser}
                    onOpenSettings={() => setActiveFeature('security')}
                  />
                )}
        </main>
      </div>
    </LocalizedTree>
  )
}

export default App
