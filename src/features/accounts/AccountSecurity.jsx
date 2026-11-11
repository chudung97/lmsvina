import { useState } from 'react'
import { LocalizedTree } from '../../i18n'
import SealProfileEditor from '../pdf-seal/SealProfileEditor'

function AccountSecurity({ sessionUser, onUserUpdated }) {
  const [profile, setProfile] = useState({ name: sessionUser.name, email: sessionUser.email })
  const [profileError, setProfileError] = useState('')
  const [profileNotice, setProfileNotice] = useState('')
  const [profileSaving, setProfileSaving] = useState(false)
  const [form, setForm] = useState({ currentPassword: '', newPassword: '', confirmation: '' })
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [saving, setSaving] = useState(false)

  const updateProfile = async (event) => {
    event.preventDefault()
    setProfileError('')
    setProfileNotice('')
    setProfileSaving(true)
    try {
      const response = await fetch('/api/account/profile', {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(profile),
      })
      const result = await response.json()
      if (!response.ok) throw new Error(result.error || 'Không thể cập nhật hồ sơ.')
      setProfile(result.user)
      onUserUpdated(result.user)
      setProfileNotice('Đã cập nhật tên và email tài khoản.')
    } catch (requestError) {
      setProfileError(requestError.message)
    } finally {
      setProfileSaving(false)
    }
  }

  const submit = async (event) => {
    event.preventDefault()
    setError('')
    setNotice('')
    if (form.newPassword !== form.confirmation) {
      setError('Mật khẩu mới nhập lại chưa khớp.')
      return
    }

    setSaving(true)
    try {
      const response = await fetch('/api/account/password', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          currentPassword: form.currentPassword,
          newPassword: form.newPassword,
        }),
      })
      const result = await response.json()
      if (!response.ok) throw new Error(result.error || 'Không thể đổi mật khẩu.')
      setForm({ currentPassword: '', newPassword: '', confirmation: '' })
      setNotice('Đã đổi mật khẩu. Các phiên đăng nhập khác đã bị đăng xuất.')
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <LocalizedTree>
    <main className="account-admin-page">
      <header>
        <p className="meetings-eyebrow">Tài khoản</p>
        <h1>Hồ sơ &amp; bảo mật</h1>
        <p>Cập nhật thông tin đăng nhập, mật khẩu và mẫu con dấu/chữ ký của bạn.</p>
      </header>
      {profileError && <p className="meeting-error" role="alert">{profileError}</p>}
      {profileNotice && <p className="account-notice" role="status">{profileNotice}</p>}
      {error && <p className="meeting-error" role="alert">{error}</p>}
      {notice && <p className="account-notice" role="status">{notice}</p>}
      <form className="account-form password-change-form" onSubmit={updateProfile}>
        <h2>Thông tin tài khoản</h2>
        <label>
          <span>Họ tên hiển thị</span>
          <input
            required
            maxLength={120}
            autoComplete="name"
            value={profile.name}
            onChange={(event) => setProfile({ ...profile, name: event.target.value })}
          />
        </label>
        <label>
          <span>Email đăng nhập</span>
          <input
            required
            type="email"
            maxLength={254}
            autoComplete="email"
            value={profile.email}
            onChange={(event) => setProfile({ ...profile, email: event.target.value })}
          />
        </label>
        <button type="submit" disabled={profileSaving}>
          {profileSaving ? 'Đang lưu...' : 'Lưu thông tin'}
        </button>
      </form>
      <form className="account-form password-change-form" onSubmit={submit}>
        <h2>Đổi mật khẩu</h2>
        <label>
          <span>Mật khẩu hiện tại</span>
          <input
            type="password"
            autoComplete="current-password"
            required
            value={form.currentPassword}
            onChange={(event) => setForm({ ...form, currentPassword: event.target.value })}
          />
        </label>
        <label>
          <span>Mật khẩu mới</span>
          <input
            type="password"
            autoComplete="new-password"
            minLength={6}
            required
            value={form.newPassword}
            onChange={(event) => setForm({ ...form, newPassword: event.target.value })}
          />
        </label>
        <label>
          <span>Nhập lại mật khẩu mới</span>
          <input
            type="password"
            autoComplete="new-password"
            minLength={6}
            required
            value={form.confirmation}
            onChange={(event) => setForm({ ...form, confirmation: event.target.value })}
          />
        </label>
        <button type="submit" disabled={saving}>{saving ? 'Đang lưu...' : 'Lưu mật khẩu mới'}</button>
      </form>
      <SealProfileEditor sessionUser={sessionUser} onUserUpdated={onUserUpdated} />
    </main>
    </LocalizedTree>
  )
}

export default AccountSecurity
