import { useMemo, useState } from 'react'
import { SearchableSelect } from '../../components/SearchableControls'
import {
  dueStatuses,
  downloadCsv,
  formatDate,
  formatNumber,
  orderStatusLabels,
  orderTypeLabels,
  priorityLabels,
  requestApi,
  scheduleStatusLabels,
  sendJson,
  stockStatusLabels,
} from './warehouseApi'
import { Badge, Messages, WarehouseModal, WarehouseTable } from './WarehouseUi'
import { useKhoResource } from './useKhoResource'

const groupsOf = (rows) => [...new Set(rows.map((row) => row.itemGroup).filter(Boolean))].sort()

export function WarehouseStock() {
  const [filters, setFilters] = useState({ group: '', status: '' })
  const { data, error, loading } = useKhoResource('/api/kho/stock')
  const rows = (data?.rows || []).filter((row) => (!filters.group || row.itemGroup === filters.group)
    && (!filters.status || row.status === filters.status))
  const columns = [
    { key: 'itemCode', label: 'Mã hàng' },
    { key: 'itemName', label: 'Tên hàng' },
    { key: 'itemGroup', label: 'Nhóm' },
    { key: 'unit', label: 'ĐVT' },
    { key: 'opening', label: 'Tồn đầu kỳ', numeric: true },
    { key: 'netIn', label: 'Tổng nhập (ròng)', numeric: true },
    { key: 'out', label: 'Tổng xuất', numeric: true },
    { key: 'stock', label: 'Tồn hiện tại', numeric: true, render: (row) => <b>{formatNumber(row.stock)}</b>, csv: (row) => row.stock },
    { key: 'min', label: 'Tồn tối thiểu', numeric: true },
    { key: 'max', label: 'Tồn tối đa', numeric: true },
    { key: 'suggested', label: 'Cần bổ sung', numeric: true, render: (row) => row.suggested || '' },
    { key: 'onOrder', label: 'Đang đặt', numeric: true, render: (row) => row.onOrder || '' },
    { key: 'price', label: 'Đơn giá áp dụng', numeric: true, render: (row) => formatNumber(row.price), csv: (row) => Math.round(row.price) },
    { key: 'value', label: 'Giá trị tồn', numeric: true, render: (row) => formatNumber(row.value), csv: (row) => Math.round(row.value) },
    { key: 'status', label: 'Trạng thái', render: (row) => <Badge value={row.status} labels={stockStatusLabels} />, csv: (row) => stockStatusLabels[row.status] },
    { key: 'lastUse', label: 'Xuất gần nhất', render: (row) => formatDate(row.lastUse) },
    { key: 'idleDays', label: 'Ngày không xuất', numeric: true, render: (row) => row.idleDays ?? 'Chưa xuất' },
  ]

  return (
    <section className="warehouse-card">
      <p className="warehouse-muted">Tồn hiện tại = Tồn đầu + Nhập ròng (đã nhận) − Xuất (đã xác nhận). Không nhập tay.</p>
      <div className="warehouse-filters">
        <label>
          <span>Nhóm hàng</span>
          <select value={filters.group} onChange={(event) => setFilters({ ...filters, group: event.target.value })}>
            <option value="">Mọi nhóm</option>
            {groupsOf(data?.rows || []).map((name) => <option key={name} value={name}>{name}</option>)}
          </select>
        </label>
        <label>
          <span>Trạng thái</span>
          <select value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value })}>
            <option value="">Mọi trạng thái</option>
            {Object.entries(stockStatusLabels).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
          </select>
        </label>
        <button type="button" className="warehouse-button" onClick={() => downloadCsv('ton-kho.csv', columns, rows)}>Xuất CSV</button>
      </div>
      <Messages error={error} />
      <WarehouseTable loading={loading && !data} rows={rows} rowKey="itemId" columns={columns} searchText={(row) => `${row.itemCode} ${row.itemName}`} />
    </section>
  )
}

function ReminderModal({ schedule, onClose, onMarked }) {
  const rows = useMemo(() => schedule.rows
    .filter((row) => dueStatuses.includes(row.status) && row.left <= schedule.remindDays
      && (!row.reminder || (new Date(`${schedule.ref}T00:00:00Z`) - new Date(`${row.reminder.date}T00:00:00Z`)) / 864e5 >= schedule.remindGap))
    .sort((a, b) => a.departmentName.localeCompare(b.departmentName) || a.left - b.left), [schedule])
  const [to, setTo] = useState(schedule.emailTo)
  const [subject, setSubject] = useState(`Nhắc cấp phát định kỳ – ${rows.length} dòng – ${formatDate(schedule.ref)}`)
  const [error, setError] = useState('')
  const body = useMemo(() => {
    let text = `Kính gửi HR-GA,\n\nDanh sách cấp phát định kỳ cần xử lý (tính đến ${formatDate(schedule.ref)}) – ${rows.length} dòng:\n`
    let department = null
    for (const row of rows) {
      if (row.departmentName !== department) {
        department = row.departmentName
        text += `\n[${department || 'Chưa có phòng ban'}]\n`
      }
      text += `- ${row.userCode} ${row.name} | ${row.itemCode} x${row.qty} | đến hạn ${formatDate(row.due)} (${row.left < 0 ? `quá ${-row.left} ngày` : `còn ${row.left} ngày`}) | ${scheduleStatusLabels[row.status]} | tồn ${row.available}\n`
    }
    return `${text}\nTrân trọng,\nHệ thống quản lý kho`
  }, [rows, schedule.ref])

  const mark = async () => {
    try {
      await sendJson('/api/kho/reminders', 'POST', { keys: rows.map((row) => row.key) })
      onMarked(`Đã đánh dấu ${rows.length} dòng đã nhắc.`)
    } catch (requestError) {
      setError(requestError.message)
    }
  }

  return (
    <WarehouseModal title={`Soạn email nhắc lịch (${rows.length} dòng)`} onClose={onClose} wide>
      <Messages error={error} />
      {!rows.length ? (
        <p className="warehouse-muted">Không có dòng nào cần nhắc (hoặc đã nhắc trong {schedule.remindGap} ngày gần đây).</p>
      ) : (
        <>
          <div className="warehouse-form-grid">
            <label><span>Gửi tới</span><input value={to} onChange={(event) => setTo(event.target.value)} /></label>
            <label className="span-2"><span>Tiêu đề</span><input value={subject} onChange={(event) => setSubject(event.target.value)} /></label>
          </div>
          <pre className="warehouse-pre">{body}</pre>
          <div className="warehouse-actions">
            <button
              type="button"
              className="warehouse-button primary"
              onClick={() => {
                const text = body.length > 1500 ? `${body.slice(0, 1500)}\n...(đã cắt, xem đầy đủ trong Workdesk)` : body
                window.location.href = `mailto:${encodeURIComponent(to)}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(text)}`
              }}
            >
              ✉ Mở email
            </button>
            <button
              type="button"
              className="warehouse-button"
              onClick={() => navigator.clipboard?.writeText(body).then(() => setError(''), () => setError('Không sao chép được, hãy bôi đen nội dung và nhấn Ctrl+C.'))}
            >
              Sao chép nội dung
            </button>
            <button type="button" className="warehouse-button success" onClick={mark}>Đánh dấu đã nhắc ({rows.length} dòng)</button>
            <button type="button" className="warehouse-button" onClick={onClose}>Đóng</button>
          </div>
          <p className="warehouse-muted">"Mở email" mở phần mềm mail mặc định; nội dung dài sẽ bị cắt – khi đó dùng "Sao chép nội dung". Chỉ bấm "Đánh dấu đã nhắc" sau khi đã gửi.</p>
        </>
      )}
    </WarehouseModal>
  )
}

export function WarehouseSchedule({ access, onIssue }) {
  const [filters, setFilters] = useState({ status: '', department: '', window: '' })
  const [composing, setComposing] = useState(false)
  const [notice, setNotice] = useState('')
  const { data, error, loading, reload } = useKhoResource('/api/kho/schedule')
  const limit = (days) => {
    const value = new Date(`${data.ref}T00:00:00Z`)
    value.setUTCDate(value.getUTCDate() + days)
    return value.toISOString().slice(0, 10)
  }
  const rows = (data?.rows || []).filter((row) => (!filters.status || row.status === filters.status)
    && (!filters.department || row.departmentName === filters.department)
    && (!filters.window || (row.due && row.due <= limit(Number(filters.window)))))
  const departments = [...new Set((data?.rows || []).map((row) => row.departmentName).filter(Boolean))].sort()
  const columns = [
    { key: 'userCode', label: 'Mã NV' },
    { key: 'name', label: 'Họ tên' },
    { key: 'departmentName', label: 'Phòng ban' },
    { key: 'itemCode', label: 'Mã hàng' },
    { key: 'itemName', label: 'Tên hàng', value: (row) => row.itemName || (row.missingSize ? '⚠ NV chưa khai báo size' : '⚠ thiếu mã/size trong danh mục') },
    { key: 'last', label: 'Cấp gần nhất', render: (row) => formatDate(row.last) },
    { key: 'cycle', label: 'Chu kỳ (th)', numeric: true, render: (row) => row.cycle || '' },
    { key: 'due', label: 'Đến hạn', render: (row) => formatDate(row.due) },
    {
      key: 'left',
      label: 'Còn lại (ngày)',
      numeric: true,
      render: (row) => row.left === null ? '' : <span className={`warehouse-badge ${row.left <= 7 ? 'red' : row.left <= 15 ? 'yellow' : 'green'}`}>{row.left}</span>,
      csv: (row) => row.left ?? '',
    },
    { key: 'status', label: 'Trạng thái', render: (row) => <Badge value={row.status} labels={scheduleStatusLabels} />, csv: (row) => scheduleStatusLabels[row.status] },
    { key: 'qty', label: 'SL dự kiến', numeric: true },
    { key: 'available', label: 'Tồn khả dụng', numeric: true },
    { key: 'reminder', label: 'Đã nhắc', value: (row) => row.reminder ? `${formatDate(row.reminder.date)} – ${row.reminder.result}` : '' },
    {
      key: 'action',
      label: '',
      csv: false,
      render: (row) => access.canWrite && row.itemId && (
        <button type="button" className="warehouse-button small" onClick={() => onIssue({ userId: row.userId, purpose: row.last ? 'periodic' : 'new_hire', lines: [{ itemId: row.itemId, qty: row.qty }] })}>
          Lập phiếu
        </button>
      ),
    },
  ]

  return (
    <section className="warehouse-card">
      <p className="warehouse-muted">
        Tự sinh từ nhân viên đang làm × chính sách nhóm đối tượng (Thiết lập). Đến hạn <b>không</b> tự cấp phát: kiểm tra rồi bấm "Lập phiếu".
        {data ? ` ${data.inactive} NV nghỉ việc tạm thời được loại khỏi lịch.` : ''}
      </p>
      <div className="warehouse-filters">
        <label>
          <span>Trạng thái</span>
          <select value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value })}>
            <option value="">Mọi trạng thái</option>
            {Object.entries(scheduleStatusLabels).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
          </select>
        </label>
        <label>
          <span>Phòng ban</span>
          <select value={filters.department} onChange={(event) => setFilters({ ...filters, department: event.target.value })}>
            <option value="">Mọi phòng ban</option>
            {departments.map((name) => <option key={name} value={name}>{name}</option>)}
          </select>
        </label>
        <label>
          <span>Thời hạn</span>
          <select value={filters.window} onChange={(event) => setFilters({ ...filters, window: event.target.value })}>
            <option value="">Mọi thời hạn</option>
            <option value="7">Đến hạn trong 7 ngày</option>
            <option value="15">Trong 15 ngày</option>
            <option value="30">Trong 30 ngày</option>
          </select>
        </label>
        {access.canWrite && data && (
          <button type="button" className="warehouse-button primary" onClick={() => setComposing(true)}>✉ Soạn email nhắc lịch</button>
        )}
        <button type="button" className="warehouse-button" onClick={() => downloadCsv('lich-cap-phat.csv', columns, rows)}>Xuất CSV</button>
      </div>
      <Messages error={error} notice={notice} />
      <WarehouseTable loading={loading && !data} rows={rows} rowKey="key" columns={columns} searchText={(row) => `${row.userCode} ${row.name} ${row.itemCode}`} />
      {composing && (
        <ReminderModal
          schedule={data}
          onClose={() => setComposing(false)}
          onMarked={(message) => {
            setComposing(false)
            setNotice(message)
            reload()
          }}
        />
      )}
    </section>
  )
}

export function WarehousePlan({ access }) {
  const [showAll, setShowAll] = useState(false)
  const [group, setGroup] = useState('')
  const [message, setMessage] = useState({ error: '', notice: '' })
  const { data, error, loading, reload } = useKhoResource('/api/kho/plan')
  const rows = (data?.rows || []).filter((row) => (showAll || row.suggested > 0) && (!group || row.itemGroup === group))
  const needed = (data?.rows || []).filter((row) => row.suggested > 0)

  const create = async (itemIds) => {
    setMessage({ error: '', notice: '' })
    try {
      const result = await sendJson('/api/kho/orders/from-plan', 'POST', itemIds ? { itemIds } : {})
      setMessage({ error: '', notice: `Đã tạo ${result.created.length} PO (Chờ duyệt): ${result.created.join(', ')}` })
      reload()
    } catch (requestError) {
      setMessage({ error: requestError.message, notice: '' })
    }
  }

  const columns = [
    { key: 'itemCode', label: 'Mã hàng' },
    { key: 'itemName', label: 'Tên hàng' },
    { key: 'stock', label: 'Tồn hiện tại', numeric: true },
    { key: 'min', label: 'Tồn tối thiểu', numeric: true },
    { key: 'safety', label: 'Tồn an toàn', numeric: true },
    { key: 'monthly', label: 'Mức dùng bq/tháng', numeric: true, render: (row) => formatNumber(row.monthly, 1) },
    { key: 'needLead', label: 'Nhu cầu trong thời gian chờ', numeric: true, render: (row) => formatNumber(row.needLead, 1) },
    { key: 'forecast', label: 'Nhu cầu dự báo', numeric: true, render: (row) => formatNumber(row.forecast, 1) },
    { key: 'reorderPoint', label: 'ROP', numeric: true, render: (row) => formatNumber(row.reorderPoint, 1) },
    { key: 'onOrder', label: 'Hàng đang đặt', numeric: true },
    { key: 'lead', label: 'Giao hàng (ngày)', numeric: true },
    { key: 'suggested', label: 'SL đề xuất mua', numeric: true, render: (row) => row.suggested ? <b>{row.suggested}</b> : '', csv: (row) => row.suggested },
    { key: 'price', label: 'Đơn giá', numeric: true, render: (row) => formatNumber(row.price) },
    { key: 'budget', label: 'Ngân sách', numeric: true, render: (row) => formatNumber(row.budget) },
    { key: 'orderBy', label: 'Ngày cần đặt', render: (row) => formatDate(row.orderBy) },
    { key: 'priority', label: 'Ưu tiên', render: (row) => <Badge value={row.priority} labels={priorityLabels} />, csv: (row) => priorityLabels[row.priority] },
    { key: 'purchaseStatus', label: 'Trạng thái mua', value: (row) => orderStatusLabels[row.purchaseStatus] || '—' },
    {
      key: 'action',
      label: '',
      csv: false,
      render: (row) => access.canWrite && row.suggested > 0 && (
        <button type="button" className="warehouse-button small" onClick={() => create([row.itemId])}>Tạo PO</button>
      ),
    },
  ]

  return (
    <section className="warehouse-card">
      <p className="warehouse-muted">
        Điểm đặt hàng ROP = nhu cầu trong thời gian chờ hàng + tồn an toàn. Cần đặt khi (Tồn + Đang đặt) ≤ ROP hoặc &lt; tồn tối thiểu.
        SL mua = MAX(Tồn tối đa, Nhu cầu dự báo + An toàn) − (Tồn + Đang đặt), làm tròn theo MOQ.
        Tổng ngân sách đề xuất: <b>{formatNumber(data?.totalBudget)} đ</b>.
      </p>
      <div className="warehouse-filters">
        <label>
          <span>Nhóm hàng</span>
          <select value={group} onChange={(event) => setGroup(event.target.value)}>
            <option value="">Mọi nhóm</option>
            {groupsOf(data?.rows || []).map((name) => <option key={name} value={name}>{name}</option>)}
          </select>
        </label>
        <label className="warehouse-check">
          <input type="checkbox" checked={showAll} onChange={(event) => setShowAll(event.target.checked)} />
          <span>Hiện cả mã chưa cần đặt</span>
        </label>
        {access.canWrite && (
          <button
            type="button"
            className="warehouse-button primary"
            disabled={!needed.length}
            onClick={() => {
              if (window.confirm(`Tạo ${needed.length} PO ở trạng thái Chờ duyệt?`)) create(null)
            }}
          >
            Tạo PO cho tất cả đề xuất ({needed.length})
          </button>
        )}
        <button type="button" className="warehouse-button" onClick={() => downloadCsv('ke-hoach-mua.csv', columns, rows)}>Xuất CSV</button>
      </div>
      <Messages error={message.error || error} notice={message.notice} />
      <WarehouseTable loading={loading && !data} rows={rows} rowKey="itemId" columns={columns} searchText={(row) => `${row.itemCode} ${row.itemName}`} />
    </section>
  )
}

function OrderForm({ onClose, onSaved }) {
  const { data: items } = useKhoResource('/api/kho/items')
  const [form, setForm] = useState({ itemId: '', qty: '', type: 'periodic', note: '' })
  const [error, setError] = useState('')
  const options = (items || []).filter((item) => item.active).map((item) => ({ value: String(item.id), label: `${item.code} · ${item.name}` }))

  const submit = async (event) => {
    event.preventDefault()
    try {
      const result = await sendJson('/api/kho/orders', 'POST', { ...form, itemId: Number(form.itemId), qty: Number(form.qty) })
      onSaved(`Đã thêm ${result.poNo} (Chờ duyệt).`)
    } catch (requestError) {
      setError(requestError.message)
    }
  }

  return (
    <WarehouseModal title="Thêm đơn đặt hàng" onClose={onClose}>
      <Messages error={error} />
      <form onSubmit={submit}>
        <div className="warehouse-form-grid">
          <label className="span-2">
            <span>Mã hàng *</span>
            <SearchableSelect label="Mã hàng PO" value={form.itemId} options={options} required onChange={(value) => setForm({ ...form, itemId: value })} />
          </label>
          <label><span>Số lượng *</span><input type="number" min="1" required value={form.qty} onChange={(event) => setForm({ ...form, qty: event.target.value })} /></label>
          <label>
            <span>Loại mua</span>
            <select value={form.type} onChange={(event) => setForm({ ...form, type: event.target.value })}>
              {Object.entries(orderTypeLabels).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
            </select>
          </label>
          <label className="span-2"><span>Ghi chú</span><input maxLength={500} value={form.note} onChange={(event) => setForm({ ...form, note: event.target.value })} /></label>
        </div>
        <div className="warehouse-actions">
          <button type="submit" className="warehouse-button primary">Lưu</button>
          <button type="button" className="warehouse-button" onClick={onClose}>Đóng</button>
        </div>
      </form>
    </WarehouseModal>
  )
}

export function WarehouseOrders({ access, onReceive }) {
  const [status, setStatus] = useState('')
  const [adding, setAdding] = useState(false)
  const [message, setMessage] = useState({ error: '', notice: '' })
  const { data, error, loading, reload } = useKhoResource('/api/kho/orders')
  const rows = (data?.rows || []).filter((row) => !status || row.status === status)

  const changeStatus = async (row, next) => {
    setMessage({ error: '', notice: '' })
    try {
      await requestApi(`/api/kho/orders/${row.id}/status`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ status: next }),
      })
      setMessage({ error: '', notice: `${row.poNo}: ${orderStatusLabels[next]}` })
    } catch (requestError) {
      setMessage({ error: requestError.message, notice: '' })
    }
    reload()
  }

  const nextStatuses = (row) => {
    if (['completed', 'cancelled'].includes(row.status) || !access.canWrite) return []
    if (row.received > 0) return ['cancelled']
    const options = { pending_approval: ['approved', 'cancelled'], approved: ['ordered', 'cancelled'], ordered: ['approved', 'cancelled'], partial: ['cancelled'] }[row.status] || []
    return options.filter((option) => option !== 'approved' || access.canManage)
  }

  const columns = [
    { key: 'poNo', label: 'Mã PO' },
    { key: 'date', label: 'Ngày', render: (row) => formatDate(row.date) },
    { key: 'itemCode', label: 'Mã hàng' },
    { key: 'itemName', label: 'Tên hàng' },
    { key: 'qty', label: 'SL đặt', numeric: true },
    { key: 'price', label: 'Đơn giá', numeric: true, render: (row) => formatNumber(row.price) },
    { key: 'budget', label: 'Ngân sách', numeric: true, render: (row) => formatNumber(row.budget) },
    { key: 'supplier', label: 'NCC' },
    { key: 'eta', label: 'Giao dự kiến', render: (row) => formatDate(row.eta) },
    { key: 'status', label: 'Trạng thái', render: (row) => <Badge value={row.status} labels={orderStatusLabels} />, csv: (row) => orderStatusLabels[row.status] },
    { key: 'type', label: 'Loại', value: (row) => orderTypeLabels[row.type] },
    { key: 'received', label: 'Đã nhận', numeric: true },
    { key: 'remaining', label: 'Còn chờ', numeric: true },
    { key: 'note', label: 'Ghi chú' },
    {
      key: 'actions',
      label: '',
      csv: false,
      render: (row) => (
        <div className="warehouse-row-actions">
          {nextStatuses(row).length > 0 && (
            <select aria-label={`Đổi trạng thái ${row.poNo}`} value="" onChange={(event) => event.target.value && changeStatus(row, event.target.value)}>
              <option value="">Đổi trạng thái…</option>
              {nextStatuses(row).map((key) => <option key={key} value={key}>{orderStatusLabels[key]}</option>)}
            </select>
          )}
          {access.canWrite && ['approved', 'ordered', 'partial'].includes(row.status) && (
            <button type="button" className="warehouse-button small success" onClick={() => onReceive({ itemId: row.itemId, poId: row.id, qty: row.remaining, type: 'purchase' })}>
              Nhận hàng
            </button>
          )}
        </div>
      ),
    },
  ]

  return (
    <section className="warehouse-card">
      <p className="warehouse-muted">
        Quy trình: Chờ duyệt → Đã duyệt (quản lý kho) → Đã đặt hàng → bấm "Nhận hàng" (tạo phiếu nhập gắn mã PO) → Đã nhận một phần/Hoàn tất tự động.
        PO đang mở được tính vào "Hàng đang đặt" nên không bị đề xuất trùng.
      </p>
      <div className="warehouse-filters">
        {access.canWrite && <button type="button" className="warehouse-button primary" onClick={() => setAdding(true)}>+ Thêm PO</button>}
        <label>
          <span>Trạng thái</span>
          <select value={status} onChange={(event) => setStatus(event.target.value)}>
            <option value="">Mọi trạng thái</option>
            {Object.entries(orderStatusLabels).filter(([key]) => key !== 'not_proposed').map(([key, label]) => <option key={key} value={key}>{label}</option>)}
          </select>
        </label>
        <button type="button" className="warehouse-button" onClick={() => downloadCsv('dat-hang.csv', columns, rows)}>Xuất CSV</button>
      </div>
      <Messages error={message.error || error} notice={message.notice} />
      <WarehouseTable loading={loading && !data} rows={rows} columns={columns} searchText={(row) => `${row.poNo} ${row.itemCode} ${row.itemName}`} />
      {adding && (
        <OrderForm
          onClose={() => setAdding(false)}
          onSaved={(notice) => {
            setAdding(false)
            setMessage({ error: '', notice })
            reload()
          }}
        />
      )}
    </section>
  )
}
