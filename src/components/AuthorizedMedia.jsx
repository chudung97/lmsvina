import { useState } from 'react'
import { downloadAuthorizedFile, useAuthorizedUrl } from '../authorized-files'

/** Ảnh có thể nằm sau API cần token; hiển thị sau khi tải xong. */
export function AuthImage({ src, alt = '', ...props }) {
  const resolved = useAuthorizedUrl(src)
  if (!resolved) return null
  return <img src={resolved} alt={alt} {...props} />
}

/** Liên kết tải file từ API (gửi kèm token). */
export function AuthDownloadLink({ href, filename, children, ...props }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  const download = async (event) => {
    event.preventDefault()
    if (busy) return
    setBusy(true)
    setError('')
    try {
      await downloadAuthorizedFile(href, filename)
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <a href={href} onClick={download} aria-busy={busy} {...props}>{children}</a>
      {error && <span role="alert" className="meeting-error">{error}</span>}
    </>
  )
}
