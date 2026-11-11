import { useEffect, useState } from 'react'
import { requestApi, sendJson, staffRoleLabels } from './warehouseApi'
import { Messages } from './WarehouseUi'
import { IssueForm, ReceiptForm } from './WarehouseForms'
import { WarehouseDashboard, WarehouseMine } from './WarehouseDashboard'
import { WarehouseIssues, WarehouseReceipts } from './WarehouseTransactions'
import { WarehouseOrders, WarehousePlan, WarehouseSchedule, WarehouseStock } from './WarehouseInventory'
import { WarehouseReports } from './WarehouseReports'
import { WarehouseEmployees, WarehouseItems, WarehouseLogs, WarehouseSettings } from './WarehouseSetup'
import { useKhoResource } from './useKhoResource'
import './Warehouse.css'

const staffTabs = [
  ['dashboard', 'Tổng quan'],
  ['issues', 'Xuất kho / Cấp phát'],
  ['receipts', 'Nhập kho'],
  ['stock', 'Tồn kho'],
  ['schedule', 'Lịch cấp phát'],
  ['plan', 'Kế hoạch mua'],
  ['orders', 'Đơn đặt hàng (PO)'],
  ['reports', 'Báo cáo'],
  ['items', 'Danh mục hàng'],
  ['employees', 'Nhân viên & size'],
  ['settings', 'Thiết lập'],
  ['logs', 'Nhật ký'],
  ['mine', 'Của tôi'],
]

/** Quản lý kho HR-GA: VPP, vật tư máy in, đồng phục, bảo hộ lao động. */
function Warehouse() {
  const { data: access, error } = useKhoResource('/api/kho/me')
  const [tab, setTab] = useState('dashboard')
  const [issueDraft, setIssueDraft] = useState(null)
  const [receiptDraft, setReceiptDraft] = useState(null)
  const [settings, setSettings] = useState(null)
  const [notice, setNotice] = useState('')
  const [actionError, setActionError] = useState('')
  const [refreshKey, setRefreshKey] = useState(0)

  useEffect(() => {
    if (!access?.canView) return
    requestApi('/api/kho/settings').then((result) => setSettings(result.settings)).catch(() => {})
  }, [access?.canView])

  const finish = (setter) => (message) => {
    setter(null)
    setActionError('')
    setNotice(message)
    setRefreshKey((current) => current + 1)
  }

  const createOrder = async (itemId) => {
    setNotice('')
    setActionError('')
    try {
      const result = await sendJson('/api/kho/orders/from-plan', 'POST', { itemIds: [itemId] })
      setNotice(`Đã tạo PO (Chờ duyệt): ${result.created.join(', ')}`)
      setRefreshKey((current) => current + 1)
    } catch (requestError) {
      setActionError(requestError.message)
    }
  }

  const canView = Boolean(access?.canView)
  const activeTab = canView ? tab : 'mine'
  const shared = { access: access || {}, onIssue: setIssueDraft, onReceive: setReceiptDraft }

  return (
    <section className="warehouse-page">
      <header className="warehouse-header">
        <div>
          <p className="meetings-eyebrow">HÀNH CHÍNH · KHO HR-GA</p>
          <h1>Quản lý kho</h1>
          <p className="meetings-subtitle">Văn phòng phẩm · Vật tư máy in · Đồng phục · Bảo hộ lao động</p>
        </div>
        {access && (
          <div className="warehouse-role-card">
            <span>Quyền kho</span>
            <strong>{access.role ? staffRoleLabels[access.role] : 'Nhân viên'}</strong>
          </div>
        )}
      </header>

      {canView && (
        <nav className="warehouse-tabs" aria-label="Chức năng kho">
          {staffTabs.map(([key, label]) => (
            <button
              key={key}
              type="button"
              className={activeTab === key ? 'active' : ''}
              aria-current={activeTab === key ? 'page' : undefined}
              onClick={() => {
                setTab(key)
                setNotice('')
                setActionError('')
              }}
            >
              {label}
            </button>
          ))}
        </nav>
      )}

      <div className="warehouse-body">
        <Messages error={error || actionError} notice={notice} />
        {access && !canView && (
          <p className="warehouse-message warning">
            Bạn đang xem đồ được cấp phát của mình. Để quản lý kho, nhờ quản lý kho hoặc admin cấp quyền tại Quản lý kho → Thiết lập.
          </p>
        )}
        {access && (
          <div key={`${activeTab}-${refreshKey}`}>
            {activeTab === 'dashboard' && <WarehouseDashboard {...shared} onCreateOrder={createOrder} />}
            {activeTab === 'issues' && <WarehouseIssues {...shared} />}
            {activeTab === 'receipts' && <WarehouseReceipts {...shared} />}
            {activeTab === 'stock' && <WarehouseStock />}
            {activeTab === 'schedule' && <WarehouseSchedule {...shared} />}
            {activeTab === 'plan' && <WarehousePlan {...shared} />}
            {activeTab === 'orders' && <WarehouseOrders {...shared} />}
            {activeTab === 'reports' && <WarehouseReports itemGroups={access.itemGroups || []} />}
            {activeTab === 'items' && <WarehouseItems {...shared} itemGroups={access.itemGroups || []} />}
            {activeTab === 'employees' && <WarehouseEmployees {...shared} />}
            {activeTab === 'settings' && <WarehouseSettings {...shared} />}
            {activeTab === 'logs' && <WarehouseLogs />}
            {activeTab === 'mine' && <WarehouseMine />}
          </div>
        )}
      </div>

      {issueDraft && (
        <IssueForm draft={issueDraft} settings={settings} onClose={() => setIssueDraft(null)} onSaved={finish(setIssueDraft)} />
      )}
      {receiptDraft && (
        <ReceiptForm draft={receiptDraft} onClose={() => setReceiptDraft(null)} onSaved={finish(setReceiptDraft)} />
      )}
    </section>
  )
}

export default Warehouse
