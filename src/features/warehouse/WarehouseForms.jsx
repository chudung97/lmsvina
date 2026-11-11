import { useEffect, useMemo, useState } from 'react'
import { SearchableSelect } from '../../components/SearchableControls'
import {
  dueStatuses,
  formatDate,
  formatNumber,
  purposeLabels,
  receiptTypeLabels,
  requestApi,
  runConfirmation,
  sendJson,
  todayInVietnam,
} from './warehouseApi'
import { Messages, WarehouseModal } from './WarehouseUi'

function useCatalog(withEmployees) {
  const [catalog, setCatalog] = useState(null)
  const [error, setError] = useState('')

  useEffect(() => {
    let active = true
    Promise.all([
      requestApi('/api/kho/items'),
      requestApi('/api/kho/stock'),
      withEmployees ? requestApi('/api/kho/employees') : Promise.resolve(null),
    ])
      .then(([items, stock, employees]) => {
        if (!active) return
        setCatalog({
          items,
          stock: Object.fromEntries(stock.rows.map((row) => [row.itemId, row.stock])),
          employees: employees?.rows ?? [],
        })
      })
      .catch((requestError) => {
        if (active) setError(requestError.message)
      })
    return () => { active = false }
  }, [withEmployees])

  return [catalog, error]
}

function itemOption(item) {
  return {
    value: String(item.id),
    label: `${item.code} · ${item.name}${item.size ? ` (${item.size})` : ''}`,
    searchText: `${item.group} ${item.supplier}`,
  }
}

export function IssueForm({ draft, settings, onClose, onSaved }) {
  const [catalog, loadError] = useCatalog(true)
  const [userId, setUserId] = useState(draft?.userId ? String(draft.userId) : '')
  const [purpose, setPurpose] = useState(draft?.purpose || 'periodic')
  const [giver, setGiver] = useState(settings?.giverName || '')
  const [note, setNote] = useState('')
  const [lines, setLines] = useState(draft?.lines?.length
    ? draft.lines.map((line) => ({ itemId: String(line.itemId), qty: String(line.qty || 1) }))
    : [{ itemId: '', qty: '1' }])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  const employees = useMemo(() => (catalog?.employees || []).map((employee) => ({
    value: employee.id,
    label: `${employee.code ? `${employee.code} · ` : ''}${employee.name}`,
    searchText: employee.departmentName,
    department: employee.departmentName || null,
  })), [catalog])
  const itemOptions = useMemo(() => (catalog?.items || []).filter((item) => item.active).map(itemOption), [catalog])
  const employee = catalog?.employees.find((person) => person.id === userId)

  const hint = (line) => {
    const item = catalog?.items.find((candidate) => String(candidate.id) === line.itemId)
    if (!item) return ''
    const stock = catalog.stock[item.id] ?? 0
    const qty = Number(line.qty)
    const parts = [`Tồn ${formatNumber(stock)} ${item.unit}`]
    if (qty > stock) parts.push('⚠ không đủ tồn')
    if (item.perPerson > 0 && qty > item.perPerson) parts.push(`⚠ vượt định mức ${item.perPerson}`)
    return parts.join(' · ')
  }

  const suggest = async () => {
    if (!userId) {
      setError('Chọn nhân viên trước.')
      return
    }
    setError('')
    try {
      const schedule = await requestApi('/api/kho/schedule')
      const rows = schedule.rows.filter((row) => String(row.userId) === userId && row.itemId && dueStatuses.includes(row.status))
      if (!rows.length) {
        setError('Nhân viên này chưa có mặt hàng đến kỳ.')
        return
      }
      setLines(rows.map((row) => ({ itemId: String(row.itemId), qty: String(row.qty || 1) })))
    } catch (requestError) {
      setError(requestError.message)
    }
  }

  const save = async (confirm) => {
    const items = lines.filter((line) => line.itemId).map((line) => ({ itemId: Number(line.itemId), qty: Number(line.qty) }))
    if (!userId) {
      setError('Chọn người nhận.')
      return
    }
    if (!items.length || items.some((line) => !Number.isInteger(line.qty) || line.qty < 1)) {
      setError('Cần ít nhất một mặt hàng, số lượng là số nguyên lớn hơn 0.')
      return
    }
    setBusy(true)
    setError('')
    try {
      const result = await sendJson('/api/kho/issues', 'POST', { userId: Number(userId), purpose, giver, note, items, confirm })
      const message = confirm && result.confirmation
        ? `Phiếu ${result.voucher}: ${await runConfirmation(result.confirmation)}`
        : `Đã lưu phiếu nháp ${result.voucher}.`
      onSaved(message)
    } catch (requestError) {
      setError(requestError.message)
      setBusy(false)
    }
  }

  return (
    <WarehouseModal title="Lập phiếu cấp phát" onClose={onClose} wide>
      <Messages error={error || loadError} />
      {!catalog && !loadError && <p className="warehouse-muted">Đang tải danh mục...</p>}
      {catalog && (
        <>
          <div className="warehouse-form-grid">
            <label>
              <span>Người nhận *</span>
              <SearchableSelect label="Người nhận" value={userId} options={employees} onChange={setUserId} required />
            </label>
            <label>
              <span>Phòng ban</span>
              <input readOnly value={employee?.departmentName || ''} />
            </label>
            <label>
              <span>Mục đích</span>
              <select value={purpose} onChange={(event) => setPurpose(event.target.value)}>
                {Object.entries(purposeLabels).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
              </select>
            </label>
            <label>
              <span>Người giao</span>
              <input value={giver} maxLength={120} onChange={(event) => setGiver(event.target.value)} />
            </label>
            <label className="span-2">
              <span>Ghi chú</span>
              <input value={note} maxLength={500} onChange={(event) => setNote(event.target.value)} />
            </label>
          </div>
          <div className="warehouse-toolbar">
            <strong>Mặt hàng (nhiều mặt hàng trong một phiếu)</strong>
            <button type="button" className="warehouse-button small" onClick={suggest}>Gợi ý theo lịch cấp phát</button>
          </div>
          <div className="warehouse-lines">
            {lines.map((line, index) => (
              <div className="warehouse-line" key={index}>
                <SearchableSelect
                  label={`Mặt hàng ${index + 1}`}
                  value={line.itemId}
                  options={itemOptions}
                  placeholder="Tìm mã hoặc tên hàng..."
                  onChange={(value) => setLines(lines.map((current, position) => position === index ? { ...current, itemId: value } : current))}
                />
                <input
                  type="number"
                  min="1"
                  aria-label={`Số lượng dòng ${index + 1}`}
                  value={line.qty}
                  onChange={(event) => setLines(lines.map((current, position) => position === index ? { ...current, qty: event.target.value } : current))}
                />
                <span className="warehouse-muted">{hint(line)}</span>
                <button
                  type="button"
                  className="warehouse-button small"
                  aria-label={`Xóa dòng ${index + 1}`}
                  onClick={() => setLines(lines.length > 1 ? lines.filter((_, position) => position !== index) : [{ itemId: '', qty: '1' }])}
                >
                  ✕
                </button>
              </div>
            ))}
          </div>
          <button type="button" className="warehouse-button small" onClick={() => setLines([...lines, { itemId: '', qty: '1' }])}>
            + Thêm dòng
          </button>
          <div className="warehouse-actions">
            <button type="button" className="warehouse-button" disabled={busy} onClick={() => save(false)}>Lưu nháp</button>
            <button type="button" className="warehouse-button primary" disabled={busy} onClick={() => save(true)}>
              Lưu &amp; XÁC NHẬN (ghi ngày giờ thật)
            </button>
            <button type="button" className="warehouse-button" onClick={onClose}>Đóng</button>
          </div>
          <p className="warehouse-muted">Ngày/giờ giao dịch chỉ được ghi khi xác nhận và là thời điểm thật của máy chủ. Phiếu nháp chưa trừ tồn.</p>
        </>
      )}
    </WarehouseModal>
  )
}

export function ReceiptForm({ draft, onClose, onSaved }) {
  const [catalog, loadError] = useCatalog(false)
  const [orders, setOrders] = useState([])
  const [form, setForm] = useState({
    itemId: draft?.itemId ? String(draft.itemId) : '',
    qty: draft?.qty ? String(draft.qty) : '',
    type: draft?.type || 'purchase',
    poId: draft?.poId ? String(draft.poId) : '',
    invoice: '',
    unitPrice: '',
    date: todayInVietnam(),
    note: '',
  })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    let active = true
    requestApi('/api/kho/orders')
      .then((result) => {
        if (active) setOrders(result.rows.filter((order) => ['approved', 'ordered', 'partial'].includes(order.status)))
      })
      .catch(() => {})
    return () => { active = false }
  }, [])

  const itemOptions = useMemo(() => (catalog?.items || []).map(itemOption), [catalog])
  const update = (key, value) => setForm((current) => ({ ...current, [key]: value }))
  const matchingOrders = orders.filter((order) => !form.itemId || String(order.itemId) === form.itemId)
  const needsNote = ['damaged', 'adjust_plus', 'adjust_minus', 'supplier_return'].includes(form.type)

  const submit = async (event) => {
    event.preventDefault()
    setBusy(true)
    setError('')
    const body = {
      itemId: Number(form.itemId),
      qty: Number(form.qty),
      type: form.type,
      poId: form.type === 'purchase' && form.poId ? Number(form.poId) : null,
      invoice: form.invoice,
      unitPrice: form.unitPrice === '' ? null : Number(form.unitPrice),
      date: form.date,
      note: form.note,
    }
    try {
      try {
        await sendJson('/api/kho/receipts', 'POST', body)
      } catch (requestError) {
        if (requestError.body?.needsConfirm && window.confirm(`${requestError.message}`)) {
          await sendJson('/api/kho/receipts', 'POST', { ...body, allowNegative: true })
        } else {
          throw requestError
        }
      }
      onSaved('Đã ghi nhận nhập kho / điều chỉnh tồn.')
    } catch (requestError) {
      setError(requestError.message)
      setBusy(false)
    }
  }

  return (
    <WarehouseModal title="Nhập kho / điều chỉnh tồn" onClose={onClose}>
      <Messages error={error || loadError} />
      {catalog && (
        <form onSubmit={submit}>
          <div className="warehouse-form-grid">
            <label className="span-2">
              <span>Mã hàng *</span>
              <SearchableSelect
                label="Mã hàng nhập"
                value={form.itemId}
                options={itemOptions}
                required
                placeholder="Tìm mã hoặc tên hàng..."
                onChange={(value) => setForm((current) => ({ ...current, itemId: value, poId: '' }))}
              />
            </label>
            <label>
              <span>Số lượng (luôn dương) *</span>
              <input type="number" min="1" required value={form.qty} onChange={(event) => update('qty', event.target.value)} />
            </label>
            <label>
              <span>Loại giao dịch</span>
              <select value={form.type} onChange={(event) => update('type', event.target.value)}>
                {Object.entries(receiptTypeLabels).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
              </select>
            </label>
            {form.type === 'purchase' && (
              <label className="span-2">
                <span>Mã PO (nếu nhận theo đơn đặt)</span>
                <select value={form.poId} onChange={(event) => update('poId', event.target.value)}>
                  <option value="">(không)</option>
                  {matchingOrders.map((order) => (
                    <option key={order.id} value={order.id}>
                      {order.poNo} – {order.itemCode} – còn {order.remaining}
                    </option>
                  ))}
                </select>
              </label>
            )}
            <label>
              <span>Số hóa đơn/chứng từ</span>
              <input maxLength={60} value={form.invoice} onChange={(event) => update('invoice', event.target.value)} />
            </label>
            <label>
              <span>Đơn giá thực tế (nếu khác danh mục)</span>
              <input type="number" min="0" step="any" value={form.unitPrice} onChange={(event) => update('unitPrice', event.target.value)} />
            </label>
            <label>
              <span>Ngày nhập</span>
              <input type="date" required max={todayInVietnam()} value={form.date} onChange={(event) => update('date', event.target.value)} />
            </label>
            <label className="span-2">
              <span>Ghi chú{needsNote ? ' * (bắt buộc với điều chỉnh/hư hỏng/trả NCC)' : ''}</span>
              <input maxLength={500} required={needsNote} value={form.note} onChange={(event) => update('note', event.target.value)} />
            </label>
          </div>
          <div className="warehouse-actions">
            <button type="submit" className="warehouse-button primary" disabled={busy}>Lưu</button>
            <button type="button" className="warehouse-button" onClick={onClose}>Đóng</button>
          </div>
          <p className="warehouse-muted">Ngày chứng từ: {formatDate(form.date)}. Không xóa giao dịch – chỉ hủy kèm lý do để còn dấu vết.</p>
        </form>
      )}
    </WarehouseModal>
  )
}
