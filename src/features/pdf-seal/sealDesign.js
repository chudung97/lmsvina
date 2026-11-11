/**
 * Dựng ảnh con dấu/chữ ký trong trình duyệt. Thiết kế (SVG) được chuyển thành PNG nền
 * trong suốt rồi gửi lên máy chủ; ảnh tải lên cũng được chuẩn hóa thành PNG.
 */

export const sealStyles = [
  { value: 'round', label: 'Dấu tròn (pháp nhân)', hint: 'Tên đơn vị chạy vòng ngoài, chữ giữa và dòng phụ.' },
  { value: 'square', label: 'Dấu vuông', hint: 'Khung vuông viền đôi, ba dòng chữ.' },
  { value: 'rect', label: 'Dấu tên / chức danh', hint: 'Khung chữ nhật: chức danh, họ tên, dòng phụ.' },
  { value: 'signature', label: 'Chữ ký', hint: 'Họ tên kiểu viết tay, có dòng phụ bên dưới.' },
  { value: 'image', label: 'Tải ảnh dấu / chữ ký', hint: 'Dùng ảnh chụp hoặc scan con dấu, chữ ký thật.' },
]

export const sealPalette = ['#c62828', '#d81b60', '#1d4ed8', '#0f766e', '#111827', '#6d28d9']

const escapeXml = (value) => String(value ?? '')
  .replace(/&/g, '&amp;')
  .replace(/</g, '&lt;')
  .replace(/>/g, '&gt;')
  .replace(/"/g, '&quot;')

/** Cỡ chữ để dòng chữ vừa chiều rộng cho trước (ước lượng theo số ký tự). */
const fitFont = (text, maxWidth, base, ratio = 0.6) => {
  const length = Math.max(1, String(text || '').length)
  return Math.max(8, Math.min(base, maxWidth / (length * ratio)))
}

const FONT = "Arial, 'Segoe UI', Helvetica, sans-serif"

function roundSeal({ orgText, centerText, subText, color }) {
  const size = 300
  const ring = 104
  const circumference = 2 * Math.PI * ring
  const org = orgText.trim()
  const orgSize = fitFont(org, circumference * 0.9, 24, 0.62)
  const center = centerText.trim() || '★'
  const centerSize = fitFont(center, 150, 46, 0.62)
  const sub = subText.trim()
  const subSize = fitFont(sub, 140, 17)
  return {
    width: size,
    height: size,
    svg: `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 ${size} ${size}">
  <defs><path id="ring" d="M 150,${150 + ring} a ${ring},${ring} 0 1,1 0,-${ring * 2} a ${ring},${ring} 0 1,1 0,${ring * 2}"/></defs>
  <g fill="none" stroke="${color}">
    <circle cx="150" cy="150" r="143" stroke-width="7"/>
    <circle cx="150" cy="150" r="132" stroke-width="2"/>
    <circle cx="150" cy="150" r="82" stroke-width="3"/>
  </g>
  <g fill="${color}" font-family="${FONT}" font-weight="700" text-anchor="middle">
    ${org ? `<text font-size="${orgSize.toFixed(1)}" dominant-baseline="central"><textPath href="#ring" startOffset="50%" textLength="${(circumference * 0.9).toFixed(1)}" lengthAdjust="spacing">${escapeXml(org.toUpperCase())}</textPath></text>` : ''}
    <text x="150" y="${sub ? 146 : 152}" font-size="${centerSize.toFixed(1)}" dominant-baseline="central">${escapeXml(center.toUpperCase())}</text>
    ${sub ? `<text x="150" y="${146 + centerSize / 2 + subSize}" font-size="${subSize.toFixed(1)}" font-weight="600" dominant-baseline="central">${escapeXml(sub)}</text>` : ''}
  </g>
</svg>`,
  }
}

function squareSeal({ orgText, centerText, subText, color }) {
  const size = 300
  const lines = [
    { text: orgText.trim().toUpperCase(), base: 22, weight: 700 },
    { text: centerText.trim().toUpperCase(), base: 44, weight: 800 },
    { text: subText.trim(), base: 18, weight: 600 },
  ].filter((line) => line.text)
  const fonts = lines.map((line) => fitFont(line.text, 236, line.base))
  const total = fonts.reduce((sum, font) => sum + font * 1.3, 0)
  let y = 150 - total / 2
  const texts = lines.map((line, index) => {
    y += fonts[index] * 1.3
    return `<text x="150" y="${(y - fonts[index] * 0.3).toFixed(1)}" font-size="${fonts[index].toFixed(1)}" font-weight="${line.weight}">${escapeXml(line.text)}</text>`
  }).join('')
  return {
    width: size,
    height: size,
    svg: `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 ${size} ${size}">
  <g fill="none" stroke="${color}">
    <rect x="6" y="6" width="288" height="288" rx="14" stroke-width="8"/>
    <rect x="22" y="22" width="256" height="256" rx="8" stroke-width="2"/>
  </g>
  <g fill="${color}" font-family="${FONT}" text-anchor="middle">${texts}</g>
</svg>`,
  }
}

function rectSeal({ orgText, centerText, subText, color }) {
  const width = 460
  const height = 170
  const title = orgText.trim().toUpperCase()
  const name = centerText.trim()
  const sub = subText.trim()
  const titleSize = fitFont(title, 400, 24)
  const nameSize = fitFont(name, 410, 44, 0.58)
  const subSize = fitFont(sub, 400, 20)
  return {
    width,
    height,
    svg: `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}">
  <g fill="none" stroke="${color}">
    <rect x="5" y="5" width="${width - 10}" height="${height - 10}" rx="12" stroke-width="6"/>
    <rect x="17" y="17" width="${width - 34}" height="${height - 34}" rx="6" stroke-width="1.5"/>
  </g>
  <g fill="${color}" font-family="${FONT}" text-anchor="middle" dominant-baseline="central">
    ${title ? `<text x="${width / 2}" y="${sub ? 48 : 58}" font-size="${titleSize.toFixed(1)}" font-weight="700">${escapeXml(title)}</text>` : ''}
    <text x="${width / 2}" y="${title ? (sub ? 92 : 108) : (sub ? 72 : 85)}" font-size="${nameSize.toFixed(1)}" font-weight="800">${escapeXml(name)}</text>
    ${sub ? `<text x="${width / 2}" y="${title ? 134 : 124}" font-size="${subSize.toFixed(1)}" font-weight="600">${escapeXml(sub)}</text>` : ''}
  </g>
</svg>`,
  }
}

function signatureSeal({ centerText, subText, orgText, color }) {
  const width = 460
  const height = 200
  const name = centerText.trim()
  const sub = subText.trim()
  const top = orgText.trim()
  const nameSize = fitFont(name, 420, 64, 0.5)
  return {
    width,
    height,
    svg: `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}">
  <g fill="${color}" text-anchor="middle">
    ${top ? `<text x="${width / 2}" y="30" font-family="${FONT}" font-size="${fitFont(top, 420, 20).toFixed(1)}" font-weight="700">${escapeXml(top.toUpperCase())}</text>` : ''}
    <text x="${width / 2}" y="${top ? 112 : 100}" font-family="'Segoe Script', 'Brush Script MT', 'Lucida Handwriting', cursive" font-size="${nameSize.toFixed(1)}" font-style="italic">${escapeXml(name)}</text>
  </g>
  <line x1="40" y1="${top ? 138 : 128}" x2="${width - 40}" y2="${top ? 138 : 128}" stroke="${color}" stroke-width="2" opacity="0.7"/>
  ${sub ? `<text x="${width / 2}" y="${top ? 172 : 164}" fill="${color}" font-family="${FONT}" font-size="${fitFont(sub, 400, 22).toFixed(1)}" font-weight="700" text-anchor="middle">${escapeXml(sub)}</text>` : ''}
</svg>`,
  }
}

export function buildSealSvg(design) {
  const values = {
    orgText: design.orgText || '',
    centerText: design.centerText || '',
    subText: design.subText || '',
    color: design.color || '#c62828',
  }
  if (design.style === 'square') return squareSeal(values)
  if (design.style === 'rect') return rectSeal(values)
  if (design.style === 'signature') return signatureSeal(values)
  return roundSeal(values)
}

export function svgDataUrl(svg) {
  return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`
}

function loadImage(source) {
  return new Promise((resolve, reject) => {
    const image = new Image()
    image.onload = () => resolve(image)
    image.onerror = () => reject(new Error('Không đọc được ảnh.'))
    image.src = source
  })
}

function canvasToBlob(canvas) {
  return new Promise((resolve, reject) => {
    canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('Không tạo được ảnh PNG.'))), 'image/png')
  })
}

/** SVG thiết kế → PNG nền trong suốt (độ phân giải gấp `scale` lần để in rõ). */
export async function svgToPng({ svg, width, height }, scale = 3) {
  const image = await loadImage(svgDataUrl(svg))
  const canvas = document.createElement('canvas')
  canvas.width = Math.round(width * scale)
  canvas.height = Math.round(height * scale)
  canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height)
  return { blob: await canvasToBlob(canvas), width: canvas.width, height: canvas.height }
}

const hexToRgb = (hex) => [1, 3, 5].map((index) => Number.parseInt(hex.slice(index, index + 2), 16))

/**
 * Chuẩn hóa ảnh dấu/chữ ký tải lên: thu nhỏ tối đa 1200 px, tùy chọn xóa nền sáng,
 * tô lại theo màu dấu và cắt bỏ viền trống. Trả về PNG.
 */
export async function processUploadedImage(file, { removeBackground = true, recolor = false, color = '#c62828', threshold = 215 } = {}) {
  if (!/^image\/(png|jpeg|webp)$/.test(file.type)) throw new Error('Chỉ nhận ảnh PNG, JPG hoặc WEBP.')
  if (file.size > 8 * 1024 * 1024) throw new Error('Ảnh tối đa 8 MB.')
  const url = URL.createObjectURL(file)
  try {
    const image = await loadImage(url)
    const ratio = Math.min(1, 1200 / Math.max(image.naturalWidth, image.naturalHeight))
    const canvas = document.createElement('canvas')
    canvas.width = Math.max(1, Math.round(image.naturalWidth * ratio))
    canvas.height = Math.max(1, Math.round(image.naturalHeight * ratio))
    const context = canvas.getContext('2d', { willReadFrequently: true })
    context.drawImage(image, 0, 0, canvas.width, canvas.height)
    const pixels = context.getImageData(0, 0, canvas.width, canvas.height)
    const data = pixels.data
    const [red, green, blue] = hexToRgb(color)
    let minX = canvas.width
    let minY = canvas.height
    let maxX = -1
    let maxY = -1
    for (let index = 0; index < data.length; index += 4) {
      const lightness = (data[index] + data[index + 1] + data[index + 2]) / 3
      if (removeBackground) {
        // Nền sáng → trong suốt, vùng chuyển tiếp mờ dần để viền không bị răng cưa.
        const alpha = lightness >= threshold ? 0 : Math.min(1, (threshold - lightness) / 60)
        data[index + 3] = Math.round(data[index + 3] * alpha)
      }
      if (recolor && data[index + 3] > 0) {
        data[index] = red
        data[index + 1] = green
        data[index + 2] = blue
      }
      if (data[index + 3] > 24) {
        const pixel = index / 4
        const x = pixel % canvas.width
        const y = Math.floor(pixel / canvas.width)
        if (x < minX) minX = x
        if (x > maxX) maxX = x
        if (y < minY) minY = y
        if (y > maxY) maxY = y
      }
    }
    if (maxX < 0) throw new Error('Ảnh gần như trống sau khi xóa nền. Hãy tắt "Xóa nền trắng" hoặc dùng ảnh rõ hơn.')
    context.putImageData(pixels, 0, 0)
    const padding = 6
    const cropX = Math.max(0, minX - padding)
    const cropY = Math.max(0, minY - padding)
    const cropWidth = Math.min(canvas.width - cropX, maxX - minX + 1 + padding * 2)
    const cropHeight = Math.min(canvas.height - cropY, maxY - minY + 1 + padding * 2)
    const output = document.createElement('canvas')
    output.width = Math.max(16, cropWidth)
    output.height = Math.max(16, cropHeight)
    output.getContext('2d').drawImage(canvas, cropX, cropY, cropWidth, cropHeight, 0, 0, cropWidth, cropHeight)
    return { blob: await canvasToBlob(output), width: output.width, height: output.height }
  } finally {
    URL.revokeObjectURL(url)
  }
}
