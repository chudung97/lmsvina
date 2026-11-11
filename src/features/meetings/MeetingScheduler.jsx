import { useEffect, useState } from 'react'
import { useRealtimeRefresh } from '../../realtime'
import { LocalizedTree } from '../../i18n'
import { useLocale } from '../../locale-context'
import {
  SearchPagination,
  SearchableCheckboxList,
  SearchableSelect,
} from '../../components/SearchableControls'
import { useSearchPagination } from '../../hooks/useSearchPagination'
import './MeetingScheduler.css'

const emptyForm = {
  title: '',
  type: 'single',
  date: '',
  endDate: '',
  startTime: '',
  endTime: '',
  recurrenceDays: [],
  recurrenceEndDate: '',
  roomId: '',
}
const weekdays = ['Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7', 'Chủ nhật']

function toDateKey(date) {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function dateFromKey(value) {
  const [year, month, day] = value.split('-').map(Number)
  return new Date(year, month - 1, day, 12)
}

function weekdayForDate(value) {
  return value ? (new Date(`${value}T12:00:00`).getDay() + 6) % 7 : 0
}

function formatTimeRange(meeting, locale) {
  if (meeting.date === meeting.endDate) return `${meeting.startTime} – ${meeting.endTime}`
  return `${meeting.startTime} ${formatMeetingDate(meeting.date, locale)} – ${meeting.endTime} ${formatMeetingDate(meeting.endDate, locale)}`
}

async function requestApi(url, options) {
  const response = await fetch(url, options)
  const result = await response.json()
  if (!response.ok) throw new Error(result.error || 'Không thể kết nối máy chủ.')
  return result
}

function formatMeetingDate(date, locale) {
  return new Intl.DateTimeFormat(locale, {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }).format(new Date(`${date}T12:00:00`))
}

function MeetingScheduler({ sessionUser }) {
  const { locale } = useLocale()
  const refreshCounter = useRealtimeRefresh('meetings', 'accounts')
  const [meetings, setMeetings] = useState([])
  const [users, setUsers] = useState([])
  const [rooms, setRooms] = useState([])
  const [availability, setAvailability] = useState(null)
  const [checkingAvailability, setCheckingAvailability] = useState(false)
  const [roomName, setRoomName] = useState('')
  const [editingRoomId, setEditingRoomId] = useState(null)
  const [editingRoomName, setEditingRoomName] = useState('')
  const [attendeeIds, setAttendeeIds] = useState([sessionUser.id])
  const [today] = useState(() => toDateKey(new Date()))
  const [form, setForm] = useState({
    ...emptyForm,
    date: today,
    endDate: today,
    recurrenceEndDate: today,
    recurrenceDays: [weekdayForDate(today)],
  })
  const [selectedDate, setSelectedDate] = useState(today)
  const [visibleMonth, setVisibleMonth] = useState(() => {
    const selected = dateFromKey(today)
    return new Date(selected.getFullYear(), selected.getMonth(), 1, 12)
  })
  const roomList = useSearchPagination(rooms, (room) => `${room.name} ${room.active ? 'active' : 'inactive'}`)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const availabilityKey = JSON.stringify({
    date: form.date,
    endDate: form.endDate,
    endTime: form.endTime,
    recurrenceDays: form.recurrenceDays,
    recurrenceEndDate: form.recurrenceEndDate,
    startTime: form.startTime,
    type: form.type,
  })
  const scheduleIsComplete = Boolean(form.date && form.startTime && form.endTime
    && (form.type !== 'overnight' || form.endDate)
    && (form.type !== 'weekly' || (form.recurrenceEndDate && form.recurrenceDays.length)))
  const currentAvailability = availability?.key === availabilityKey ? availability.result : null
  const isCheckingAvailability = scheduleIsComplete && (checkingAvailability || !currentAvailability)

  useEffect(() => {
    let cancelled = false

    Promise.all([
      requestApi('/api/meetings'),
      requestApi('/api/meetings/users'),
      requestApi('/api/meetings/rooms'),
    ])
      .then(([items, availableUsers, availableRooms]) => {
        if (!cancelled) {
          setMeetings(items)
          setUsers(availableUsers)
          setRooms(availableRooms)
        }
      })
      .catch((requestError) => {
        if (!cancelled) setError(`Không thể tải danh sách cuộc họp: ${requestError.message}`)
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [refreshCounter])

  useEffect(() => {
    if (!form.date || !form.startTime || !form.endTime
      || (form.type === 'overnight' && !form.endDate)
      || (form.type === 'weekly'
        && (!form.recurrenceEndDate || !form.recurrenceDays.length))) {
      return undefined
    }

    const controller = new AbortController()
    const timeout = window.setTimeout(async () => {
      setCheckingAvailability(true)
      try {
        const query = new URLSearchParams({
          type: form.type,
          date: form.date,
          startTime: form.startTime,
          endTime: form.endTime,
          recurrenceDays: JSON.stringify(form.recurrenceDays),
        })
        if (form.type === 'overnight') query.set('endDate', form.endDate)
        if (form.type === 'weekly') query.set('recurrenceEndDate', form.recurrenceEndDate)
        const result = await requestApi(`/api/meetings/availability?${query}`, {
          signal: controller.signal,
        })
        setAvailability({ key: availabilityKey, result })
      } catch (requestError) {
        if (!controller.signal.aborted) {
          setAvailability({
            key: availabilityKey,
            result: { error: requestError.message, conflicts: [], busyRoomIds: [] },
          })
        }
      } finally {
        if (!controller.signal.aborted) setCheckingAvailability(false)
      }
    }, 180)

    return () => {
      window.clearTimeout(timeout)
      controller.abort()
    }
  }, [form.date, form.endDate, form.endTime, form.recurrenceDays,
    form.recurrenceEndDate, form.startTime, form.type, availabilityKey])

  const updateField = (event) => {
    const { name, value } = event.target
    setForm((current) => {
      if (name === 'date') {
        return {
          ...current,
          date: value,
          endDate: current.endDate < value ? value : current.endDate,
          recurrenceEndDate: current.recurrenceEndDate < value ? value : current.recurrenceEndDate,
          recurrenceDays: current.type === 'weekly' ? [weekdayForDate(value)] : current.recurrenceDays,
        }
      }
      return { ...current, [name]: value }
    })
  }

  const createMeeting = async (event) => {
    event.preventDefault()
    setError('')
    setNotice('')
    setSaving(true)
    try {
      const meeting = await requestApi('/api/meetings', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...form, attendeeIds }),
      })
      setMeetings((current) => [...current, ...meeting.createdMeetings].sort((first, second) =>
        `${first.date}T${first.startTime}`.localeCompare(`${second.date}T${second.startTime}`),
      ))
      setNotice(meeting.createdCount > 1
        ? `Đã tạo ${meeting.createdCount} buổi họp lặp.`
        : 'Đã lưu lịch họp.')
      setSelectedDate(meeting.date)
      const meetingDate = dateFromKey(meeting.date)
      setVisibleMonth(new Date(meetingDate.getFullYear(), meetingDate.getMonth(), 1, 12))
      setForm({
        ...emptyForm,
        date: meeting.date,
        endDate: meeting.date,
        recurrenceEndDate: meeting.date,
        recurrenceDays: [weekdayForDate(meeting.date)],
      })
      setAttendeeIds([sessionUser.id])
      setAvailability(null)
    } catch (requestError) {
      setError(`Không thể tạo cuộc họp: ${requestError.message}`)
    } finally {
      setSaving(false)
    }
  }

  const addRoom = async (event) => {
    event.preventDefault()
    setError('')
    try {
      const created = await requestApi('/api/meetings/rooms', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name: roomName }),
      })
      setRooms((current) => [...current, created].sort((first, second) =>
        first.name.localeCompare(second.name, 'vi'),
      ))
      setRoomName('')
      setNotice(`Đã thêm phòng ${created.name}.`)
    } catch (requestError) {
      setError(`Không thể thêm phòng: ${requestError.message}`)
    }
  }

  const toggleRoom = async (room) => {
    setError('')
    try {
      const updated = await requestApi(`/api/meetings/rooms/${room.id}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ active: !room.active }),
      })
      setRooms((current) => current.map((item) => item.id === updated.id ? updated : item))
      if (!updated.active && String(form.roomId) === String(updated.id)) {
        setForm((current) => ({ ...current, roomId: '' }))
      }
    } catch (requestError) {
      setError(`Không thể cập nhật phòng: ${requestError.message}`)
    }
  }

  const saveRoomName = async (event) => {
    event.preventDefault()
    setError('')
    try {
      const updated = await requestApi(`/api/meetings/rooms/${editingRoomId}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name: editingRoomName }),
      })
      setRooms((current) => current.map((room) => room.id === updated.id ? updated : room))
      setEditingRoomId(null)
      setEditingRoomName('')
      setNotice(`Đã cập nhật tên phòng thành ${updated.name}.`)
    } catch (requestError) {
      setError(`Không thể sửa tên phòng: ${requestError.message}`)
    }
  }

  const toggleRecurrenceDay = (day) => {
    setForm((current) => ({
      ...current,
      recurrenceDays: current.recurrenceDays.includes(day)
        ? current.recurrenceDays.filter((item) => item !== day)
        : [...current.recurrenceDays, day].sort((first, second) => first - second),
    }))
  }

  const firstDayOfMonth = new Date(
    visibleMonth.getFullYear(),
    visibleMonth.getMonth(),
    1,
    12,
  )
  const daysInMonth = new Date(
    visibleMonth.getFullYear(),
    visibleMonth.getMonth() + 1,
    0,
    12,
  ).getDate()
  const startOffset = (firstDayOfMonth.getDay() + 6) % 7
  const calendarCells = Array.from(
    { length: Math.ceil((startOffset + daysInMonth) / 7) * 7 },
    (_, index) => {
      const day = index - startOffset + 1
      return day > 0 && day <= daysInMonth
        ? toDateKey(new Date(visibleMonth.getFullYear(), visibleMonth.getMonth(), day, 12))
        : null
    },
  )
  const meetingsByDate = meetings.reduce((grouped, meeting) => {
    const firstVisibleDate = toDateKey(firstDayOfMonth)
    const lastVisibleDate = toDateKey(new Date(
      visibleMonth.getFullYear(),
      visibleMonth.getMonth() + 1,
      0,
      12,
    ))
    const endDate = meeting.endDate || meeting.date
    const start = new Date(`${meeting.date > firstVisibleDate ? meeting.date : firstVisibleDate}T00:00:00`)
    const end = new Date(`${endDate < lastVisibleDate ? endDate : lastVisibleDate}T00:00:00`)
    if (meeting.endTime === '00:00' && endDate > meeting.date && endDate <= lastVisibleDate) {
      end.setDate(end.getDate() - 1)
    }
    if (start > end) return grouped
    for (const current = new Date(start); current <= end; current.setDate(current.getDate() + 1)) {
      const date = toDateKey(current)
      grouped[date] = [...(grouped[date] || []), meeting]
    }
    return grouped
  }, {})
  const selectedDayMeetings = meetingsByDate[selectedDate] || []
  const agendaList = useSearchPagination(
    selectedDayMeetings,
    (meeting) => `${meeting.title} ${meeting.roomName || ''} ${meeting.participants.map((user) => `${user.name} ${user.user_code || ''}`).join(' ')} ${meeting.startTime} ${meeting.endTime}`,
  )
  const selectedDateLabel = formatMeetingDate(selectedDate, locale)
  const monthLabel = new Intl.DateTimeFormat(locale, {
    month: 'long',
    year: 'numeric',
  }).format(visibleMonth)

  const changeMonth = (amount) => {
    const nextMonth = new Date(visibleMonth.getFullYear(), visibleMonth.getMonth() + amount, 1, 12)
    setVisibleMonth(nextMonth)
    setSelectedDate(toDateKey(nextMonth))
  }

  const selectCalendarDate = (date) => {
    setSelectedDate(date)
    setForm((current) => ({
      ...current,
      date,
      endDate: current.endDate < date ? date : current.endDate,
      recurrenceEndDate: current.recurrenceEndDate < date ? date : current.recurrenceEndDate,
    }))
  }

  return (
    <LocalizedTree>
    <section className="meetings-page" aria-label="Lịch họp">
      <header className="meetings-header">
        <div>
          <p className="meetings-eyebrow">Không gian làm việc</p>
          <h1>Lịch họp</h1>
          <p className="meetings-subtitle">Tạo lịch trong ngày, qua ngày hoặc lặp hàng tuần. Giờ trùng được kiểm tra trên lịch toàn công ty.</p>
        </div>
        <div className="meeting-total">
          <strong>{meetings.length}</strong>
          <span>cuộc họp</span>
        </div>
      </header>

      {error && <p className="meeting-error" role="alert">{error}</p>}
      {notice && <p className="meeting-notice" role="status">{notice}</p>}

      <div className="meetings-layout">
        <section className="meeting-form-card" aria-labelledby="meeting-form-title">
          <div className="meeting-card-heading">
            <span className="meeting-heading-icon" aria-hidden="true">+</span>
            <div>
              <h2 id="meeting-form-title">Tạo cuộc họp</h2>
              <p>Chọn loại lịch và thêm người tham gia.</p>
            </div>
          </div>
          {sessionUser.role === 'admin' && (
            <section className="meeting-room-admin" aria-label="Quản lý phòng họp">
              <h3>Quản lý phòng</h3>
              <form onSubmit={addRoom}>
                <input
                  type="text"
                  aria-label="Tên phòng họp mới"
                  placeholder="Tên phòng họp mới"
                  maxLength={80}
                  value={roomName}
                  onChange={(event) => setRoomName(event.target.value)}
                  required
                />
                <button type="submit">Thêm phòng</button>
              </form>
              {rooms.length ? (
                <>
                  <SearchPagination list={roomList} compact />
                  <ul>
                    {roomList.pageItems.map((room) => (
                      <li key={room.id}>
                        {editingRoomId === room.id ? (
                          <form onSubmit={saveRoomName}>
                            <input
                              type="text"
                              aria-label="Tên phòng họp"
                              maxLength={80}
                              value={editingRoomName}
                              onChange={(event) => setEditingRoomName(event.target.value)}
                              required
                            />
                            <button type="submit">Lưu</button>
                            <button type="button" onClick={() => setEditingRoomId(null)}>Hủy</button>
                          </form>
                        ) : (
                          <>
                            <span>{room.name}{!room.active && <small> · Đã tắt</small>}</span>
                            <span className="meeting-room-actions">
                              <button type="button" onClick={() => {
                                setEditingRoomId(room.id)
                                setEditingRoomName(room.name)
                              }}>Sửa</button>
                              <button type="button" onClick={() => toggleRoom(room)}>
                                {room.active ? 'Tắt' : 'Bật'}
                              </button>
                            </span>
                          </>
                        )}
                      </li>
                    ))}
                  </ul>
                  {!roomList.pageItems.length && <p>Không tìm thấy phòng họp phù hợp.</p>}
                </>
              ) : <p>Chưa có phòng họp. Thêm phòng để bắt đầu đặt lịch.</p>}
            </section>
          )}
          <form className="meeting-form" onSubmit={createMeeting}>
            <label>
              <span>Tiêu đề cuộc họp</span>
              <input
                name="title"
                type="text"
                value={form.title}
                onChange={updateField}
                placeholder="Ví dụ: Họp kế hoạch tuần"
                maxLength={120}
                required
              />
            </label>
            <div className="meeting-search-select">
              <span>Phòng họp</span>
              <SearchableSelect
                label="Phòng họp"
                value={form.roomId}
                onChange={(value) => setForm((current) => ({ ...current, roomId: value }))}
                required
                placeholder="Tìm phòng họp theo tên..."
                emptyOption={rooms.some((room) => room.active) ? 'Chọn phòng họp' : 'Chưa có phòng họp hoạt động'}
                disabled={!rooms.some((room) => room.active)}
                options={rooms.filter((room) => room.active).map((room) => ({
                  value: room.id,
                  label: `${room.name}${currentAvailability?.busyRoomIds?.includes(room.id) ? ' · ĐANG BẬN' : ''}`,
                  searchText: room.name,
                }))}
              />
            </div>
            {isCheckingAvailability && (
              <p className="meeting-availability checking" role="status">Đang kiểm tra lịch trống...</p>
            )}
            {!isCheckingAvailability && currentAvailability?.error && (
              <p className="meeting-availability conflict" role="alert">{currentAvailability.error}</p>
            )}
            {!isCheckingAvailability && currentAvailability?.conflicts?.length > 0 && (
              <div className="meeting-availability conflict" role="alert">
                {currentAvailability.conflicts.map((conflict, index) => (
                  <p key={`${conflict.id}-${conflict.date}-${index}`}>
                    {conflict.roomId && conflict.roomId === Number(form.roomId)
                      ? `Phòng ${conflict.roomName} đang bận`
                      : 'Lịch công ty bị trùng giờ'}
                    {' · '}{conflict.title} · {conflict.date} · {conflict.startTime}–{conflict.endTime}
                  </p>
                ))}
              </div>
            )}
            {!isCheckingAvailability && currentAvailability?.available && form.roomId && (
              <p className="meeting-availability available" role="status">
                Phòng {rooms.find((room) => String(room.id) === form.roomId)?.name || ''} đang trống.
              </p>
            )}
            <fieldset className="meeting-type-picker">
              <legend>Loại lịch</legend>
              {[
                ['single', 'Trong ngày', 'Một ngày, giờ bắt đầu và kết thúc'],
                ['overnight', 'Qua ngày', 'Bắt đầu và kết thúc ở hai ngày'],
                ['weekly', 'Lặp hàng tuần', 'Chọn thứ và ngày kết thúc'],
              ].map(([value, label, description], index) => (
                <label className={form.type === value ? 'selected' : ''} key={value}>
                  <input
                    type="radio"
                    name="type"
                    value={value}
                    checked={form.type === value}
                    onChange={(event) => setForm((current) => ({ ...current, type: event.target.value }))}
                  />
                  <span className="meeting-type-icon" aria-hidden="true">{['◷', '↗', '↻'][index]}</span>
                  <span className="meeting-type-copy"><strong>{label}</strong><small>{description}</small></span>
                </label>
              ))}
            </fieldset>
            <label>
              <span>{form.type === 'weekly' ? 'Ngày bắt đầu lặp' : 'Ngày bắt đầu'}</span>
              <input name="date" type="date" value={form.date} onChange={updateField} required />
            </label>
            {form.type === 'overnight' && (
              <label>
                <span>Ngày kết thúc</span>
                <input
                  name="endDate"
                  type="date"
                  min={form.date}
                  value={form.endDate}
                  onChange={updateField}
                  required
                />
              </label>
            )}
            <div className="meeting-time-fields">
              <label>
                <span>Giờ bắt đầu</span>
                <input name="startTime" type="time" value={form.startTime} onChange={updateField} required />
              </label>
              <label>
                <span>Giờ kết thúc</span>
                <input name="endTime" type="time" value={form.endTime} onChange={updateField} required />
              </label>
            </div>
            {form.type === 'overnight' && (
              <p className="meeting-field-hint">Giờ kết thúc được tính vào ngày kết thúc đã chọn.</p>
            )}
            {form.type === 'weekly' && (
              <div className="meeting-weekly-options">
                <fieldset className="meeting-weekdays">
                  <legend>Lặp vào các thứ</legend>
                  <div>
                    {weekdays.map((weekday, day) => (
                      <label className={form.recurrenceDays.includes(day) ? 'selected' : ''} key={weekday}>
                        <input
                          type="checkbox"
                          checked={form.recurrenceDays.includes(day)}
                          onChange={() => toggleRecurrenceDay(day)}
                        />
                        <span>{weekday.replace('Thứ ', 'T')}</span>
                      </label>
                    ))}
                  </div>
                </fieldset>
                <label>
                  <span>Lặp đến hết ngày</span>
                  <input
                    name="recurrenceEndDate"
                    type="date"
                    min={form.date}
                    value={form.recurrenceEndDate}
                    onChange={updateField}
                    required
                  />
                </label>
              </div>
            )}
            <SearchableCheckboxList
              title="Người tham gia"
              description="Chọn tài khoản sẽ tham dự cuộc họp."
              options={users.map((user) => ({
                value: user.id,
                label: `${user.name}${user.id === sessionUser.id ? ' (tôi)' : ''}${user.user_code ? ` · ${user.user_code}` : ''}`,
                searchText: `${user.name} ${user.user_code || ''} ${user.email || ''}`,
                department: user.departmentName ?? null,
              }))}
              selectedValues={attendeeIds}
              className="meeting-participants"
              onToggle={(id, checked) => setAttendeeIds((current) => (
                checked
                  ? current.some((selected) => String(selected) === String(id)) ? current : [...current, id]
                  : current.filter((selected) => String(selected) !== String(id))
              ))}
            />
            <button
              className="meeting-submit"
              type="submit"
              disabled={saving || isCheckingAvailability || currentAvailability?.available === false
                || !rooms.some((room) => room.active)}
            >
              {saving ? 'Đang kiểm tra và lưu...' : form.type === 'weekly' ? 'Tạo lịch lặp' : 'Lưu lịch họp'}
            </button>
          </form>
        </section>

        <section className="meeting-list-card" aria-labelledby="meeting-list-title">
          <div className="meeting-card-heading meeting-list-heading">
            <div>
              <h2 id="meeting-list-title">Danh sách lịch họp</h2>
              <p>Lịch toàn công ty · kiểm tra giờ trùng và tình trạng phòng.</p>
            </div>
          </div>
          {loading ? (
            <p className="meeting-empty">Đang tải lịch họp...</p>
          ) : (
            <>
              <div className="calendar-toolbar">
                <button type="button" aria-label="Tháng trước" onClick={() => changeMonth(-1)}>‹</button>
                <h3>{monthLabel}</h3>
                <button type="button" aria-label="Tháng sau" onClick={() => changeMonth(1)}>›</button>
              </div>
              <div className="meeting-calendar" role="grid" aria-label={`Lịch tháng ${monthLabel}`}>
                {weekdays.map((weekday) => (
                  <div className="calendar-weekday" role="columnheader" key={weekday}>{weekday}</div>
                ))}
                {calendarCells.map((date, index) => {
                  const dayMeetings = date ? meetingsByDate[date] || [] : []
                  const isSelected = date === selectedDate
                  const meetingSummary = dayMeetings
                    .map((meeting) => {
                      const timeRange = formatTimeRange(meeting, locale)
                      const attendeeNames = meeting.participants.map((user) => user.name).join(', ')
                      return `${meeting.title}, ${timeRange}${meeting.roomName ? `, Phòng: ${meeting.roomName}` : ''}${meeting.type === 'weekly' ? ', lịch lặp hàng tuần' : ''}${attendeeNames ? `, Người tham gia: ${attendeeNames}` : ''}`
                    })
                    .join('; ')
                  return date ? (
                    <button
                      type="button"
                      role="gridcell"
                      key={date}
                      className={`calendar-day${isSelected ? ' selected' : ''}${dayMeetings.length ? ' has-meetings' : ''}${index % 7 >= 5 ? ' tooltip-align-right' : ''}${date === today ? ' today' : ''}`}
                      aria-label={`${formatMeetingDate(date, locale)}${dayMeetings.length ? `, ${dayMeetings.length} cuộc họp: ${meetingSummary}` : ''}`}
                      title={meetingSummary || undefined}
                      aria-pressed={isSelected}
                      onClick={() => selectCalendarDate(date)}
                    >
                      <span className="calendar-day-number">{Number(date.slice(-2))}</span>
                      {dayMeetings.length > 0 && (
                        <span className="calendar-meeting-markers" aria-hidden="true">
                          {dayMeetings.slice(0, 2).map((meeting) => (
                            <span className={`calendar-meeting-marker${meeting.date !== date ? ' continues' : ''}`} key={meeting.id}>
                              <time>{meeting.date === date ? meeting.startTime : 'Tiếp diễn'}</time>
                              <span>{meeting.title}</span>
                            </span>
                          ))}
                          {dayMeetings.length > 2 && (
                            <span className="calendar-more-marker">+{dayMeetings.length - 2} cuộc họp</span>
                          )}
                        </span>
                      )}
                      {dayMeetings.length > 0 && (
                        <span className="calendar-day-tooltip" role="tooltip">
                          {dayMeetings.map((meeting) => (
                            <span className="calendar-tooltip-meeting" key={meeting.id}>
                              <strong>{meeting.title}</strong>
                              <span>{formatTimeRange(meeting, locale)}</span>
                              {meeting.roomName && <small>Phòng: {meeting.roomName}</small>}
                              {meeting.type === 'weekly' && (
                                <small>
                                  Lặp hàng tuần · {weekdays.filter((_, day) => meeting.recurrenceDays.includes(day)).join(', ')}
                                </small>
                              )}
                              {meeting.participants.length > 0 && (
                                <small>{meeting.participants.map((user) => user.name).join(', ')}</small>
                              )}
                            </span>
                          ))}
                        </span>
                      )}
                    </button>
                  ) : (
                    <span className="calendar-day empty" aria-hidden="true" key={`empty-${index}`} />
                  )
                })}
              </div>

              <div className="selected-day-agenda">
                <div className="agenda-heading">
                  <div>
                    <span className="agenda-eyebrow">Lịch trong ngày</span>
                    <h3>{selectedDateLabel}</h3>
                  </div>
                  <span className="agenda-count">{selectedDayMeetings.length}</span>
                </div>
                {selectedDayMeetings.length ? (
                  <>
                    <SearchPagination list={agendaList} compact />
                    <ul className="meeting-list">
                      {agendaList.pageItems.map((meeting) => (
                        <li className="meeting-item" key={meeting.id}>
                          <div className="meeting-time-block">
                            <strong>{meeting.date === selectedDate ? meeting.startTime : '…'}</strong>
                            <span>{meeting.endDate === selectedDate ? meeting.endTime : '→'}</span>
                          </div>
                          <div className="meeting-details">
                            <h4>
                              {meeting.title}
                              {meeting.type === 'weekly' && <span className="meeting-recurrence-badge">↻ Hàng tuần</span>}
                            </h4>
                            <span className="meeting-time">
                              {formatTimeRange(meeting, locale)}
                            </span>
                            {meeting.roomName && (
                              <span className="meeting-attendees">Phòng họp: {meeting.roomName}</span>
                            )}
                            {meeting.type === 'weekly' && (
                              <span className="meeting-attendees">
                                Lặp: {weekdays.filter((_, day) => meeting.recurrenceDays.includes(day)).join(', ')}
                              </span>
                            )}
                            {meeting.participants.length > 0 && (
                              <span className="meeting-attendees">
                                Người tham gia: {meeting.participants.map((user) => user.name).join(', ')}
                              </span>
                            )}
                          </div>
                        </li>
                      ))}
                    </ul>
                    {!agendaList.pageItems.length && <p className="agenda-empty">Không tìm thấy cuộc họp phù hợp.</p>}
                  </>
                ) : (
                  <p className="agenda-empty">Không có cuộc họp nào trong ngày này.</p>
                )}
              </div>
            </>
          )}
        </section>
      </div>
    </section>
    </LocalizedTree>
  )
}

export default MeetingScheduler
