import { useEffect, useRef, useState } from 'react'

/** Tỉ lệ toạ độ dấu: phải trùng với scripts/stamp-pdf.js (viewport scale 1.3). */
export const PDF_SCALE = 1.3

async function loadPdfjs() {
  const pdfjsLib = await import('pdfjs-dist')
  pdfjsLib.GlobalWorkerOptions.workerSrc = new URL('pdfjs-dist/build/pdf.worker.min.mjs', import.meta.url).toString()
  return pdfjsLib
}

/**
 * Hiển thị toàn bộ các trang của một PDF theo chiều dọc.
 * - renderOverlay(pageNumber, size): phần tử đặt chồng lên trang (con dấu).
 * - onVisiblePageChange(pageNumber): trang đang nằm giữa màn hình khi cuộn.
 * - pagesRef: mảng phần tử .pdf-page để cuộn tới trang / tính toạ độ kéo thả.
 */
function PdfPages({ file, renderOverlay, onVisiblePageChange, onLoaded, onPageDoubleClick, pagesRef, emptyMessage }) {
  const canvasesRef = useRef([])
  const onLoadedRef = useRef(onLoaded)
  const [pdf, setPdf] = useState(null)

  useEffect(() => {
    onLoadedRef.current = onLoaded
  }, [onLoaded])
  const [sizes, setSizes] = useState([])
  const [error, setError] = useState('')

  useEffect(() => {
    if (!file) return undefined
    let cancelled = false
    let task = null
    const load = async () => {
      try {
        const pdfjsLib = await loadPdfjs()
        task = pdfjsLib.getDocument({ data: await file.arrayBuffer() })
        const document = await task.promise
        const nextSizes = []
        for (let index = 1; index <= document.numPages; index += 1) {
          const viewport = (await document.getPage(index)).getViewport({ scale: PDF_SCALE })
          nextSizes.push({ width: viewport.width, height: viewport.height })
        }
        if (cancelled) return
        setError('')
        setPdf(document)
        setSizes(nextSizes)
        onLoadedRef.current?.(nextSizes)
      } catch (loadError) {
        if (!cancelled) setError(`Không thể hiển thị PDF: ${loadError.message}`)
      }
    }
    load()
    return () => {
      cancelled = true
      task?.destroy()
    }
  }, [file])

  useEffect(() => {
    if (!pdf || !sizes.length) return undefined
    let cancelled = false
    const ratio = Math.min(2, window.devicePixelRatio || 1)
    const render = async () => {
      for (let index = 0; index < sizes.length && !cancelled; index += 1) {
        const canvas = canvasesRef.current[index]
        if (!canvas) continue
        const page = await pdf.getPage(index + 1)
        const viewport = page.getViewport({ scale: PDF_SCALE * ratio })
        canvas.width = viewport.width
        canvas.height = viewport.height
        await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise
      }
    }
    render()
    return () => { cancelled = true }
  }, [pdf, sizes])

  useEffect(() => {
    if (!sizes.length || !onVisiblePageChange) return undefined
    let frame = 0
    const update = () => {
      frame = 0
      const middle = window.innerHeight / 2
      let best = 1
      let bestDistance = Infinity
      ;(pagesRef?.current || []).forEach((element, index) => {
        if (!element) return
        const rect = element.getBoundingClientRect()
        const distance = rect.top <= middle && rect.bottom >= middle ? 0 : Math.min(Math.abs(rect.top - middle), Math.abs(rect.bottom - middle))
        if (distance < bestDistance) {
          bestDistance = distance
          best = index + 1
        }
      })
      onVisiblePageChange(best)
    }
    const schedule = () => {
      if (!frame) frame = window.requestAnimationFrame(update)
    }
    window.addEventListener('scroll', schedule, { capture: true, passive: true })
    window.addEventListener('resize', schedule)
    schedule()
    return () => {
      window.removeEventListener('scroll', schedule, { capture: true })
      window.removeEventListener('resize', schedule)
      if (frame) window.cancelAnimationFrame(frame)
    }
  }, [sizes, onVisiblePageChange, pagesRef])

  if (!file) return <div className="pdf-empty">{emptyMessage || 'Chưa có tài liệu.'}</div>
  if (error) return <p role="alert" className="app-error">{error}</p>
  if (!sizes.length) return <div className="pdf-empty">Đang tải PDF...</div>

  return (
    <div className="pdf-document-stack">
      {sizes.map((size, index) => {
        const pageNumber = index + 1
        return (
          <div
            key={`page-${pageNumber}`}
            ref={(element) => {
              if (pagesRef) {
                if (!pagesRef.current) pagesRef.current = []
                pagesRef.current[index] = element
              }
            }}
            className="pdf-page pdf-document-page"
            data-page-number={pageNumber}
            style={{ width: '100%', maxWidth: size.width, aspectRatio: `${size.width} / ${size.height}` }}
            onDoubleClick={onPageDoubleClick ? (event) => {
              const rect = event.currentTarget.getBoundingClientRect()
              const scale = rect.width / size.width
              onPageDoubleClick(pageNumber, {
                x: (event.clientX - rect.left) / scale,
                y: (event.clientY - rect.top) / scale,
              })
            } : undefined}
          >
            <canvas ref={(element) => { canvasesRef.current[index] = element }} className="pdf-canvas" />
            <span className="pdf-page-number">Trang {pageNumber}/{sizes.length}</span>
            {renderOverlay?.(pageNumber, size)}
          </div>
        )
      })}
    </div>
  )
}

export default PdfPages
