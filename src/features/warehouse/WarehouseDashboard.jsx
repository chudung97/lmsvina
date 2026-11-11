import { formatDate, formatNumber, issueStatusLabels, priorityLabels, purposeLabels, scheduleStatusLabels, stockStatusLabels } from './warehouseApi'
import { Badge, Messages, WarehouseTable } from './WarehouseUi'
import { useKhoResource } from './useKhoResource'

const barColors = { safe: '#70ad47', low: '#f2b200', over: '#2196f3' }

function Bars({ items, format = formatNumber }) {
  const max = Math.max(1, ...items.map((item) => item.value))
  return (
    <div className="warehouse-bars">
      {items.map((item) => (
        <div className="warehouse-bar" key={item.label}>
          <span>{item.label}</span>
          <div style={{ width: `${Math.max(1, (item.value / max) * 100)}%`, background: item.color || '#3974f6' }}></div>
          <strong>{format(item.value)}</strong>
        </div>
      ))}
    </div>
  )
}

export function WarehouseDashboard({ access, onIssue, onCreateOrder }) {
  const { data, error, loading } = useKhoResource('/api/kho/dashboard')
  const kpis = data?.kpis
  const cards = kpis ? [
    ['Tổng số mã hàng', formatNumber(kpis.itemCount), ''],
    ['Giá trị tồn kho (VND)', formatNumber(kpis.stockValue), ''],
    ['Nhập mua trong tháng (SL)', formatNumber(kpis.inMonth), ''],
    ['Xuất trong tháng (SL)', formatNumber(kpis.outMonth), ''],
    ['Mặt hàng SẮP HẾT', kpis.low, kpis.low ? 'yellow' : ''],
    ['Dưới tối thiểu / hết', kpis.under, kpis.under ? 'red' : ''],
    ['Mặt hàng CẦN ĐẶT MUA', kpis.needBuy, kpis.needBuy ? 'yellow' : ''],
    ['Mặt hàng TỒN QUÁ NHIỀU', kpis.over, kpis.over ? 'blue' : ''],
    ['NV sắp đến kỳ cấp phát', kpis.soonEmployees, kpis.soonEmployees ? 'yellow' : ''],
    ['NV QUÁ HẠN cấp phát', kpis.overdueEmployees, kpis.overdueEmployees ? 'red' : ''],
    ['Ngân sách mua dự kiến (VND)', formatNumber(kpis.budget), ''],
    ['Dòng lịch đang CHỜ HÀNG', kpis.waiting, kpis.waiting ? 'red' : ''],
  ] : []

  return (
    <>
      <Messages error={error} />
      {loading && !data && <p className="warehouse-muted">Đang tải tổng quan...</p>}
      {data && (
        <>
          <div className="warehouse-kpis">
            {cards.map(([label, value, tone]) => (
              <article className={`warehouse-kpi ${tone}`} key={label}>
                <span>{label}</span>
                <strong>{value}</strong>
              </article>
            ))}
          </div>
          <div className="warehouse-grid-2">
            <section className="warehouse-card">
              <h2>Công việc kho cần xử lý</h2>
              <div className="warehouse-table-wrap">
                <table className="warehouse-table compact">
                  <thead>
                    <tr>
                      <th>Công việc</th>
                      <th className="numeric">Quá hạn/hôm nay</th>
                      <th className="numeric">≤ 7 ngày</th>
                      <th className="numeric">≤ 15 ngày</th>
                      <th className="numeric">≤ 30 ngày</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.tasks.map((task) => (
                      <tr key={task.label}>
                        <td>{task.label}</td>
                        {task.values.map((value, index) => <td className="numeric" key={index}>{formatNumber(value)}</td>)}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </section>
            <section className="warehouse-card">
              <h2>Giá trị tồn theo nhóm</h2>
              <Bars items={data.valueByGroup.map((group) => ({ label: group.group, value: group.value }))} />
              <h2>Số mã theo trạng thái tồn</h2>
              <Bars items={data.statusCounts.map((status) => ({
                label: stockStatusLabels[status.status],
                value: status.count,
                color: barColors[status.status] || '#e53935',
              }))} />
            </section>
          </div>
          <section className="warehouse-card">
            <h2>Cấp phát sắp đến hạn / quá hạn (30 ngày tới) <span className="warehouse-muted">– {data.dueTotal} dòng</span></h2>
            <WarehouseTable
              rows={data.dueRows}
              rowKey="key"
              pageSize={15}
              searchText={(row) => `${row.userCode} ${row.name} ${row.itemCode}`}
              empty="Không có dòng nào đến hạn."
              columns={[
                { key: 'userCode', label: 'Mã NV' },
                { key: 'name', label: 'Họ tên' },
                { key: 'departmentName', label: 'Phòng ban' },
                { key: 'itemCode', label: 'Mã hàng' },
                { key: 'due', label: 'Ngày đến hạn', render: (row) => formatDate(row.due) },
                { key: 'left', label: 'Còn lại (ngày)', numeric: true },
                { key: 'status', label: 'Trạng thái', render: (row) => <Badge value={row.status} labels={scheduleStatusLabels} /> },
                {
                  key: 'action',
                  label: '',
                  render: (row) => access.canWrite && (
                    <button type="button" className="warehouse-button small" onClick={() => onIssue({ userId: row.userId, purpose: 'periodic', lines: [{ itemId: row.itemId, qty: row.qty }] })}>
                      Lập phiếu
                    </button>
                  ),
                },
              ]}
            />
          </section>
          <section className="warehouse-card">
            <h2>Mặt hàng cần đặt mua <span className="warehouse-muted">– {data.buyTotal} mã, ngân sách {formatNumber(data.kpis.budget)} đ</span></h2>
            <WarehouseTable
              rows={data.buyRows}
              rowKey="itemId"
              pageSize={10}
              searchText={(row) => `${row.itemCode} ${row.itemName}`}
              empty="Chưa có mặt hàng cần đặt."
              columns={[
                { key: 'itemCode', label: 'Mã hàng' },
                { key: 'itemName', label: 'Tên hàng' },
                { key: 'stock', label: 'Tồn', numeric: true },
                { key: 'onOrder', label: 'Đang đặt', numeric: true },
                { key: 'suggested', label: 'SL đề xuất', numeric: true },
                { key: 'budget', label: 'Ngân sách', numeric: true, render: (row) => formatNumber(row.budget) },
                { key: 'orderBy', label: 'Ngày cần đặt', render: (row) => formatDate(row.orderBy) },
                { key: 'priority', label: 'Ưu tiên', render: (row) => <Badge value={row.priority} labels={priorityLabels} /> },
                {
                  key: 'action',
                  label: '',
                  render: (row) => access.canWrite && (
                    <button type="button" className="warehouse-button small" onClick={() => onCreateOrder(row.itemId)}>Tạo PO</button>
                  ),
                },
              ]}
            />
          </section>
          <p className="warehouse-muted">
            Quy ước màu: Đỏ = thiếu hàng/đến hạn · Vàng = sắp hết/sắp đến lịch · Xanh lá = an toàn · Xanh dương = tồn dư.
            Ngày tham chiếu: {formatDate(data.ref)}
          </p>
        </>
      )}
    </>
  )
}

export function WarehouseMine() {
  const { data, error, loading } = useKhoResource('/api/kho/mine')

  return (
    <>
      <Messages error={error} />
      <section className="warehouse-card">
        <h2>Lịch cấp phát định kỳ của tôi</h2>
        <WarehouseTable
          loading={loading && !data}
          rows={data?.schedule || []}
          rowKey="key"
          searchText={(row) => `${row.itemCode} ${row.itemName}`}
          empty="Bạn chưa thuộc nhóm đối tượng được cấp phát định kỳ."
          columns={[
            { key: 'itemCode', label: 'Mã hàng' },
            { key: 'itemName', label: 'Tên hàng', render: (row) => row.itemName || (row.missingSize ? 'Chưa khai báo size' : 'Chưa có mã hàng phù hợp') },
            { key: 'last', label: 'Cấp gần nhất', render: (row) => formatDate(row.last) },
            { key: 'cycle', label: 'Chu kỳ (tháng)', numeric: true },
            { key: 'due', label: 'Đến hạn', render: (row) => formatDate(row.due) },
            { key: 'qty', label: 'SL dự kiến', numeric: true },
            { key: 'status', label: 'Trạng thái', render: (row) => <Badge value={row.status} labels={scheduleStatusLabels} /> },
          ]}
        />
      </section>
      <section className="warehouse-card">
        <h2>Đồ đã nhận</h2>
        <WarehouseTable
          loading={loading && !data}
          rows={data?.issues || []}
          searchText={(row) => `${row.voucher} ${row.itemCode} ${row.itemName}`}
          empty="Chưa có phiếu cấp phát nào."
          columns={[
            { key: 'voucher', label: 'Mã phiếu' },
            { key: 'date', label: 'Ngày', render: (row) => formatDate(row.date) },
            { key: 'itemCode', label: 'Mã hàng' },
            { key: 'itemName', label: 'Tên hàng' },
            { key: 'qty', label: 'SL', numeric: true },
            { key: 'unit', label: 'ĐVT' },
            { key: 'purpose', label: 'Mục đích', render: (row) => purposeLabels[row.purpose] },
            { key: 'status', label: 'Trạng thái', render: (row) => <Badge value={row.status} labels={issueStatusLabels} /> },
          ]}
        />
      </section>
    </>
  )
}
