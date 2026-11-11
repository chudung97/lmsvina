import { useEffect, useMemo, useState } from 'react'
import { buildSealSvg, processUploadedImage, sealPalette, sealStyles, svgDataUrl, svgToPng } from './sealDesign'
import './SealProfileEditor.css'

const fieldLabels = {
  round: { org: 'Tên đơn vị (chạy vòng ngoài)', center: 'Chữ giữa', sub: 'Dòng dưới (VD: mã số DN, bộ phận)' },
  square: { org: 'Dòng trên', center: 'Chữ chính', sub: 'Dòng dưới' },
  rect: { org: 'Chức danh (VD: GIÁM ĐỐC)', center: 'Họ tên', sub: 'Dòng phụ (VD: bộ phận)' },
  signature: { org: 'Dòng trên (tùy chọn)', center: 'Tên ký (kiểu viết tay)', sub: 'Họ tên/chức danh in bên dưới' },
}

const placeholders = {
  round: { org: 'CÔNG TY TNHH ABC VIỆT NAM', center: 'HR-GA', sub: 'MST 0123456789' },
  square: { org: 'ĐÃ DUYỆT', center: 'HR-GA', sub: 'Phòng Hành chính' },
  rect: { org: 'TRƯỞNG PHÒNG', center: 'Nguyễn Văn A', sub: 'Phòng Hành chính - Nhân sự' },
  signature: { org: '', center: 'Nguyễn Văn A', sub: 'Trưởng phòng HC-NS' },
}

const requestApi = async (url, options = {}) => {
  const response = await fetch(url, options)
  const result = await response.json()
  if (!response.ok) throw new Error(result.error || 'Không thể kết nối máy chủ.')
  return result
}

/** Thiết kế con dấu & chữ ký của tài khoản (dùng khi đóng dấu PDF). */
function SealProfileEditor({ sessionUser, onUserUpdated }) {
  const [saved, setSaved] = useState(null)
  const [design, setDesign] = useState(null)
  const [upload, setUpload] = useState({ file: null, removeBackground: true, recolor: false })
  const [uploadPreview, setUploadPreview] = useState(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const canSign = sessionUser.role === 'admin' || sessionUser.orgLevel === 'ceo'
    || (sessionUser.permissions || []).includes('pdf_signer')

  useEffect(() => {
    let active = true
    requestApi('/api/seal-profile')
      .then((profile) => {
        if (!active) return
        setSaved(profile)
        setDesign({
          style: profile.configured ? profile.style : 'round',
          orgText: profile.configured ? profile.orgText : '',
          centerText: profile.configured ? profile.centerText : (profile.centerText || ''),
          subText: profile.configured ? profile.subText : (profile.subText || sessionUser.name || ''),
          color: profile.color || '#c62828',
        })
      })
      .catch((requestError) => {
        if (active) setError(requestError.message)
      })
    return () => { active = false }
  }, [sessionUser.name])

  // Xử lý ảnh tải lên (xóa nền, đổi màu, cắt viền) mỗi khi đổi file/tùy chọn/màu.
  useEffect(() => {
    if (design?.style !== 'image' || !upload.file) return undefined
    let active = true
    let url = null
    processUploadedImage(upload.file, { ...upload, color: design.color })
      .then((result) => {
        if (!active) return
        url = URL.createObjectURL(result.blob)
        setUploadPreview({ ...result, url })
        setError('')
      })
      .catch((processError) => {
        if (active) {
          setUploadPreview(null)
          setError(processError.message)
        }
      })
    return () => {
      active = false
      if (url) URL.revokeObjectURL(url)
    }
  }, [design?.style, design?.color, upload])

  const svg = useMemo(() => (design && design.style !== 'image' ? buildSealSvg(design) : null), [design])

  if (!design) {
    return (
      <section className="account-form seal-profile-editor">
        <h2>Con dấu &amp; chữ ký</h2>
        {error ? <p className="meeting-error" role="alert">{error}</p> : <p>Đang tải mẫu con dấu...</p>}
      </section>
    )
  }

  const labels = fieldLabels[design.style] || fieldLabels.round
  const hints = placeholders[design.style] || placeholders.round
  const update = (key, value) => {
    setDesign((current) => ({ ...current, [key]: value }))
    setNotice('')
  }
  const previewUrl = design.style === 'image' ? uploadPreview?.url || (saved?.style === 'image' ? saved.imageUrl : null) : svg && svgDataUrl(svg.svg)

  const save = async (event) => {
    event.preventDefault()
    setBusy(true)
    setError('')
    setNotice('')
    try {
      let image
      if (design.style === 'image') {
        if (!uploadPreview) throw new Error('Chọn ảnh con dấu hoặc chữ ký để tải lên.')
        image = uploadPreview.blob
      } else {
        image = (await svgToPng(svg)).blob
      }
      const form = new FormData()
      for (const key of ['style', 'orgText', 'centerText', 'subText', 'color']) form.append(key, design[key] ?? '')
      form.append('image', image, 'seal.png')
      const result = await requestApi('/api/seal-profile', { method: 'POST', body: form })
      setSaved(result)
      onUserUpdated?.(result.account)
      setNotice('Đã lưu mẫu con dấu. Mẫu mới áp dụng cho các lần đóng dấu tiếp theo; tài liệu đã ký giữ nguyên dấu cũ.')
    } catch (saveError) {
      setError(saveError.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <form className="account-form seal-profile-editor" onSubmit={save}>
      <h2>Con dấu &amp; chữ ký</h2>
      <p className="seal-editor-note">
        Mẫu này được dùng khi bạn đóng dấu PDF. Ảnh dấu được lưu cố định vào từng tài liệu tại thời điểm ký.
        {!canSign && ' Tài khoản của bạn chưa có quyền ký PDF; mẫu sẽ dùng khi được cấp quyền.'}
      </p>
      {error && <p className="meeting-error" role="alert">{error}</p>}
      {notice && <p className="account-notice" role="status">{notice}</p>}

      <div className="seal-editor-grid">
        <div className="seal-editor-fields">
          <fieldset className="seal-style-picker">
            <legend>Kiểu dấu</legend>
            {sealStyles.map((style) => (
              <label key={style.value} className={design.style === style.value ? 'selected' : ''}>
                <input type="radio" name="seal-style" value={style.value} checked={design.style === style.value} onChange={() => update('style', style.value)} />
                <span>
                  <strong>{style.label}</strong>
                  <small>{style.hint}</small>
                </span>
              </label>
            ))}
          </fieldset>

          {design.style === 'image' ? (
            <div className="seal-upload">
              <label>
                <span>Ảnh dấu / chữ ký (PNG, JPG, WEBP – tối đa 8 MB)</span>
                <input type="file" accept="image/png,image/jpeg,image/webp" onChange={(event) => setUpload({ ...upload, file: event.target.files?.[0] || null })} />
              </label>
              <label className="seal-check">
                <input type="checkbox" checked={upload.removeBackground} onChange={(event) => setUpload({ ...upload, removeBackground: event.target.checked })} />
                <span>Xóa nền trắng (ảnh chụp/scan trên giấy)</span>
              </label>
              <label className="seal-check">
                <input type="checkbox" checked={upload.recolor} onChange={(event) => setUpload({ ...upload, recolor: event.target.checked })} />
                <span>Tô lại theo màu dấu bên dưới</span>
              </label>
            </div>
          ) : (
            <>
              <label>
                <span>{labels.org}</span>
                <input maxLength={120} value={design.orgText} placeholder={hints.org} onChange={(event) => update('orgText', event.target.value)} />
              </label>
              <label>
                <span>{labels.center}</span>
                <input maxLength={40} value={design.centerText} placeholder={hints.center} onChange={(event) => update('centerText', event.target.value)} />
              </label>
              <label>
                <span>{labels.sub}</span>
                <input maxLength={120} value={design.subText} placeholder={hints.sub} onChange={(event) => update('subText', event.target.value)} />
              </label>
            </>
          )}

          <div className="seal-color">
            <span>Màu mực</span>
            <div>
              {sealPalette.map((color) => (
                <button
                  key={color}
                  type="button"
                  className={design.color.toLowerCase() === color ? 'selected' : ''}
                  style={{ background: color }}
                  aria-label={`Màu ${color}`}
                  onClick={() => update('color', color)}
                />
              ))}
              <input type="color" aria-label="Chọn màu khác" value={design.color} onChange={(event) => update('color', event.target.value)} />
            </div>
          </div>
        </div>

        <div className="seal-preview">
          <span>Xem trước trên giấy</span>
          <div className="seal-paper">
            <div className="seal-paper-lines" aria-hidden="true"><i /><i /><i /><i /><i /></div>
            {previewUrl ? <img src={previewUrl} alt="Xem trước con dấu" /> : <p>Chọn ảnh để xem trước.</p>}
          </div>
          {saved?.imageUrl && (
            <div className="seal-current">
              <span>Mẫu đang dùng</span>
              <img src={saved.imageUrl} alt="Mẫu con dấu đang dùng" />
            </div>
          )}
        </div>
      </div>

      <button type="submit" disabled={busy}>{busy ? 'Đang lưu...' : 'Lưu mẫu con dấu'}</button>
    </form>
  )
}

export default SealProfileEditor
