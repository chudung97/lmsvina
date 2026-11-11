import { useState } from 'react'
import { formatDate, formatNumber, scheduleStatusLabels, todayInVietnam } from './warehouseApi'
import { Badge, Messages } from './WarehouseUi'
import { useKhoResource } from './useKhoResource'

function SimpleTable({ columns, rows, empty = 'Không có dữ liệu.' }) {
  return (
    <div className="warehouse-table-wrap">
      <table className="warehouse-table compact">
        <thead>
          <tr>{columns.map((column) => <th key={column.key} className={column.numeric ? 'numeric' : ''}>{column.label}</th>)}</tr>
        </thead>
        <tbody>
          {rows.map((row, index) => (
            <tr key={index} className={row.bold ? 'total' : ''}>
              {columns.map((column) => (
                <td key={column.key} data-label={column.label} className={column.numeric ? 'numeric' : ''}>
                  {column.render ? column.render(row) : row[column.key]}
                </td>
              ))}
            </tr>
          ))}
          {!rows.length && <tr><td className="warehouse-empty" colSpan={columns.length}>{empty}</td></tr>}
        </tbody>
      </table>
    </div>
  )
}

const money = (key) => ({ key, numeric: true, render: (row) => formatNumber(row[key]) })

export function WarehouseReports({ itemGroups }) {
  const today = todayInVietnam()
  const [filters, setFilters] = useState({
    year: today.slice(0, 4),
    period: 'month',
    number: String(Number(today.slice(5, 7))),
    departmentId: '',
    group: '',
    itemCode: '',
  })
  const params = new URLSearchParams(Object.entries(filters).filter(([, value]) => value !== ''))
  const { data, error, loading } = useKhoResource(`/api/kho/reports?${params}`)
  const { data: settings } = useKhoResource('/api/kho/settings')
  const update = (key, value) => setFilters((current) => ({ ...current, [key]: value }))
  const flowTotal = data ? data.flow.reduce((total, row) => ({
    group: 'TỔNG',
    open: total.open + row.open,
    purchase: total.purchase + row.purchase,
    other: total.other + row.other,
    out: total.out + row.out,
    close: total.close + row.close,
    outQty: total.outQty + row.outQty,
    bold: true,
  }), { open: 0, purchase: 0, other: 0, out: 0, close: 0, outQty: 0 }) : null

  return (
    <section className="warehouse-card warehouse-report">
      <div className="warehouse-filters no-print">
        <label><span>Năm</span><input type="number" min="2000" max="2100" value={filters.year} onChange={(event) => update('year', event.target.value)} /></label>
        <label>
          <span>Loại kỳ</span>
          <select value={filters.period} onChange={(event) => setFilters({ ...filters, period: event.target.value, number: '1' })}>
            <option value="month">Tháng</option>
            <option value="quarter">Quý</option>
            <option value="year">Năm</option>
          </select>
        </label>
        {filters.period !== 'year' && (
          <label>
            <span>Kỳ số</span>
            <input type="number" min="1" max={filters.period === 'month' ? 12 : 4} value={filters.number} onChange={(event) => update('number', event.target.value)} />
          </label>
        )}
        <label>
          <span>Phòng ban</span>
          <select value={filters.departmentId} onChange={(event) => update('departmentId', event.target.value)}>
            <option value="">Mọi phòng ban</option>
            {(settings?.departments || []).map((department) => <option key={department.id} value={department.id}>{department.name}</option>)}
          </select>
        </label>
        <label>
          <span>Nhóm hàng</span>
          <select value={filters.group} onChange={(event) => update('group', event.target.value)}>
            <option value="">Mọi nhóm hàng</option>
            {itemGroups.map((group) => <option key={group} value={group}>{group}</option>)}
          </select>
        </label>
        <label><span>Mã hàng</span><input placeholder="Để trống = tất cả" value={filters.itemCode} onChange={(event) => update('itemCode', event.target.value)} /></label>
        <button type="button" className="warehouse-button" onClick={() => window.print()}>In</button>
      </div>
      <Messages error={error} />
      {loading && !data && <p className="warehouse-muted">Đang lập báo cáo...</p>}
      {data && (
        <>
          <p className="warehouse-muted">Kỳ báo cáo: <b>{formatDate(data.from)} – {formatDate(data.to)}</b>. Giá trị xuất tính theo đơn giá áp dụng hiện hành.</p>

          <h2>1. Nhập – xuất – tồn theo nhóm hàng (VND)</h2>
          <SimpleTable
            rows={[...data.flow, flowTotal]}
            columns={[
              { key: 'group', label: 'Nhóm hàng', render: (row) => row.bold ? <b>{row.group}</b> : row.group },
              { ...money('open'), label: 'Tồn đầu kỳ' },
              { ...money('purchase'), label: 'Nhập mua' },
              { ...money('other'), label: 'Nhập/điều chỉnh khác (ròng)' },
              { ...money('out'), label: 'Xuất' },
              { ...money('close'), label: 'Tồn cuối kỳ' },
              { ...money('outQty'), label: 'SL xuất' },
            ]}
          />

          <h2>2. Chi phí tiêu hao theo phòng ban &amp; chi phí đồng phục/bảo hộ trên mỗi nhân viên</h2>
          <SimpleTable
            rows={data.departments}
            columns={[
              { key: 'name', label: 'Phòng ban' },
              { key: 'headcount', label: 'Nhân sự đang làm', numeric: true },
              { ...money('supplies'), label: 'VPP & vật tư in' },
              { ...money('uniform'), label: 'Đồng phục' },
              { ...money('safety'), label: 'Bảo hộ' },
              { ...money('total'), label: 'Tổng chi phí' },
              { ...money('perEmployee'), label: 'ĐP + BH / NV' },
              { key: 'overdue', label: 'Dòng quá hạn', numeric: true },
              { key: 'soon', label: 'Dòng sắp đến hạn/chờ hàng', numeric: true },
            ]}
          />

          <h2>3. Top 10 mặt hàng tiêu thụ nhiều nhất</h2>
          <SimpleTable
            rows={data.top.map((row, index) => ({ ...row, rank: index + 1 }))}
            columns={[
              { key: 'rank', label: '#' },
              { key: 'itemCode', label: 'Mã hàng' },
              { key: 'itemName', label: 'Tên hàng' },
              { key: 'itemGroup', label: 'Nhóm' },
              { ...money('qty'), label: 'SL xuất kỳ' },
              { ...money('stock'), label: 'Tồn hiện tại' },
            ]}
          />

          <h2>4. Mặt hàng tồn lâu / ít sử dụng (không xuất ≥ {data.staleDays} ngày)</h2>
          <SimpleTable
            rows={data.stale}
            columns={[
              { key: 'itemCode', label: 'Mã hàng' },
              { key: 'itemName', label: 'Tên hàng' },
              { ...money('stock'), label: 'Tồn' },
              { ...money('value'), label: 'Giá trị tồn' },
              { key: 'idleDays', label: 'Ngày không xuất', numeric: true, render: (row) => row.idleDays ?? 'Chưa xuất' },
            ]}
          />
          <p className="warehouse-muted">Mặt hàng tồn dư (&gt; tồn tối đa): {data.over.length ? data.over.join(', ') : 'không có'}</p>

          <h2>5. Chỉ số đáp ứng &amp; mua khẩn cấp</h2>
          <SimpleTable
            rows={[
              ['Số dòng cấp phát đã xác nhận', formatNumber(data.kpis.confirmedLines)],
              ['Số lần thiếu hàng (dòng "Thiếu hàng")', formatNumber(data.kpis.shortageLines)],
              ['Tỷ lệ đáp ứng nhu cầu cấp phát', data.kpis.fulfillment === null ? '' : `${formatNumber(data.kpis.fulfillment, 1)}%`],
              ['Giá trị mua khẩn cấp (PO Khẩn cấp, không hủy)', formatNumber(data.kpis.urgentValue)],
              ['Tổng giá trị xuất kỳ', formatNumber(data.kpis.outValue)],
            ].map(([label, value]) => ({ label, value }))}
            columns={[{ key: 'label', label: 'Chỉ số' }, { key: 'value', label: 'Giá trị', numeric: true }]}
          />

          <h2>6. Ngân sách kế hoạch vs chi phí mua thực tế (năm {filters.year})</h2>
          <SimpleTable
            rows={data.budget}
            columns={[
              { key: 'label', label: 'Kỳ', render: (row) => row.bold ? <b>{row.label}</b> : row.label },
              { ...money('budget'), label: 'Ngân sách' },
              { ...money('actual'), label: 'Thực tế (Nhập mua)' },
              { key: 'diff', label: 'Chênh lệch', numeric: true, render: (row) => formatNumber(row.budget - row.actual) },
              { key: 'rate', label: '% sử dụng', numeric: true, render: (row) => row.budget ? `${formatNumber((row.actual / row.budget) * 100, 1)}%` : '' },
            ]}
          />

          <h2>7. Nhân viên đến kỳ / quá hạn cấp phát ({data.dueTotal})</h2>
          <SimpleTable
            rows={data.due}
            columns={[
              { key: 'userCode', label: 'Mã NV' },
              { key: 'name', label: 'Họ tên' },
              { key: 'departmentName', label: 'Phòng ban' },
              { key: 'itemCode', label: 'Mã hàng' },
              { key: 'last', label: 'Cấp gần nhất', render: (row) => formatDate(row.last) },
              { key: 'due', label: 'Đến hạn', render: (row) => formatDate(row.due) },
              { key: 'left', label: 'Còn lại (ngày)', numeric: true },
              { key: 'status', label: 'Trạng thái', render: (row) => <Badge value={row.status} labels={scheduleStatusLabels} /> },
            ]}
          />
          {data.dueTotal > data.due.length && <p className="warehouse-muted">Hiển thị {data.due.length}/{data.dueTotal} dòng – xem đầy đủ ở Lịch cấp phát.</p>}
        </>
      )}
    </section>
  )
}
