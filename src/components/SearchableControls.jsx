import { useRef, useState } from 'react'
import { useLocale } from '../locale-context'
import { LocalizedTree } from '../i18n'
import { normalizeSearchText } from '../search'
import { useSearchPagination } from '../hooks/useSearchPagination'
import './SearchableControls.css'

function Pagination({ page, totalPages, totalItems, pageSize, onPageChange, compact = false }) {
  const { language } = useLocale()
  const previous = language === 'en' ? 'Previous' : language === 'ko' ? '이전' : 'Trước'
  const next = language === 'en' ? 'Next' : language === 'ko' ? '다음' : 'Sau'
  const pageLabel = language === 'en' ? 'Page' : language === 'ko' ? '페이지' : 'Trang'
  const resultsLabel = language === 'en' ? 'results' : language === 'ko' ? '개 결과' : 'kết quả'
  const firstItem = totalItems ? (page - 1) * pageSize + 1 : 0
  const lastItem = Math.min(page * pageSize, totalItems)

  return (
    <div className={`search-pagination${compact ? ' compact' : ''}`}>
      <span className="search-pagination-count">
        {language === 'en'
          ? `${firstItem}-${lastItem} of ${totalItems} ${resultsLabel}`
          : language === 'ko'
            ? `${totalItems}개 중 ${firstItem}-${lastItem}${resultsLabel}`
            : `Hiển thị ${firstItem}–${lastItem} trên ${totalItems} ${resultsLabel}`}
      </span>
      <div>
        <button type="button" aria-label={previous} disabled={page <= 1} onClick={() => onPageChange(page - 1)}>
          {previous}
        </button>
        <span>{pageLabel} {page}/{totalPages}</span>
        <button type="button" aria-label={next} disabled={page >= totalPages} onClick={() => onPageChange(page + 1)}>
          {next}
        </button>
      </div>
    </div>
  )
}

const NO_DEPARTMENT = '__no_department__'

function departmentKey(option) {
  return option.department ? String(option.department) : NO_DEPARTMENT
}

/**
 * Lọc lựa chọn theo phòng ban. Tự bật khi option có trường `department`
 * (tên phòng ban, null = chưa có phòng ban); các danh sách khác không bị ảnh hưởng.
 */
function useDepartmentFilter(options) {
  const [department, setDepartment] = useState('')
  const enabled = options.some((option) => option.department !== undefined)
  const departments = enabled
    ? [...new Set(options.map(departmentKey))].sort((a, b) => {
      if (a === NO_DEPARTMENT) return 1
      if (b === NO_DEPARTMENT) return -1
      return a.localeCompare(b, 'vi')
    })
    : []
  const active = departments.includes(department) ? department : ''

  return {
    enabled,
    department: active,
    setDepartment,
    departments,
    options: enabled && active ? options.filter((option) => departmentKey(option) === active) : options,
  }
}

function DepartmentFilter({ filter, onChange, disabled = false }) {
  const { language } = useLocale()
  if (!filter.enabled) return null
  const label = language === 'en' ? 'Department' : language === 'ko' ? '부서' : 'Phòng ban'
  const all = language === 'en' ? 'All departments' : language === 'ko' ? '모든 부서' : 'Tất cả phòng ban'
  const none = language === 'en' ? 'No department' : language === 'ko' ? '부서 없음' : 'Chưa có phòng ban'

  return (
    <label className="searchable-department-filter">
      <span>{label}</span>
      <select
        value={filter.department}
        disabled={disabled}
        onChange={(event) => {
          filter.setDepartment(event.target.value)
          onChange?.(event.target.value)
        }}
      >
        <option value="">{all}</option>
        {filter.departments.map((department) => (
          <option key={department} value={department}>{department === NO_DEPARTMENT ? none : department}</option>
        ))}
      </select>
    </label>
  )
}

function OptionDepartment({ filter, option }) {
  if (!filter.enabled || filter.department) return null
  return <small className="searchable-option-department">{option.department || '—'}</small>
}

export function SearchPagination({ list, compact = false }) {
  const { language } = useLocale()
  const searchLabel = language === 'en' ? 'Search list' : language === 'ko' ? '목록 검색' : 'Tìm kiếm danh sách'
  const placeholder = language === 'en' ? 'Type to search...' : language === 'ko' ? '검색어 입력...' : 'Nhập từ khóa...'

  return (
    <div className={`search-list-controls${compact ? ' compact' : ''}`}>
      <label>
        <span>{searchLabel}</span>
        <input
          type="search"
          value={list.query}
          onChange={(event) => list.setQuery(event.target.value)}
          placeholder={placeholder}
        />
      </label>
      <Pagination
        compact={compact}
        page={list.page}
        totalPages={list.totalPages}
        totalItems={list.totalItems}
        pageSize={list.pageSize}
        onPageChange={list.setPage}
      />
    </div>
  )
}

export function SearchableSelect({
  label,
  value,
  options,
  onChange,
  required = false,
  placeholder = 'Tìm theo tên...',
  emptyOption,
  disabled = false,
}) {
  const { language } = useLocale()
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [page, setPage] = useState(1)
  const inputRef = useRef(null)
  const pageSize = 8
  const departmentFilter = useDepartmentFilter(options)
  const selected = options.find((option) => String(option.value) === String(value))
  const normalizedQuery = normalizeSearchText(query.trim())
  const filteredOptions = departmentFilter.options.filter((option) => normalizeSearchText(
    `${option.label} ${option.searchText || ''} ${option.department || ''}`,
  ).includes(normalizedQuery))
  const totalPages = Math.max(1, Math.ceil(filteredOptions.length / pageSize))
  const currentPage = Math.min(page, totalPages)
  const pageOptions = filteredOptions.slice((currentPage - 1) * pageSize, currentPage * pageSize)
  const invalidMessage = language === 'en'
    ? 'Choose an option from the list.'
    : language === 'ko' ? '목록에서 항목을 선택하세요.' : 'Vui lòng chọn một mục trong danh sách.'
  const inputValue = open ? query : selected?.label || ''

  return (
    <LocalizedTree>
      <div
        className={`searchable-select${departmentFilter.enabled ? ' has-department-filter' : ''}`}
        onBlur={(event) => {
          if (!event.currentTarget.contains(event.relatedTarget)) {
            setOpen(false)
            if (!value) setQuery('')
            inputRef.current?.setCustomValidity('')
          }
        }}
      >
        <DepartmentFilter
          filter={departmentFilter}
          disabled={disabled}
          onChange={() => {
            setPage(1)
            inputRef.current?.focus()
            setOpen(true)
          }}
        />
        <input
          ref={inputRef}
          type="text"
          role="combobox"
          disabled={disabled}
          aria-label={label}
          aria-autocomplete="list"
          aria-expanded={open}
          aria-controls={`options-${label.replace(/\s+/g, '-')}`}
          aria-required={required}
          autoComplete="off"
          required={required && !value}
          value={inputValue}
          placeholder={placeholder}
          onFocus={() => {
            setQuery('')
            setPage(1)
            setOpen(true)
          }}
          onChange={(event) => {
            setQuery(event.target.value)
            setPage(1)
            setOpen(true)
            if (value) onChange('')
            inputRef.current?.setCustomValidity(event.target.value ? invalidMessage : '')
          }}
          onKeyDown={(event) => {
            if (event.key === 'Escape') {
              setOpen(false)
              setQuery('')
              inputRef.current?.setCustomValidity('')
            }
            if (event.key === 'ArrowDown' && !open) setOpen(true)
          }}
        />
        {open && (
          <div className="searchable-select-menu">
            <ul id={`options-${label.replace(/\s+/g, '-')}`} role="listbox" aria-label={label}>
              {emptyOption && (
                <li role="presentation">
                  <button
                    type="button"
                    role="option"
                    aria-selected={!value}
                    onClick={() => {
                      onChange('')
                      setQuery('')
                      setOpen(false)
                      inputRef.current?.setCustomValidity('')
                    }}
                  >
                    {emptyOption}
                  </button>
                </li>
              )}
              {pageOptions.map((option) => (
                <li role="presentation" key={option.value}>
                  <button
                    type="button"
                    role="option"
                    aria-selected={String(option.value) === String(value)}
                    onClick={() => {
                      onChange(String(option.value))
                      setQuery('')
                      setOpen(false)
                      inputRef.current?.setCustomValidity('')
                    }}
                  >
                    {option.label}
                    <OptionDepartment filter={departmentFilter} option={option} />
                  </button>
                </li>
              ))}
            </ul>
            {!filteredOptions.length && <p className="searchable-select-empty">Không tìm thấy kết quả.</p>}
            <Pagination
              compact
              page={currentPage}
              totalPages={totalPages}
              totalItems={filteredOptions.length}
              pageSize={pageSize}
              onPageChange={setPage}
            />
          </div>
        )}
      </div>
    </LocalizedTree>
  )
}

export function SearchableMultiSelect({
  label,
  options,
  selectedValues,
  onChange,
  placeholder = 'Tìm và chọn...',
  disabled = false,
}) {
  const { language } = useLocale()
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [page, setPage] = useState(1)
  const inputRef = useRef(null)
  const pageSize = 8
  const departmentFilter = useDepartmentFilter(options)
  const selectedOptions = selectedValues
    .map((selected) => options.find((option) => String(selected) === String(option.value)))
    .filter(Boolean)
  const normalizedQuery = normalizeSearchText(query.trim())
  const filteredOptions = departmentFilter.options.filter((option) => normalizeSearchText(
    `${option.label} ${option.searchText || ''} ${option.department || ''}`,
  ).includes(normalizedQuery))
  const totalPages = Math.max(1, Math.ceil(filteredOptions.length / pageSize))
  const currentPage = Math.min(page, totalPages)
  const pageOptions = filteredOptions.slice((currentPage - 1) * pageSize, currentPage * pageSize)
  const selectedLabel = selectedOptions.map((option) => option.label).join(', ')
  const doneLabel = language === 'en' ? 'Done' : language === 'ko' ? '완료' : 'Xong'
  const searchLabel = language === 'en' ? 'Search users' : language === 'ko' ? '사용자 검색' : 'Tìm kiếm user'
  const selectPlaceholder = language === 'en'
    ? 'Select users...'
    : language === 'ko' ? '사용자 선택...' : 'Chọn user...'
  const emptyMessage = language === 'en'
    ? 'No matching results.'
    : language === 'ko' ? '검색 결과가 없습니다.' : 'Không tìm thấy kết quả.'

  return (
    <LocalizedTree>
      <div
        className="searchable-select searchable-multi-select"
        onBlur={(event) => {
          if (!event.currentTarget.contains(event.relatedTarget)) {
            setOpen(false)
            setQuery('')
          }
        }}
      >
        <DepartmentFilter
          filter={departmentFilter}
          disabled={disabled}
          onChange={() => {
            setPage(1)
            setOpen(true)
          }}
        />
        <label className="searchable-multi-select-search">
          <span>{searchLabel}</span>
          <input
            type="search"
            aria-label={searchLabel}
            disabled={disabled}
            autoComplete="off"
            value={query}
            placeholder={placeholder}
            onFocus={() => {
              setPage(1)
              setOpen(true)
            }}
            onChange={(event) => {
              setQuery(event.target.value)
              setPage(1)
              setOpen(true)
            }}
          />
        </label>
        <input
          ref={inputRef}
          type="text"
          role="combobox"
          readOnly
          disabled={disabled}
          aria-label={label}
          aria-expanded={open}
          aria-controls={`options-${label.replace(/\s+/g, '-')}`}
          autoComplete="off"
          value={selectedLabel}
          placeholder={selectPlaceholder}
          onFocus={() => {
            setOpen(true)
          }}
          onClick={() => setOpen(true)}
          onKeyDown={(event) => {
            if (event.key === 'Escape') {
              setOpen(false)
              setQuery('')
            }
            if (event.key === 'ArrowDown' && !open) setOpen(true)
          }}
        />
        {open && (
          <div className="searchable-select-menu">
            <button
              type="button"
              className="searchable-multi-select-done"
              onMouseDown={(event) => event.preventDefault()}
              onClick={() => {
                setOpen(false)
                setQuery('')
              }}
            >
              {doneLabel}
            </button>
            <ul id={`options-${label.replace(/\s+/g, '-')}`} role="listbox" aria-label={label} aria-multiselectable="true">
              {pageOptions.map((option) => {
                const isSelected = selectedValues.some((selected) => String(selected) === String(option.value))
                return (
                  <li role="presentation" key={option.value}>
                    <button
                      type="button"
                      role="option"
                      aria-selected={isSelected}
                      onMouseDown={(event) => event.preventDefault()}
                      onClick={() => onChange(isSelected
                        ? selectedValues.filter((selected) => String(selected) !== String(option.value))
                        : [...selectedValues, String(option.value)])}
                    >
                      <span className="searchable-multi-select-check" aria-hidden="true">{isSelected ? '✓' : ''}</span>
                      <span className="searchable-option-text">
                        {option.label}
                        <OptionDepartment filter={departmentFilter} option={option} />
                      </span>
                    </button>
                  </li>
                )
              })}
            </ul>
            {!filteredOptions.length && <p className="searchable-select-empty">{emptyMessage}</p>}
            <Pagination
              compact
              page={currentPage}
              totalPages={totalPages}
              totalItems={filteredOptions.length}
              pageSize={pageSize}
              onPageChange={setPage}
            />
          </div>
        )}
      </div>
    </LocalizedTree>
  )
}

export function SearchableCheckboxList({
  title,
  description,
  options,
  selectedValues,
  onToggle,
  emptyMessage = 'Không tìm thấy kết quả.',
  pageSize = 10,
  className = '',
}) {
  const departmentFilter = useDepartmentFilter(options)
  const list = useSearchPagination(
    departmentFilter.options,
    (option) => `${option.label} ${option.searchText || ''} ${option.department || ''}`,
    pageSize,
  )

  return (
    <LocalizedTree>
      <fieldset className={`searchable-checkbox-list ${className}`}>
        <legend>{title}</legend>
        {description && <p>{description}</p>}
        <DepartmentFilter filter={departmentFilter} onChange={() => list.setPage(1)} />
        <SearchPagination list={list} compact />
        <div className="searchable-checkbox-options">
          {list.pageItems.length ? list.pageItems.map((option) => {
            const checked = selectedValues.some((selected) => String(selected) === String(option.value))
            return (
              <label key={option.value}>
                <input
                  type="checkbox"
                  checked={checked}
                  onChange={(event) => onToggle(option.value, event.target.checked)}
                />
                <span>
                  {option.label}
                  <OptionDepartment filter={departmentFilter} option={option} />
                </span>
              </label>
            )
          }) : <p className="searchable-select-empty">{emptyMessage}</p>}
        </div>
      </fieldset>
    </LocalizedTree>
  )
}
