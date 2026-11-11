import assert from 'node:assert/strict'
import test from 'node:test'
import { getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs'
import { degrees, PDFDocument } from 'pdf-lib'
import { fitRectangle, mapViewportImageToPdf, mapViewportRectToPdf } from '../server/features/pdf-seal/geometry.js'

const scale = 1.3
const sourceRectangle = { x: 80, y: 120, width: 50, height: 30 }

for (const rotation of [0, 90, 180, 270]) {
  test(`maps seal coordinates for a page rotated ${rotation} degrees`, async () => {
    const source = await PDFDocument.create()
    const sourcePage = source.addPage([400, 600])
    sourcePage.setMediaBox(-20, 10, 400, 600)
    sourcePage.setCropBox(30, 40, 250, 400)
    sourcePage.setRotation(degrees(rotation))

    const loadingTask = getDocument({
      data: new Uint8Array(await source.save()),
      useSystemFonts: true,
    })

    try {
      const pdf = await loadingTask.promise
      const page = await pdf.getPage(1)
      const viewport = page.getViewport({ scale })
      const corners = [
        viewport.convertToViewportPoint(sourceRectangle.x, sourceRectangle.y),
        viewport.convertToViewportPoint(sourceRectangle.x + sourceRectangle.width, sourceRectangle.y),
        viewport.convertToViewportPoint(sourceRectangle.x, sourceRectangle.y + sourceRectangle.height),
        viewport.convertToViewportPoint(
          sourceRectangle.x + sourceRectangle.width,
          sourceRectangle.y + sourceRectangle.height,
        ),
      ]
      const xValues = corners.map(([x]) => x)
      const yValues = corners.map(([, y]) => y)
      const displayRectangle = {
        x: Math.min(...xValues),
        y: Math.min(...yValues),
        width: Math.max(...xValues) - Math.min(...xValues),
        height: Math.max(...yValues) - Math.min(...yValues),
      }
      const mapped = mapViewportRectToPdf(viewport, displayRectangle)

      for (const key of Object.keys(sourceRectangle)) {
        assert.ok(Math.abs(mapped[key] - sourceRectangle[key]) < 0.001, `${key}: ${mapped[key]}`)
      }

      // Ảnh dấu: góc trên-trái và dưới-phải của ảnh đã xoay phải trùng khung trên màn hình.
      const placement = mapViewportImageToPdf(viewport, displayRectangle)
      const radians = (placement.rotate * Math.PI) / 180
      const topLeft = [
        placement.x - placement.height * Math.sin(radians),
        placement.y + placement.height * Math.cos(radians),
      ]
      const bottomRight = [
        placement.x + placement.width * Math.cos(radians),
        placement.y + placement.width * Math.sin(radians),
      ]
      const [tlX, tlY] = viewport.convertToViewportPoint(...topLeft)
      const [brX, brY] = viewport.convertToViewportPoint(...bottomRight)
      assert.ok(Math.abs(tlX - displayRectangle.x) < 0.001 && Math.abs(tlY - displayRectangle.y) < 0.001, `top-left ${tlX},${tlY}`)
      assert.ok(Math.abs(brX - (displayRectangle.x + displayRectangle.width)) < 0.001
        && Math.abs(brY - (displayRectangle.y + displayRectangle.height)) < 0.001, `bottom-right ${brX},${brY}`)
      assert.equal(((placement.rotate % 360) + 360) % 360, rotation)
    } finally {
      await loadingTask.destroy()
    }
  })
}

test('fits a seal image inside its box without distortion', () => {
  assert.deepEqual(fitRectangle({ x: 10, y: 20, width: 200, height: 100 }, 1), { x: 60, y: 20, width: 100, height: 100 })
  assert.deepEqual(fitRectangle({ x: 0, y: 0, width: 100, height: 100 }, 2), { x: 0, y: 25, width: 100, height: 50 })
})
