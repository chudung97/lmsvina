import { useState } from 'react'
import {
  addDays,
  downloadCsv,
  formatDate,
  formatNumber,
  issueStatusLabels,
  purposeLabels,
  receiptStatusLabels,
  receiptTypeLabels,
  runConfirmation,
  sendJson,
  todayInVietnam,
  warningLabels,
} from './warehouseApi'
import { Badge, Messages, WarehouseTable } from './WarehouseUi'
import { useKhoResource } from './useKhoResource'

function DateRange({ from, to, onChange }) {
  return (
    <>
      <label>
        <span>Từ ngày</span>
        <input type="date" value={from} onChange={(event) => onChange(event.target.value, to)} />
      </label>
      <label>
        <span>Đến ngày</span>
        <input type="date" value={to} onChange={(event) => onChange(from, event.target.value)} />
      </label>
    </>
  )
}

async function askReason(message) {
  const reason = window.prompt(message)
  if (reason === null) return null
  if (!reason.trim()) throw new Error('Cần ghi lý do hủy.')
  return reason.trim()
}

export function WarehouseIssues({ access, onIssue }) {
  const [range, setRange] = useState(() => ({ from: addDays(todayInVietnam(), -90), to: todayInVietnam() }))
  const [filters, setFilters] = useState({ status: '', department: '', group: '', warningsOnly: false })
  const [message, setMessage] = useState({ error: '', notice: '' })
  const [busy, setBusy] = useState(false)
  const { data, error, loading, reload } = useKhoResource(`/api/kho/issues?from=${range.from}&to=${range.to}`)
  const rows = (data?.rows || []).filter((row) => (!filters.status || row.status === filters.status)
    && (!filters.department || row.departmentName === filters.department)
    && (!filters.group || row.itemGroup === filters.group)
    && (!filters.warningsOnly || !['ok', 'cancelled'].includes(row.warning)))
  const departments = [...new Set((data?.rows || []).map((row) => row.departmentName).filter(Boolean))].sort()
  const groups = [...new Set((data?.rows || []).map((row) => row.itemGroup).filter(Boolean))].sort()

  const run = async (action) => {
    setBusy(true)
    setMessage({ error: '', notice: '' })
    try {
      const notice = await action()
      if (notice) setMessage({ error: '', notice })
      reload()
    } catch (requestError) {
      setMessage({ error: requestError.message, notice: '' })
    } finally {
      setBusy(false)
    }
  }

  const confirmIds = (ids) => run(async () => {
    const result = await sendJson('/api/kho/issues/confirm', 'POST', { ids })
    return runConfirmation(result)
  })

  const columns = [
    { key: 'voucher', label: 'Mã phiếu' },
    { key: 'date', label: 'Ngày', render: (row) => formatDate(row.date) },
    { key: 'time', label: 'Giờ' },
    { key: 'userCode', label: 'Mã NV' },
    { key: 'userName', label: 'Họ tên' },
    { key: 'departmentName', label: 'Phòng ban' },
    { key: 'itemCode', label: 'Mã hàng' },
    { key: 'itemName', label: 'Tên hàng' },
    { key: 'size', label: 'Size' },
    { key: 'qty', label: 'SL', numeric: true },
    { key: 'unit', label: 'ĐVT' },
    { key: 'purpose', label: 'Mục đích', value: (row) => purposeLabels[row.purpose] },
    { key: 'nextDue', label: 'Ngày cấp tiếp theo', render: (row) => formatDate(row.nextDue), csv: (row) => row.nextDue },
    { key: 'status', label: 'Trạng thái', render: (row) => <Badge value={row.status} labels={issueStatusLabels} />, csv: (row) => issueStatusLabels[row.status] },
    { key: 'warning', label: 'Cảnh báo', render: (row) => <Badge value={row.warning} labels={warningLabels} />, csv: (row) => warningLabels[row.warning] },
    { key: 'giver', label: 'Người giao' },
    { key: 'note', label: 'Ghi chú' },
    {
      key: 'actions',
      label: '',
      csv: false,
      render: (row) => access.canWrite && (
        <div className="warehouse-row-actions">
          {['draft', 'shortage'].includes(row.status) && (
            <button type="button" className="warehouse-button small primary" disabled={busy} onClick={() => confirmIds([row.id])}>Xác nhận</button>
          )}
          {row.status === 'draft' && row.warning === 'over_stock' && (
            <button type="button" className="warehouse-button small" disabled={busy} onClick={() => run(async () => {
              await sendJson(`/api/kho/issues/${row.id}/shortage`, 'POST')
              return 'Đã chuyển dòng sang "Thiếu hàng".'
            })}>Thiếu hàng</button>
          )}
          {row.status !== 'cancelled' && (row.status !== 'confirmed' || access.canManage) && (
            <button type="button" className="warehouse-button small" disabled={busy} onClick={() => run(async () => {
              const reason = await askReason(`Lý do hủy ${row.voucher} / ${row.itemCode}${row.status === 'confirmed' ? ' (đã xác nhận – hủy sẽ hoàn lại tồn)' : ''}:`)
              if (reason === null) return ''
              await sendJson(`/api/kho/issues/${row.id}/cancel`, 'POST', { reason })
              return 'Đã hủy dòng (còn lưu vết).'
            })}>Hủy</button>
          )}
        </div>
      ),
    },
  ]
  const drafts = rows.filter((row) => row.status === 'draft')

  return (
    <section className="warehouse-card">
      <p className="warehouse-muted">
        Lập phiếu (nhiều mặt hàng/nhân viên) → kiểm tra cảnh báo → bấm <b>Xác nhận</b> để ghi dấu thời gian thật. Chỉ dòng "Đã xác nhận" trừ tồn.
        Dòng nháp/thiếu hàng luôn hiển thị, các dòng khác lọc theo khoảng ngày.
      </p>
      <div className="warehouse-filters">
        {access.canWrite && (
          <button type="button" className="warehouse-button primary" onClick={() => onIssue({})}>+ Lập phiếu cấp phát</button>
        )}
        <DateRange from={range.from} to={range.to} onChange={(from, to) => setRange({ from, to })} />
        <label>
          <span>Trạng thái</span>
          <select value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value })}>
            <option value="">Mọi trạng thái</option>
            {Object.entries(issueStatusLabels).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
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
          <span>Nhóm hàng</span>
          <select value={filters.group} onChange={(event) => setFilters({ ...filters, group: event.target.value })}>
            <option value="">Mọi nhóm hàng</option>
            {groups.map((name) => <option key={name} value={name}>{name}</option>)}
          </select>
        </label>
        <label className="warehouse-check">
          <input type="checkbox" checked={filters.warningsOnly} onChange={(event) => setFilters({ ...filters, warningsOnly: event.target.checked })} />
          <span>Chỉ dòng có cảnh báo</span>
        </label>
        {access.canWrite && (
          <button
            type="button"
            className="warehouse-button"
            disabled={busy || !drafts.length}
            onClick={() => {
              if (window.confirm(`Xác nhận ${drafts.length} dòng Nháp theo bộ lọc hiện tại?`)) confirmIds(drafts.map((row) => row.id))
            }}
          >
            Xác nhận {drafts.length} dòng Nháp đang lọc
          </button>
        )}
        <button type="button" className="warehouse-button" onClick={() => downloadCsv('xuat-kho.csv', columns, rows)}>Xuất CSV</button>
      </div>
      <Messages error={message.error || error} notice={message.notice} />
      <WarehouseTable
        loading={loading && !data}
        rows={rows}
        columns={columns}
        searchText={(row) => `${row.voucher} ${row.userCode} ${row.userName} ${row.itemCode} ${row.itemName}`}
      />
    </section>
  )
}

export function WarehouseReceipts({ access, onReceive }) {
  const [range, setRange] = useState(() => ({ from: addDays(todayInVietnam(), -90), to: todayInVietnam() }))
  const [filters, setFilters] = useState({ type: '', group: '' })
  const [message, setMessage] = useState({ error: '', notice: '' })
  const { data, error, loading, reload } = useKhoResource(`/api/kho/receipts?from=${range.from}&to=${range.to}`)
  const rows = (data?.rows || []).filter((row) => (!filters.type || row.type === filters.type)
    && (!filters.group || row.itemGroup === filters.group))
  const groups = [...new Set((data?.rows || []).map((row) => row.itemGroup).filter(Boolean))].sort()

  const cancel = async (row) => {
    setMessage({ error: '', notice: '' })
    try {
      const reason = await askReason(`Lý do hủy giao dịch ${row.voucher} / ${row.itemCode} (hoàn tác ảnh hưởng tồn):`)
      if (reason === null) return
      await sendJson(`/api/kho/receipts/${row.id}/cancel`, 'POST', { reason })
      setMessage({ error: '', notice: 'Đã hủy giao dịch nhập.' })
      reload()
    } catch (requestError) {
      setMessage({ error: requestError.message, notice: '' })
    }
  }

  const columns = [
    { key: 'voucher', label: 'Mã phiếu' },
    { key: 'date', label: 'Ngày', render: (row) => formatDate(row.date) },
    { key: 'time', label: 'Giờ' },
    { key: 'itemCode', label: 'Mã hàng' },
    { key: 'itemName', label: 'Tên hàng' },
    { key: 'itemGroup', label: 'Nhóm' },
    { key: 'qty', label: 'SL', numeric: true },
    { key: 'appliedPrice', label: 'Đơn giá', numeric: true, render: (row) => formatNumber(row.appliedPrice) },
    { key: 'amount', label: 'Thành tiền', numeric: true, render: (row) => formatNumber(row.amount) },
    { key: 'supplier', label: 'NCC' },
    { key: 'invoice', label: 'Chứng từ' },
    { key: 'type', label: 'Loại GD', value: (row) => receiptTypeLabels[row.type] },
    { key: 'poNo', label: 'Mã PO' },
    { key: 'status', label: 'Trạng thái', render: (row) => <Badge value={row.status} labels={receiptStatusLabels} />, csv: (row) => receiptStatusLabels[row.status] },
    { key: 'warning', label: 'Cảnh báo', render: (row) => <Badge value={row.warning} labels={warningLabels} />, csv: (row) => warningLabels[row.warning] },
    { key: 'receiver', label: 'Người nhập' },
    { key: 'note', label: 'Ghi chú' },
    {
      key: 'actions',
      label: '',
      csv: false,
      render: (row) => access.canManage && row.status === 'received' && (
        <button type="button" className="warehouse-button small" onClick={() => cancel(row)}>Hủy</button>
      ),
    },
  ]

  return (
    <section className="warehouse-card">
      <p className="warehouse-muted">Mỗi dòng là một giao dịch nhập/điều chỉnh. Loại giao dịch quyết định cộng hay trừ tồn; không xóa, chỉ Hủy để còn dấu vết.</p>
      <div className="warehouse-filters">
        {access.canWrite && (
          <button type="button" className="warehouse-button primary" onClick={() => onReceive({})}>+ Nhập kho / điều chỉnh</button>
        )}
        <DateRange from={range.from} to={range.to} onChange={(from, to) => setRange({ from, to })} />
        <label>
          <span>Loại giao dịch</span>
          <select value={filters.type} onChange={(event) => setFilters({ ...filters, type: event.target.value })}>
            <option value="">Mọi loại</option>
            {Object.entries(receiptTypeLabels).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
          </select>
        </label>
        <label>
          <span>Nhóm hàng</span>
          <select value={filters.group} onChange={(event) => setFilters({ ...filters, group: event.target.value })}>
            <option value="">Mọi nhóm</option>
            {groups.map((name) => <option key={name} value={name}>{name}</option>)}
          </select>
        </label>
        <button type="button" className="warehouse-button" onClick={() => downloadCsv('nhap-kho.csv', columns, rows)}>Xuất CSV</button>
      </div>
      <Messages error={message.error || error} notice={message.notice} />
      <WarehouseTable
        loading={loading && !data}
        rows={rows}
        columns={columns}
        searchText={(row) => `${row.voucher} ${row.itemCode} ${row.itemName} ${row.poNo} ${row.invoice}`}
      />
    </section>
  )
}
