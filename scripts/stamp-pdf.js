import { readFile, writeFile } from 'node:fs/promises'
import path from 'node:path'
import fontkit from '@pdf-lib/fontkit'
import { degrees, PDFDocument, rgb } from 'pdf-lib'
import { getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs'
import { fitRectangle, mapViewportImageToPdf, mapViewportRectToPdf } from '../server/features/pdf-seal/geometry.js'

let input = ''
for await (const chunk of process.stdin) input += chunk

const { sourcePath, outputPath, marks } = JSON.parse(input)
if (typeof sourcePath !== 'string' || typeof outputPath !== 'string' || !Array.isArray(marks)) {
  throw new TypeError('PDF stamping requires sourcePath, outputPath, and a marks array.')
}

const sourceBytes = await readFile(sourcePath)
const pdf = await PDFDocument.load(sourceBytes)
const pdfjsTask = getDocument({ data: new Uint8Array(sourceBytes), useSystemFonts: true })
const pdfjsDocument = await pdfjsTask.promise
try {
  pdf.registerFontkit(fontkit)
  const fontBytes = await readFile(path.join(
    process.cwd(),
    'node_modules',
    'dejavu-fonts-ttf',
    'ttf',
    'DejaVuSans.ttf',
  ))
  const font = await pdf.embedFont(fontBytes, { subset: true })
  const pages = pdf.getPages()
  const toRgb = (color) => rgb(
    Number.parseInt(color.slice(1, 3), 16) / 255,
    Number.parseInt(color.slice(3, 5), 16) / 255,
    Number.parseInt(color.slice(5, 7), 16) / 255,
  )

  for (const mark of marks) {
    const page = pages[mark.page - 1]
    if (!page) continue
    const viewport = (await pdfjsDocument.getPage(mark.page)).getViewport({ scale: 1.3 })

    if (mark.imagePath) {
      // Ảnh dấu (PNG trong suốt) chụp lại lúc ký: vẽ đúng khung, giữ tỉ lệ, đứng thẳng.
      const image = await pdf.embedPng(await readFile(mark.imagePath))
      const box = fitRectangle(mark, image.width / image.height)
      const placement = mapViewportImageToPdf(viewport, box)
      page.drawImage(image, {
        x: placement.x,
        y: placement.y,
        width: placement.width,
        height: placement.height,
        rotate: degrees(placement.rotate),
      })
      continue
    }

    const { x, y, width, height } = mapViewportRectToPdf(viewport, mark)
    const color = toRgb(mark.color)
    page.drawRectangle({
      x,
      y,
      width,
      height,
      rotate: degrees(-(mark.rotation || 0)),
      color,
      opacity: 0.82,
      borderColor: rgb(1, 1, 1),
      borderWidth: 2.5,
      borderOpacity: 0.95,
    })

    const sealSize = Math.min(height * 0.4, width * 0.36)
    const sealWidth = font.widthOfTextAtSize(mark.sealText, sealSize)
    page.drawText(mark.sealText, {
      x: x + (width - sealWidth) / 2,
      y: y + height * 0.48,
      size: sealSize,
      font,
      color: rgb(1, 1, 1),
      rotate: degrees(-(mark.rotation || 0)),
    })

    const signatureSize = Math.min(6, (width - 8) / Math.max(font.widthOfTextAtSize(mark.signatureName, 1), 1))
    const signatureWidth = font.widthOfTextAtSize(mark.signatureName, signatureSize)
    page.drawText(mark.signatureName, {
      x: x + Math.max(4, (width - signatureWidth) / 2),
      y: y + height * 0.22,
      size: signatureSize,
      font,
      color: rgb(1, 1, 1),
      rotate: degrees(-(mark.rotation || 0)),
    })
  }

  await writeFile(outputPath, await pdf.save())
} finally {
  await pdfjsTask.destroy()
}
