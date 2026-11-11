export async function requestApi(url, options = {}) {
  const response = await fetch(url, options)
  const result = await response.json()
  if (!response.ok) {
    const error = new Error(result.error || 'Không thể kết nối máy chủ.')
    error.status = response.status
    error.body = result
    throw error
  }
  return result
}

export function sendJson(url, method, body) {
  return requestApi(url, {
    method,
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body ?? {}),
  })
}

function describeLines(lines) {
  return lines.map((line) => `${line.voucher} · ${line.itemCode} · ${line.userName}: ${warningLabels[line.warning] ?? line.warning}`)
}

/**
 * Hoàn tất kết quả xác nhận cấp phát: cảnh báo đỏ bị chặn; cảnh báo vàng (vượt định mức,
 * trùng kỳ) hỏi lại người dùng rồi gửi xác nhận kèm acknowledge.
 */
export async function runConfirmation(confirmation) {
  let confirmed = confirmation.confirmed.length
  const blocked = [...confirmation.blocked]
  const pending = confirmation.needsAcknowledge
  if (pending.length) {
    const accepted = window.confirm(
      `Các dòng sau có cảnh báo:\n${describeLines(pending).join('\n')}\n\nVẫn xác nhận cấp phát? (Ghi chú sẽ lưu vết cảnh báo)`,
    )
    if (accepted) {
      const second = await sendJson('/api/kho/issues/confirm', 'POST', {
        ids: pending.map((line) => line.id),
        acknowledge: true,
      })
      confirmed += second.confirmed.length
      blocked.push(...second.blocked)
    } else {
      blocked.push(...pending)
    }
  }
  const parts = [`Đã xác nhận ${confirmed} dòng`]
  if (blocked.length) parts.push(`chưa xác nhận ${blocked.length}: ${describeLines(blocked).slice(0, 5).join('; ')}`)
  return parts.join(' · ')
}

export const receiptTypeLabels = {
  purchase: 'Nhập mua',
  employee_return: 'Hàng trả lại từ NV',
  adjust_plus: 'Điều chỉnh kiểm kê (+)',
  supplier_return: 'Trả NCC',
  damaged: 'Hàng hư hỏng',
  adjust_minus: 'Điều chỉnh kiểm kê (-)',
}

export const purposeLabels = {
  new_hire: 'Cấp mới',
  periodic: 'Cấp định kỳ',
  supplement: 'Cấp bổ sung',
  replacement: 'Thay thế hỏng/đổi size',
  department_supply: 'Cấp VPP/vật tư phòng ban',
}

export const issueStatusLabels = {
  draft: 'Nháp',
  confirmed: 'Đã xác nhận',
  shortage: 'Thiếu hàng',
  cancelled: 'Đã hủy',
}

export const receiptStatusLabels = { received: 'Đã nhận', cancelled: 'Đã hủy' }

export const orderStatusLabels = {
  not_proposed: 'Chưa đề xuất',
  pending_approval: 'Chờ duyệt',
  approved: 'Đã duyệt',
  ordered: 'Đã đặt hàng',
  partial: 'Đã nhận một phần',
  completed: 'Hoàn tất',
  cancelled: 'Đã hủy',
}

export const orderTypeLabels = { periodic: 'Định kỳ', urgent: 'Khẩn cấp' }

export const stockStatusLabels = {
  negative: 'ÂM KHO',
  out: 'HẾT HÀNG',
  below_min: 'DƯỚI TỐI THIỂU',
  low: 'SẮP HẾT',
  safe: 'AN TOÀN',
  over: 'TỒN DƯ',
}

export const scheduleStatusLabels = {
  overdue: 'Quá hạn',
  due_today: 'Đến hạn hôm nay',
  due_soon: 'Sắp đến hạn',
  waiting_stock: 'Chờ hàng',
  not_due: 'Chưa đến hạn',
  missing_item: 'Thiếu mã/size',
  not_configured: 'Chưa cấu hình',
}

export const priorityLabels = { urgent: 'Khẩn cấp', high: 'Cao', medium: 'Trung bình', none: 'Không cần đặt' }

export const warningLabels = {
  ok: 'OK',
  invalid_ref: 'Mã hàng/NV không hợp lệ',
  invalid_item: 'Mã hàng không hợp lệ',
  qty_invalid: 'Số lượng ≤ 0',
  cancelled: 'Đã hủy',
  missing_date: 'Thiếu ngày giao dịch',
  duplicate: 'Giao dịch trùng',
  over_stock: 'VƯỢT TỒN KHO',
  over_quota: 'Vượt định mức',
  cycle_overlap: 'Trùng kỳ cấp phát',
  po_missing: 'Mã PO không tồn tại',
  over_po: 'Nhận vượt SL đặt',
  dup_invoice: 'Nghi trùng chứng từ',
  max_lt_min: 'Tối đa < Tối thiểu',
  missing_size: 'Thiếu size',
}

export const staffRoleLabels = { manager: 'Quản lý kho', keeper: 'Thủ kho', viewer: 'Chỉ xem' }

export const sizeTypeLabels = { shirt: 'Áo', pants: 'Quần', shoes: 'Giày', none: 'Không' }

export const dueStatuses = ['overdue', 'due_today', 'due_soon', 'waiting_stock']

const tones = {
  red: ['negative', 'out', 'below_min', 'overdue', 'due_today', 'waiting_stock', 'urgent', 'shortage', 'missing_item',
    'invalid_ref', 'invalid_item', 'qty_invalid', 'missing_date', 'duplicate', 'over_stock', 'po_missing', 'over_po', 'max_lt_min'],
  yellow: ['low', 'due_soon', 'high', 'draft', 'pending_approval', 'approved', 'over_quota', 'cycle_overlap', 'dup_invoice',
    'missing_size', 'not_proposed'],
  green: ['safe', 'not_due', 'confirmed', 'received', 'completed', 'ok'],
  blue: ['over', 'medium', 'ordered', 'partial'],
}

export function toneOf(key) {
  return Object.keys(tones).find((tone) => tones[tone].includes(key)) || 'gray'
}

export function formatNumber(value, digits = 0) {
  if (value === null || value === undefined || value === '' || Number.isNaN(Number(value))) return ''
  return Number(value).toLocaleString('vi-VN', { maximumFractionDigits: digits, minimumFractionDigits: 0 })
}

export function formatDate(value) {
  if (!value) return ''
  const [year, month, day] = String(value).slice(0, 10).split('-')
  return day ? `${day}/${month}/${year}` : value
}

export function todayInVietnam() {
  return new Date(Date.now() + 7 * 60 * 60 * 1000).toISOString().slice(0, 10)
}

export function addDays(date, days) {
  const value = new Date(`${date}T00:00:00Z`)
  value.setUTCDate(value.getUTCDate() + days)
  return value.toISOString().slice(0, 10)
}

function csvCell(value) {
  const text = String(value ?? '')
  return /[",\n;]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text
}

/** Xuất CSV UTF-8 có BOM để Excel đọc đúng tiếng Việt. */
export function downloadCsv(filename, columns, rows) {
  const exportable = columns.filter((column) => column.label && column.csv !== false)
  const lines = [exportable.map((column) => csvCell(column.label)).join(',')]
  for (const row of rows) {
    lines.push(exportable.map((column) => csvCell(
      typeof column.csv === 'function' ? column.csv(row) : column.value ? column.value(row) : row[column.key],
    )).join(','))
  }
  const blob = new Blob([`﻿${lines.join('\r\n')}`], { type: 'text/csv;charset=utf-8' })
  const link = document.createElement('a')
  link.href = URL.createObjectURL(blob)
  link.download = filename
  document.body.appendChild(link)
  link.click()
  window.setTimeout(() => {
    URL.revokeObjectURL(link.href)
    link.remove()
  }, 500)
}

export function parseCsv(text) {
  const source = text.replace(/^﻿/, '')
  const rows = []
  let row = []
  let cell = ''
  let quoted = false
  for (let index = 0; index < source.length; index += 1) {
    const char = source[index]
    if (quoted) {
      if (char === '"' && source[index + 1] === '"') {
        cell += '"'
        index += 1
      } else if (char === '"') {
        quoted = false
      } else {
        cell += char
      }
    } else if (char === '"') {
      quoted = true
    } else if (char === ',' || char === ';') {
      row.push(cell)
      cell = ''
    } else if (char === '\n') {
      row.push(cell)
      rows.push(row)
      row = []
      cell = ''
    } else if (char !== '\r') {
      cell += char
    }
  }
  if (cell !== '' || row.length) {
    row.push(cell)
    rows.push(row)
  }
  return rows.filter((cells) => cells.some((value) => value.trim() !== ''))
}

/** Chọn file CSV và ánh xạ tiêu đề cột (label) sang key theo danh sách columns. */
export function readCsvFile(columns) {
  return new Promise((resolve, reject) => {
    const input = document.createElement('input')
    input.type = 'file'
    input.accept = '.csv,text/csv'
    input.onchange = () => {
      const file = input.files?.[0]
      if (!file) return
      const reader = new FileReader()
      reader.onload = () => {
        const [header, ...body] = parseCsv(String(reader.result))
        if (!header) {
          reject(new Error('File trống.'))
          return
        }
        const mapping = header.map((title) => columns.find((column) => column.label === title.trim())?.key)
        if (!mapping.some(Boolean)) {
          reject(new Error('Không nhận ra cột nào – hãy dùng file "Xuất CSV" làm mẫu.'))
          return
        }
        resolve(body.map((cells) => Object.fromEntries(mapping
          .map((key, index) => [key, (cells[index] ?? '').trim()])
          .filter(([key]) => key))))
      }
      reader.onerror = () => reject(new Error('Không đọc được file.'))
      reader.readAsText(file, 'utf-8')
    }
    input.click()
  })
}
