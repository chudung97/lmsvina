import { useState } from 'react'
import { SearchableSelect } from '../../components/SearchableControls'
import { downloadCsv, formatDate, formatNumber, readCsvFile, requestApi, sendJson, sizeTypeLabels, staffRoleLabels, warningLabels } from './warehouseApi'
import { Badge, Messages, WarehouseModal, WarehouseTable } from './WarehouseUi'
import { useKhoResource } from './useKhoResource'

const itemFields = [
  { key: 'code', label: 'Mã hàng', required: true },
  { key: 'name', label: 'Tên hàng', required: true },
  { key: 'group', label: 'Nhóm hàng', required: true, type: 'group' },
  { key: 'unit', label: 'Đơn vị tính', required: true },
  { key: 'color', label: 'Màu sắc' },
  { key: 'size', label: 'Size' },
  { key: 'spec', label: 'Quy cách' },
  { key: 'supplier', label: 'Nhà cung cấp' },
  { key: 'price', label: 'Đơn giá (VND)', number: true },
  { key: 'min', label: 'Tồn tối thiểu', number: true },
  { key: 'max', label: 'Tồn tối đa', number: true },
  { key: 'lead', label: 'Thời gian giao hàng (ngày)', number: true },
  { key: 'cycle', label: 'Chu kỳ cấp phát (tháng, 0 = không định kỳ)', number: true },
  { key: 'perPerson', label: 'Định mức/người', number: true },
  { key: 'moq', label: 'Quy cách đóng gói/SL đặt tối thiểu', number: true },
  { key: 'opening', label: 'Tồn đầu kỳ', number: true },
  { key: 'family', label: 'Dòng SP (nhóm các size)' },
  { key: 'note', label: 'Ghi chú' },
]

const emptyItem = { group: 'Văn phòng phẩm', unit: 'Cái', price: 0, min: 0, max: 0, lead: 7, cycle: 0, perPerson: 0, moq: 1, opening: 0, active: true }

function ItemForm({ item, itemGroups, onClose, onSaved }) {
  const [form, setForm] = useState(() => ({ ...emptyItem, ...item }))
  const [error, setError] = useState('')

  const submit = async (event) => {
    event.preventDefault()
    try {
      if (item?.id) await requestApi(`/api/kho/items/${item.id}`, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(form) })
      else await sendJson('/api/kho/items', 'POST', form)
      onSaved(`Đã lưu mã hàng ${form.code}.`)
    } catch (requestError) {
      setError(requestError.message)
    }
  }

  return (
    <WarehouseModal title={item?.id ? `Sửa mã hàng ${item.code}` : 'Thêm mã hàng'} onClose={onClose} wide>
      <Messages error={error} />
      <form onSubmit={submit}>
        <div className="warehouse-form-grid">
          {itemFields.map((field) => (
            <label key={field.key}>
              <span>{field.label}{field.required ? ' *' : ''}</span>
              {field.type === 'group' ? (
                <select value={form.group} onChange={(event) => setForm({ ...form, group: event.target.value })}>
                  {itemGroups.map((group) => <option key={group} value={group}>{group}</option>)}
                </select>
              ) : (
                <input
                  type={field.number ? 'number' : 'text'}
                  min={field.number ? 0 : undefined}
                  step={field.key === 'price' ? 'any' : undefined}
                  required={field.required}
                  readOnly={field.key === 'code' && Boolean(item?.id)}
                  value={form[field.key] ?? ''}
                  onChange={(event) => setForm({ ...form, [field.key]: event.target.value })}
                />
              )}
            </label>
          ))}
          <label className="warehouse-check">
            <input type="checkbox" checked={Boolean(form.active)} onChange={(event) => setForm({ ...form, active: event.target.checked })} />
            <span>Đang sử dụng</span>
          </label>
        </div>
        <div className="warehouse-actions">
          <button type="submit" className="warehouse-button primary">Lưu</button>
          <button type="button" className="warehouse-button" onClick={onClose}>Đóng</button>
        </div>
      </form>
    </WarehouseModal>
  )
}

export function WarehouseItems({ access, itemGroups }) {
  const [group, setGroup] = useState('')
  const [editing, setEditing] = useState(null)
  const [message, setMessage] = useState({ error: '', notice: '' })
  const { data, error, loading, reload } = useKhoResource('/api/kho/items')
  const rows = (data || []).filter((row) => !group || row.group === group)

  const act = async (action) => {
    setMessage({ error: '', notice: '' })
    try {
      const notice = await action()
      if (notice) setMessage({ error: '', notice })
      reload()
    } catch (requestError) {
      setMessage({ error: requestError.message, notice: '' })
    }
  }

  const columns = [
    ...itemFields.map((field) => ({
      key: field.key,
      label: field.label,
      numeric: field.number,
      render: field.number ? (row) => formatNumber(row[field.key]) : undefined,
      csv: (row) => row[field.key],
    })),
    { key: 'active', label: 'Đang sử dụng', value: (row) => row.active ? 'Có' : 'Ngừng', csv: (row) => row.active ? 1 : 0 },
    { key: 'warning', label: 'Cảnh báo', render: (row) => <Badge value={row.warning} labels={warningLabels} />, csv: false },
    {
      key: 'actions',
      label: '',
      csv: false,
      render: (row) => access.canManage && (
        <div className="warehouse-row-actions">
          <button type="button" className="warehouse-button small" onClick={() => setEditing(row)}>Sửa</button>
          {!row.hasTransactions && (
            <button type="button" className="warehouse-button small" onClick={() => window.confirm(`Xóa mã ${row.code}?`) && act(async () => {
              await requestApi(`/api/kho/items/${row.id}`, { method: 'DELETE' })
              return `Đã xóa ${row.code}.`
            })}>Xóa</button>
          )}
        </div>
      ),
    },
  ]

  const importCsv = () => act(async () => {
    const parsed = await readCsvFile([...itemFields, { key: 'active', label: 'Đang sử dụng' }])
    const result = await sendJson('/api/kho/items/import', 'POST', { rows: parsed })
    return `Nhập CSV: thêm ${result.added}, cập nhật ${result.updated}, lỗi ${result.errors.length}${result.errors.length ? ` – ${result.errors.slice(0, 3).join(' ')}` : ''}`
  })

  return (
    <section className="warehouse-card">
      <p className="warehouse-muted">
        Mỗi mã hàng = một dòng (khác size/màu/mẫu → khác mã). Hàng cấp phát định kỳ đặt mã theo dạng <code>DÒNG-SP-SIZE</code> (ví dụ DP-POLO-M)
        và điền cột "Dòng SP" để lịch cấp phát tự chọn đúng size cho nhân viên.
      </p>
      <div className="warehouse-filters">
        {access.canManage && <button type="button" className="warehouse-button primary" onClick={() => setEditing({})}>+ Thêm mã hàng</button>}
        <label>
          <span>Nhóm hàng</span>
          <select value={group} onChange={(event) => setGroup(event.target.value)}>
            <option value="">Mọi nhóm</option>
            {itemGroups.map((name) => <option key={name} value={name}>{name}</option>)}
          </select>
        </label>
        <button type="button" className="warehouse-button" onClick={() => downloadCsv('danh-muc-hang.csv', columns, rows)}>Xuất CSV</button>
        {access.canManage && <button type="button" className="warehouse-button" onClick={importCsv}>Nhập CSV</button>}
      </div>
      <Messages error={message.error || error} notice={message.notice} />
      <WarehouseTable loading={loading && !data} rows={rows} columns={columns} searchText={(row) => `${row.code} ${row.name} ${row.supplier} ${row.family}`} />
      {editing && (
        <ItemForm
          item={editing}
          itemGroups={itemGroups}
          onClose={() => setEditing(null)}
          onSaved={(notice) => {
            setEditing(null)
            setMessage({ error: '', notice })
            reload()
          }}
        />
      )}
    </section>
  )
}

function SizeForm({ employee, shirtSizes, shoeSizes, onClose, onSaved }) {
  const [form, setForm] = useState({ shirtSize: employee.shirtSize, pantsSize: employee.pantsSize, shoeSize: employee.shoeSize })
  const [error, setError] = useState('')
  const select = (key, options) => (
    <select value={form[key]} onChange={(event) => setForm({ ...form, [key]: event.target.value })}>
      <option value="">(không)</option>
      {options.map((size) => <option key={size} value={size}>{size}</option>)}
    </select>
  )

  const submit = async (event) => {
    event.preventDefault()
    try {
      await requestApi(`/api/kho/employees/${employee.id}/sizes`, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(form) })
      onSaved(`Đã cập nhật size cho ${employee.name}.`)
    } catch (requestError) {
      setError(requestError.message)
    }
  }

  return (
    <WarehouseModal title={`Size của ${employee.name}`} onClose={onClose}>
      <Messages error={error} />
      <form onSubmit={submit}>
        <div className="warehouse-form-grid">
          <label><span>Size áo</span>{select('shirtSize', shirtSizes)}</label>
          <label><span>Size quần</span>{select('pantsSize', shirtSizes)}</label>
          <label><span>Size giày</span>{select('shoeSize', shoeSizes)}</label>
        </div>
        <div className="warehouse-actions">
          <button type="submit" className="warehouse-button primary">Lưu</button>
          <button type="button" className="warehouse-button" onClick={onClose}>Đóng</button>
        </div>
      </form>
    </WarehouseModal>
  )
}

const sizeCsvColumns = [
  { key: 'userCode', label: 'Mã nhân viên' },
  { key: 'name', label: 'Họ và tên' },
  { key: 'departmentName', label: 'Phòng ban' },
  { key: 'shirtSize', label: 'Size áo' },
  { key: 'pantsSize', label: 'Size quần' },
  { key: 'shoeSize', label: 'Size giày' },
]

export function WarehouseEmployees({ access }) {
  const [department, setDepartment] = useState('')
  const [editing, setEditing] = useState(null)
  const [message, setMessage] = useState({ error: '', notice: '' })
  const { data, error, loading, reload } = useKhoResource('/api/kho/employees')
  const rows = (data?.rows || []).filter((row) => !department || row.departmentName === department)
  const departments = [...new Set((data?.rows || []).map((row) => row.departmentName).filter(Boolean))].sort()

  const columns = [
    { key: 'code', label: 'Mã nhân viên' },
    { key: 'name', label: 'Họ và tên' },
    { key: 'departmentName', label: 'Phòng ban' },
    { key: 'group', label: 'Nhóm đối tượng', value: (row) => row.group || 'Chưa phân nhóm' },
    { key: 'hireDate', label: 'Ngày vào làm', render: (row) => formatDate(row.hireDate) },
    { key: 'onLeave', label: 'Nghỉ tạm thời', value: (row) => row.onLeave ? 'Có' : '' },
    { key: 'shirtSize', label: 'Size áo' },
    { key: 'pantsSize', label: 'Size quần' },
    { key: 'shoeSize', label: 'Size giày' },
    { key: 'lastIssue', label: 'Cấp gần nhất', render: (row) => formatDate(row.lastIssue) },
    { key: 'nextDue', label: 'Đến hạn kế tiếp', render: (row) => formatDate(row.nextDue) },
    {
      key: 'actions',
      label: '',
      csv: false,
      render: (row) => access.canWrite && <button type="button" className="warehouse-button small" onClick={() => setEditing(row)}>Sửa size</button>,
    },
  ]

  const importCsv = async () => {
    setMessage({ error: '', notice: '' })
    try {
      const parsed = await readCsvFile(sizeCsvColumns)
      const result = await sendJson('/api/kho/employees/sizes/import', 'POST', { rows: parsed })
      setMessage({ error: '', notice: `Cập nhật size ${result.updated} NV, lỗi ${result.errors.length}${result.errors.length ? ` – ${result.errors.slice(0, 3).join(' ')}` : ''}` })
      reload()
    } catch (requestError) {
      setMessage({ error: requestError.message, notice: '' })
    }
  }

  return (
    <section className="warehouse-card">
      <p className="warehouse-muted">
        Danh sách lấy trực tiếp từ tài khoản nhân viên đang hoạt động của Workdesk/LMS (họ tên, phòng ban, ngày vào làm chỉ đọc).
        Kho chỉ lưu thêm size áo/quần/giày để cấp đúng mã hàng.
      </p>
      <div className="warehouse-filters">
        <label>
          <span>Phòng ban</span>
          <select value={department} onChange={(event) => setDepartment(event.target.value)}>
            <option value="">Mọi phòng ban</option>
            {departments.map((name) => <option key={name} value={name}>{name}</option>)}
          </select>
        </label>
        <button type="button" className="warehouse-button" onClick={() => downloadCsv('size-nhan-vien.csv', sizeCsvColumns.map((column) => ({ ...column, csv: (row) => column.key === 'userCode' ? row.code : row[column.key] })), rows)}>
          Xuất CSV size
        </button>
        {access.canWrite && <button type="button" className="warehouse-button" onClick={importCsv}>Nhập CSV size</button>}
      </div>
      <Messages error={message.error || error} notice={message.notice} />
      <WarehouseTable loading={loading && !data} rows={rows} columns={columns} searchText={(row) => `${row.code} ${row.name} ${row.departmentName}`} />
      {editing && (
        <SizeForm
          employee={editing}
          shirtSizes={data.shirtSizes}
          shoeSizes={data.shoeSizes}
          onClose={() => setEditing(null)}
          onSaved={(notice) => {
            setEditing(null)
            setMessage({ error: '', notice })
            reload()
          }}
        />
      )}
    </section>
  )
}

const parameterFields = [
  ['refDate', 'Ngày tham chiếu (để trống = hôm nay)', 'date', 'Dùng để chốt báo cáo/kiểm thử; mốc ghi giao dịch luôn là giờ thật'],
  ['scheduleStart', 'Ngày bắt đầu áp dụng lịch cấp phát', 'date', 'NV chưa từng được cấp sẽ đến hạn không sớm hơn ngày này'],
  ['remindDays', 'Nhắc trước kỳ cấp phát (ngày)', 'number'],
  ['safetyDays', 'Tồn an toàn (số ngày tiêu thụ)', 'number'],
  ['reviewDays', 'Chu kỳ rà soát/đặt hàng (ngày)', 'number'],
  ['avgMonths', 'Số tháng tính mức dùng bình quân', 'number'],
  ['firstWait', 'Chờ cấp lần đầu sau ngày vào làm (ngày)', 'number'],
  ['soonFactor', 'Ngưỡng SẮP HẾT (× tồn tối thiểu)', 'number'],
  ['staleDays', 'Ngưỡng tồn lâu (ngày không xuất)', 'number'],
  ['overFactor', 'Hệ số cảnh báo tồn dư (× tồn tối đa)', 'number'],
  ['remindGap', 'Giãn cách giữa 2 lần nhắc cùng một dòng (ngày)', 'number'],
  ['emailTo', 'Email nhận nhắc lịch (HR-GA)', 'email'],
  ['receiverName', 'Thủ kho – nhập', 'text'],
  ['giverName', 'Thủ kho – xuất/giao', 'text'],
  ['stocktakeName', 'Người kiểm kê', 'text'],
  ['approverName', 'Người phê duyệt mua hàng', 'text'],
]

function settingsForm(data) {
  const policyText = {}
  for (const [group, codes] of Object.entries(data.policies || {})) policyText[group] = codes.join(', ')
  return {
    settings: { ...data.settings },
    families: data.families.map((family) => ({ ...family })),
    departments: data.departments.map((department) => ({ ...department })),
    policyText,
    budget: [...data.budget.months],
  }
}

export function WarehouseSettings({ access }) {
  const [year, setYear] = useState(() => new Date().getFullYear())
  const url = `/api/kho/settings?year=${year}`
  const { data, error, stamp, reload } = useKhoResource(url)
  const [formStamp, setFormStamp] = useState(null)
  const [refreshAfter, setRefreshAfter] = useState(null)
  const [message, setMessage] = useState({ error: '', notice: '' })

  // Form chỉ khởi tạo lại khi đổi năm hoặc khi dữ liệu mới về sau lần lưu,
  // để cập nhật realtime từ người khác không xóa phần đang sửa dở.
  const fresh = data && stamp?.startsWith(`${url}|`)
  if (fresh && (formStamp === null || (refreshAfter !== null && stamp !== refreshAfter))) {
    setFormStamp(stamp)
    setRefreshAfter(null)
  }

  if (!data || formStamp === null) return <><Messages error={error} /><p className="warehouse-muted">Đang tải thiết lập...</p></>

  return (
    <SettingsEditor
      key={formStamp}
      access={access}
      data={data}
      message={message}
      setMessage={setMessage}
      loadError={error}
      year={year}
      setYear={(value) => {
        setYear(value)
        setFormStamp(null)
      }}
      onSaved={() => {
        setRefreshAfter(stamp)
        reload()
      }}
      refresh={reload}
    />
  )
}

function SettingsEditor({ access, data, message, setMessage, loadError, year, setYear, onSaved, refresh }) {
  const { data: employees } = useKhoResource('/api/kho/employees')
  const [form, setForm] = useState(() => settingsForm(data))
  const [staffPick, setStaffPick] = useState({ userId: '', role: 'keeper' })
  const readOnly = !access.canManage
  const reload = onSaved
  const error = loadError

  const groups = [...new Set([...form.departments.map((department) => department.group.trim()), ...Object.keys(form.policyText)].filter(Boolean))].sort()
  const setSetting = (key, value) => setForm({ ...form, settings: { ...form.settings, [key]: value } })

  const save = async () => {
    setMessage({ error: '', notice: '' })
    const policies = {}
    for (const group of groups) {
      policies[group] = (form.policyText[group] || '').split(',').map((code) => code.trim()).filter(Boolean)
    }
    try {
      await sendJson('/api/kho/settings', 'PUT', {
        settings: form.settings,
        families: form.families.filter((family) => family.code.trim()),
        policies,
        departmentGroups: form.departments.map((department) => ({ departmentId: department.id, group: department.group })),
        budget: { year, months: form.budget.map((value) => Number(value) || 0) },
      })
      setMessage({ error: '', notice: 'Đã lưu thiết lập. Lịch cấp phát và kế hoạch mua được tính lại.' })
      reload()
    } catch (requestError) {
      setMessage({ error: requestError.message, notice: '' })
    }
  }

  const setStaff = async (userId, role) => {
    setMessage({ error: '', notice: '' })
    try {
      await sendJson(`/api/kho/staff/${userId}`, 'PUT', { role })
      setMessage({ error: '', notice: role ? 'Đã cập nhật quyền kho.' : 'Đã thu hồi quyền kho.' })
      setStaffPick({ userId: '', role: 'keeper' })
      refresh()
    } catch (requestError) {
      setMessage({ error: requestError.message, notice: '' })
    }
  }

  return (
    <>
      <Messages error={message.error || error} notice={message.notice} />
      {readOnly && <p className="warehouse-message warning">Chỉ quản lý kho mới được thay đổi thiết lập.</p>}

      <section className="warehouse-card">
        <h2>Nhân sự kho &amp; phân quyền</h2>
        <p className="warehouse-muted">
          Quản lý kho: toàn quyền, duyệt PO, hủy giao dịch đã ghi sổ, thiết lập. Thủ kho: nhập/xuất, lập PO, sửa size NV. Chỉ xem: xem mọi màn hình.
          Admin hệ thống và Tổng giám đốc luôn có toàn quyền: {(data.leaders || []).map((leader) => leader.name).join(', ') || '—'}.
        </p>
        <div className="warehouse-table-wrap">
          <table className="warehouse-table compact">
            <thead><tr><th>Mã NV</th><th>Họ tên</th><th>Vai trò</th><th></th></tr></thead>
            <tbody>
              {data.staff.map((person) => (
                <tr key={person.id}>
                  <td>{person.userCode}</td>
                  <td>{person.name}</td>
                  <td>
                    <select disabled={readOnly} value={person.role} onChange={(event) => setStaff(person.id, event.target.value)}>
                      {Object.entries(staffRoleLabels).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                    </select>
                  </td>
                  <td>{!readOnly && <button type="button" className="warehouse-button small" onClick={() => setStaff(person.id, null)}>Thu hồi</button>}</td>
                </tr>
              ))}
              {!data.staff.length && <tr><td className="warehouse-empty" colSpan="4">Chưa phân quyền kho cho ai.</td></tr>}
            </tbody>
          </table>
        </div>
        {!readOnly && (
          <div className="warehouse-filters">
            <label className="wide">
              <span>Thêm người</span>
              <SearchableSelect
                label="Nhân viên kho"
                value={staffPick.userId}
                options={(employees?.rows || []).map((person) => ({ value: person.id, label: `${person.code ? `${person.code} · ` : ''}${person.name}`, searchText: person.departmentName, department: person.departmentName || null }))}
                onChange={(userId) => setStaffPick({ ...staffPick, userId })}
              />
            </label>
            <label>
              <span>Vai trò</span>
              <select value={staffPick.role} onChange={(event) => setStaffPick({ ...staffPick, role: event.target.value })}>
                {Object.entries(staffRoleLabels).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
              </select>
            </label>
            <button type="button" className="warehouse-button primary" disabled={!staffPick.userId} onClick={() => setStaff(staffPick.userId, staffPick.role)}>Cấp quyền</button>
          </div>
        )}
      </section>

      <section className="warehouse-card">
        <h2>Tham số hệ thống &amp; người phụ trách</h2>
        <div className="warehouse-form-grid">
          {parameterFields.map(([key, label, type, hint]) => (
            <label key={key} title={hint}>
              <span>{label}</span>
              <input
                type={type}
                step={type === 'number' ? 'any' : undefined}
                disabled={readOnly}
                value={form.settings[key] ?? ''}
                onChange={(event) => setSetting(key, event.target.value)}
              />
            </label>
          ))}
          <label>
            <span>Phương pháp định giá tồn kho</span>
            <select disabled={readOnly} value={form.settings.valuation} onChange={(event) => setSetting('valuation', event.target.value)}>
              <option value="avg">Bình quân gia quyền</option>
              <option value="std">Giá chuẩn danh mục</option>
            </select>
          </label>
        </div>
      </section>

      <section className="warehouse-card">
        <h2>Dòng sản phẩm (nhóm các size)</h2>
        <p className="warehouse-muted">Mã hàng của từng size = Mã dòng SP + "-" + size của nhân viên (ví dụ DP-POLO + M → DP-POLO-M).</p>
        <div className="warehouse-table-wrap">
          <table className="warehouse-table compact">
            <thead><tr><th>Mã dòng SP</th><th>Tên</th><th>Loại size</th><th></th></tr></thead>
            <tbody>
              {form.families.map((family, index) => {
                const change = (key, value) => setForm({ ...form, families: form.families.map((current, position) => position === index ? { ...current, [key]: value } : current) })
                return (
                  <tr key={index}>
                    <td><input disabled={readOnly} value={family.code} onChange={(event) => change('code', event.target.value)} /></td>
                    <td><input disabled={readOnly} value={family.name} onChange={(event) => change('name', event.target.value)} /></td>
                    <td>
                      <select disabled={readOnly} value={family.sizeType} onChange={(event) => change('sizeType', event.target.value)}>
                        {Object.entries(sizeTypeLabels).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                      </select>
                    </td>
                    <td>{!readOnly && <button type="button" className="warehouse-button small" onClick={() => setForm({ ...form, families: form.families.filter((_, position) => position !== index) })}>Xóa</button>}</td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
        {!readOnly && (
          <button type="button" className="warehouse-button small" onClick={() => setForm({ ...form, families: [...form.families, { code: '', name: '', sizeType: 'shirt' }] })}>
            + Thêm dòng SP
          </button>
        )}
      </section>

      <section className="warehouse-card">
        <h2>Phòng ban → nhóm đối tượng cấp phát</h2>
        <p className="warehouse-muted">Phòng ban lấy từ danh mục phòng ban của Workdesk. Đặt tên nhóm (ví dụ Văn phòng, Sản xuất, Kho - Kỹ thuật); để trống = không cấp phát định kỳ.</p>
        <datalist id="warehouse-groups">{groups.map((group) => <option key={group} value={group} />)}</datalist>
        <div className="warehouse-table-wrap">
          <table className="warehouse-table compact">
            <thead><tr><th>Phòng ban</th><th>Nhóm đối tượng</th></tr></thead>
            <tbody>
              {form.departments.map((department, index) => (
                <tr key={department.id}>
                  <td>{department.name}</td>
                  <td>
                    <input
                      list="warehouse-groups"
                      disabled={readOnly}
                      value={department.group}
                      onChange={(event) => setForm({ ...form, departments: form.departments.map((current, position) => position === index ? { ...current, group: event.target.value } : current) })}
                    />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <h2>Chính sách cấp phát định kỳ theo nhóm đối tượng</h2>
        <p className="warehouse-muted">Nhập các mã dòng SP, cách nhau dấu phẩy. Đổi ở đây là lịch tự đổi.</p>
        <div className="warehouse-form-grid">
          {groups.map((group) => (
            <label key={group} className="span-2">
              <span>{group}</span>
              <input
                disabled={readOnly}
                placeholder="Ví dụ: DP-POLO, DP-QUAN, BH-GIAY"
                value={form.policyText[group] || ''}
                onChange={(event) => setForm({ ...form, policyText: { ...form.policyText, [group]: event.target.value } })}
              />
            </label>
          ))}
          {!groups.length && <p className="warehouse-muted">Gán nhóm đối tượng cho phòng ban trước.</p>}
        </div>
      </section>

      <section className="warehouse-card">
        <div className="warehouse-toolbar">
          <h2>Ngân sách mua hàng theo tháng (VND)</h2>
          <label>
            <span>Năm </span>
            <input type="number" min="2000" max="2100" value={year} onChange={(event) => setYear(Number(event.target.value) || year)} />
          </label>
        </div>
        <div className="warehouse-form-grid">
          {form.budget.map((value, index) => (
            <label key={index}>
              <span>Tháng {index + 1}</span>
              <input
                type="number"
                min="0"
                disabled={readOnly}
                value={value}
                onChange={(event) => setForm({ ...form, budget: form.budget.map((current, position) => position === index ? event.target.value : current) })}
              />
            </label>
          ))}
        </div>
      </section>

      {!readOnly && (
        <div className="warehouse-actions sticky">
          <button type="button" className="warehouse-button primary" onClick={save}>Lưu thiết lập</button>
        </div>
      )}
    </>
  )
}

export function WarehouseLogs() {
  const { data, error, loading } = useKhoResource('/api/kho/logs')

  return (
    <section className="warehouse-card">
      <p className="warehouse-muted">Mọi thao tác xác nhận/hủy/lập phiếu/nhập kho/tạo PO/thiết lập đều được ghi lại (không xóa được từ giao diện). Hiển thị 500 thao tác gần nhất.</p>
      <Messages error={error} />
      <WarehouseTable
        loading={loading && !data}
        rows={data || []}
        searchText={(row) => `${row.action} ${row.reference} ${row.detail} ${row.userName}`}
        columns={[
          { key: 'createdAt', label: 'Thời điểm' },
          { key: 'userName', label: 'Người thao tác' },
          { key: 'action', label: 'Thao tác' },
          { key: 'reference', label: 'Mã' },
          { key: 'detail', label: 'Chi tiết' },
        ]}
      />
    </section>
  )
}
