import { useCallback, useEffect, useMemo, useState } from 'react'
import './MealReports.css'

const shifts = [
  ['morning', 'Sáng'],
  ['noon', 'Trưa'],
  ['afternoon', 'Chiều'],
  ['night', 'Đêm'],
]

function todayInVietnam() {
  return new Date(Date.now() + 7 * 60 * 60 * 1000).toISOString().slice(0, 10)
}

async function requestApi(url, options = {}) {
  const response = await fetch(url, options)
  const result = await response.json()
  if (!response.ok) throw new Error(result.error || 'Không thể kết nối máy chủ.')
  return result
}

function MealReports() {
  const [date, setDate] = useState(todayInVietnam)
  const [shift, setShift] = useState('morning')
  const [report, setReport] = useState(null)
  const [quantities, setQuantities] = useState({})
  const [reporterSelections, setReporterSelections] = useState({})
  const [cutoffs, setCutoffs] = useState({})
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  const load = useCallback(async () => {
    const result = await requestApi(
      `/api/meal-reports?date=${encodeURIComponent(date)}&shift=${encodeURIComponent(shift)}`,
    )
    setReport(result)
    setCutoffs(Object.fromEntries(result.cutoffs.map((item) => [item.shift, item.cutoffTime])))
    setReporterSelections(Object.fromEntries(result.departments.map((department) => [
      department.id,
      department.workshops[0]?.reporterId || '',
    ])))
    const nextQuantities = {}
    for (const department of result.departments) {
      for (const workshop of department.workshops) {
        nextQuantities[`vietnamese:${department.id}:${workshop.workshop}`] = workshop.vietnameseQty ?? ''
      }
    }
    for (const meal of result.koreanMeals) {
      nextQuantities[`korean:${meal.workshop}`] = meal.koreanQty ?? ''
    }
    setQuantities(nextQuantities)
  }, [date, shift])

  useEffect(() => {
    let active = true
    setReport(null)
    setError('')
    setNotice('')
    requestApi(`/api/meal-reports?date=${encodeURIComponent(date)}&shift=${encodeURIComponent(shift)}`)
      .then((result) => {
        if (!active) return
        setReport(result)
        setCutoffs(Object.fromEntries(result.cutoffs.map((item) => [item.shift, item.cutoffTime])))
        setReporterSelections(Object.fromEntries(result.departments.map((department) => [
          department.id,
          department.workshops[0]?.reporterId || '',
        ])))
        const nextQuantities = {}
        for (const department of result.departments) {
          for (const workshop of department.workshops) {
            nextQuantities[`vietnamese:${department.id}:${workshop.workshop}`] = workshop.vietnameseQty ?? ''
          }
        }
        for (const meal of result.koreanMeals) {
          nextQuantities[`korean:${meal.workshop}`] = meal.koreanQty ?? ''
        }
        setQuantities(nextQuantities)
        setError('')
      })
      .catch((requestError) => {
        if (active) setError(requestError.message)
      })
    return () => { active = false }
  }, [date, shift])

  const totals = useMemo(() => ({
    vietnamese: (report?.departments || []).reduce((total, department) => (
      total + department.workshops.reduce((subtotal, workshop) => subtotal + Number(workshop.vietnameseQty || 0), 0)
    ), 0),
    korean: (report?.koreanMeals || []).reduce((total, meal) => total + Number(meal.koreanQty || 0), 0),
  }), [report])

  const saveQuantity = async (department, workshop, mealType) => {
    const key = mealType === 'korean'
      ? `korean:${workshop.workshop}`
      : `vietnamese:${department.id}:${workshop.workshop}`
    const quantity = quantities[key]
    if (quantity === '' || !Number.isInteger(Number(quantity)) || Number(quantity) < 0) {
      setError('Số suất phải là số nguyên từ 0 trở lên.')
      return
    }
    setBusy(true)
    setError('')
    setNotice('')
    try {
      const body = {
        date,
        shift,
        workshop: workshop.workshop,
        mealType,
        quantity: Number(quantity),
      }
      if (department) body.departmentId = department.id
      await requestApi('/api/meal-reports', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      })
      setNotice(department
        ? `Đã lưu số suất Việt cho ${department.name}.`
        : `Đã lưu tổng suất Hàn cho xưởng ${workshop.workshop}.`)
      await load()
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setBusy(false)
    }
  }

  const saveCutoffs = async (event) => {
    event.preventDefault()
    setBusy(true)
    setError('')
    setNotice('')
    try {
      await requestApi('/api/meal-reports/cutoffs', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ cutoffs }),
      })
      setNotice('Đã lưu giờ chốt báo cơm cho bốn ca.')
      await load()
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setBusy(false)
    }
  }

  const saveReporter = async (department, selectedUserId) => {
    setBusy(true)
    setError('')
    setNotice('')
    try {
      await requestApi(`/api/meal-reports/departments/${encodeURIComponent(department.id)}/reporters`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ shift, userId: selectedUserId || null }),
      })
      setNotice(`Đã cập nhật người nhập báo cơm ca ${shifts.find(([key]) => key === shift)?.[1]}.`)
      await load()
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="meal-page">
      <header className="meal-header">
        <div>
          <p className="meetings-eyebrow">ĂN CA TOÀN CÔNG TY</p>
          <h1>Báo cơm</h1>
          <p className="meetings-subtitle">Khai báo và tổng hợp số suất ăn theo ngày, ca, xưởng và phòng ban.</p>
        </div>
        <div className="meal-total-card">
          <span>Tổng suất Việt · Hàn</span>
          <strong>{totals.vietnamese.toLocaleString()} · {totals.korean.toLocaleString()}</strong>
        </div>
      </header>

      <section className="meal-card">
        <div className="meal-filters">
          <label>
            <span>Ngày báo cơm</span>
            <input type="date" value={date} onChange={(event) => setDate(event.target.value)} />
          </label>
          <label>
            <span>Ca làm việc</span>
            <select value={shift} onChange={(event) => setShift(event.target.value)}>
              {shifts.map(([key, label]) => <option key={key} value={key}>{label}</option>)}
            </select>
          </label>
          {report && (
            <p className={report.isClosed ? 'meal-status closed' : 'meal-status open'}>
              {report.isClosed
                ? `Đã chốt lúc ${report.cutoffTime}; chỉ xem báo cáo.`
                : `Nhận báo cơm đến ${report.cutoffTime}.`}
            </p>
          )}
        </div>

        {error && <p className="meal-message error" role="alert">{error}</p>}
        {notice && <p className="meal-message success" role="status">{notice}</p>}

        {report?.isAdmin && (
          <form className="meal-admin-settings" onSubmit={saveCutoffs}>
            <div>
              <h2>Cấu hình giờ chốt</h2>
              <p>Giờ áp dụng hằng ngày cho từng ca, theo giờ Việt Nam.</p>
            </div>
            <div className="meal-cutoff-grid">
              {shifts.map(([key, label]) => (
                <label key={key}>
                  <span>Ca {label}</span>
                  <input
                    type="time"
                    required
                    value={cutoffs[key] || '23:59'}
                    onChange={(event) => setCutoffs({ ...cutoffs, [key]: event.target.value })}
                  />
                </label>
              ))}
            </div>
            <button type="submit" disabled={busy}>Lưu giờ chốt</button>
          </form>
        )}

        {!report && !error && <p className="meal-empty">Đang tải báo cáo...</p>}
        {report?.departments.length === 0 && (
          <p className="meal-empty">Bạn chưa được gán vào phòng ban có báo cơm.</p>
        )}
        {!!report?.departments.length && (
          <div className="meal-table-wrap">
            <table className="meal-table">
              <thead>
                <tr>
                  <th>Phòng ban</th>
                  <th>Xưởng</th>
                  <th>Suất ăn Việt</th>
                </tr>
              </thead>
              <tbody>
                {report.departments.map((department) => department.workshops.map((workshop) => {
                  const vietnameseKey = `vietnamese:${department.id}:${workshop.workshop}`
                  const vietnameseValue = quantities[vietnameseKey] ?? ''
                  return (
                    <tr key={`${department.id}:${workshop.workshop}`}>
                      <td data-label="Phòng ban">{department.name}</td>
                      <td data-label="Xưởng">Xưởng {workshop.workshop}</td>
                      <td data-label="Suất Việt">
                        <div className="meal-quantity-cell">
                          <input
                            type="number"
                            min="0"
                            max="99999"
                            value={vietnameseValue}
                            disabled={!workshop.canEditVietnamese || busy}
                            aria-label={`${department.name}, xưởng ${workshop.workshop}, suất Việt`}
                            onChange={(event) => setQuantities({ ...quantities, [vietnameseKey]: event.target.value })}
                          />
                          {workshop.canEditVietnamese && (
                            <button
                              type="button"
                              disabled={busy}
                              onClick={() => saveQuantity(department, workshop, 'vietnamese')}
                            >
                              Lưu
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  )
                }))}
              </tbody>
            </table>
          </div>
        )}
        {report && (
          <section className="meal-korean-report">
            <div className="meal-section-heading">
              <div>
                <h2>Tổng suất ăn Hàn · HR</h2>
                <p>Nhập tổng số suất theo xưởng, không phân bổ theo phòng ban.</p>
              </div>
              <strong>
                Tổng: {report.koreanMeals.reduce((total, meal) => total + Number(meal.koreanQty || 0), 0).toLocaleString()}
              </strong>
            </div>
            <div className="meal-korean-workshops">
              {report.koreanMeals.map((meal) => {
                const key = `korean:${meal.workshop}`
                return (
                  <div className="meal-korean-workshop" key={meal.workshop}>
                    <label htmlFor={`korean-meal-${meal.workshop}`}>Xưởng {meal.workshop}</label>
                    <div className="meal-quantity-cell">
                      <input
                        id={`korean-meal-${meal.workshop}`}
                        type="number"
                        min="0"
                        max="99999"
                        value={quantities[key] ?? ''}
                        disabled={!meal.canEdit || busy}
                        aria-label={`Xưởng ${meal.workshop}, tổng suất ăn Hàn`}
                        onChange={(event) => setQuantities({ ...quantities, [key]: event.target.value })}
                      />
                      {meal.canEdit && (
                        <button
                          type="button"
                          disabled={busy}
                          onClick={() => saveQuantity(null, meal, 'korean')}
                        >
                          Lưu
                        </button>
                      )}
                    </div>
                  </div>
                )
              })}
            </div>
          </section>
        )}
      </section>

      {!!report?.departments.some((department) => department.canManageReporters) && (
        <section className="meal-card meal-reporter-settings">
          <div className="meal-section-heading">
            <div>
              <h2>Phân công trưởng ca</h2>
              <p>Chọn nhân viên được nhập suất Việt cho phòng ban và ca đang chọn.</p>
            </div>
          </div>
          {report.departments.filter((department) => department.canManageReporters).map((department) => {
            const assignedId = reporterSelections[department.id]
              ?? department.workshops[0]?.reporterId
              ?? ''
            const options = report.reporterOptions.filter((person) => person.departmentId === department.id)
            return (
              <div className="meal-reporter-row" key={department.id}>
                <strong>{department.name}</strong>
                <div className="meal-reporter-controls">
                  <select
                    value={assignedId}
                    disabled={busy}
                    aria-label={`Trưởng ca nhập báo cơm: ${department.name}`}
                    onChange={(event) => setReporterSelections({
                      ...reporterSelections,
                      [department.id]: event.target.value,
                    })}
                  >
                    <option value="">Chưa phân công</option>
                    {options.map((person) => (
                      <option key={person.id} value={person.id}>
                        {person.name}{person.userCode ? ` · ${person.userCode}` : ''}
                      </option>
                    ))}
                  </select>
                  <button
                    type="button"
                    disabled={busy}
                    onClick={() => saveReporter(department, assignedId)}
                  >
                    Lưu phân công
                  </button>
                </div>
              </div>
            )
          })}
          {report.reporterOptions.length === 0 && (
            <p className="meal-empty">Phòng ban chưa có nhân viên phù hợp để phân công.</p>
          )}
        </section>
      )}

      <p className="meal-footnote">
        Danh sách phòng ban toàn công ty luôn hiển thị; trước giờ chốt, chỉ người có quyền mới sửa suất Việt tại phòng ban mình phụ trách. HR nhập tổng suất Hàn riêng theo từng xưởng. Qua giờ chốt, báo cáo chuyển sang chỉ xem. Admin quản lý quyền HR tại mục Tài khoản &amp; phân quyền.
      </p>
    </section>
  )
}

export default MealReports
