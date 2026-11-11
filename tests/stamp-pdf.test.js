import assert from 'node:assert/strict'
import { execFile } from 'node:child_process'
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
import test from 'node:test'
import { deflateSync } from 'node:zlib'
import { PDFDocument, PDFName } from 'pdf-lib'

function crc32(buffer) {
  let crc = 0xffffffff
  for (const byte of buffer) {
    crc ^= byte
    for (let bit = 0; bit < 8; bit += 1) crc = (crc >>> 1) ^ (0xedb88320 & -(crc & 1))
  }
  return (crc ^ 0xffffffff) >>> 0
}

function pngChunk(type, data) {
  const length = Buffer.alloc(4)
  length.writeUInt32BE(data.length)
  const body = Buffer.concat([Buffer.from(type, 'ascii'), data])
  const crc = Buffer.alloc(4)
  crc.writeUInt32BE(crc32(body))
  return Buffer.concat([length, body, crc])
}

/** PNG RGBA nhỏ màu đỏ bán trong suốt, đủ để pdf-lib nhúng. */
function redPng(width, height) {
  const header = Buffer.alloc(13)
  header.writeUInt32BE(width, 0)
  header.writeUInt32BE(height, 4)
  header.set([8, 6, 0, 0, 0], 8)
  const rows = []
  for (let y = 0; y < height; y += 1) {
    rows.push(Buffer.from([0, ...Array.from({ length: width }, () => [200, 30, 30, 200]).flat()]))
  }
  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    pngChunk('IHDR', header),
    pngChunk('IDAT', deflateSync(Buffer.concat(rows))),
    pngChunk('IEND', Buffer.alloc(0)),
  ])
}

function runStamp(input) {
  return new Promise((resolve, reject) => {
    const child = execFile(process.execPath, ['scripts/stamp-pdf.js'], { cwd: process.cwd() }, (error, stdout, stderr) => {
      if (error) reject(new Error(stderr || error.message))
      else resolve(stdout)
    })
    child.stdin.end(JSON.stringify(input))
  })
}

const imageCount = (page) => {
  const xObjects = page.node.Resources()?.lookup(PDFName.of('XObject'))
  return xObjects ? xObjects.keys().length : 0
}

test('stamps a seal image only on the page it was placed on', async () => {
  const directory = await mkdtemp(path.join(tmpdir(), 'stamp-pdf-'))
  try {
    const source = await PDFDocument.create()
    for (let index = 0; index < 5; index += 1) source.addPage([595, 842])
    const sourcePath = path.join(directory, 'source.pdf')
    const imagePath = path.join(directory, 'seal.png')
    const outputPath = path.join(directory, 'stamped.pdf')
    await writeFile(sourcePath, await source.save())
    await writeFile(imagePath, redPng(40, 40))

    await runStamp({
      sourcePath,
      outputPath,
      marks: [{
        page: 5, x: 500, y: 800, width: 130, height: 130, rotation: 0,
        sealText: 'ABC', signatureName: 'Test', color: '#c62828', imagePath,
      }],
    })

    const stamped = await PDFDocument.load(await readFile(outputPath))
    const counts = stamped.getPages().map(imageCount)
    assert.deepEqual(counts, [0, 0, 0, 0, 1])
  } finally {
    await rm(directory, { recursive: true, force: true })
  }
})
