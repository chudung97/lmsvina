import { useState } from 'react'
import { normalizeSearchText } from '../search'

export function useSearchPagination(items, getSearchText, pageSize = 10) {
  const [query, setQuery] = useState('')
  const [page, setPage] = useState(1)
  const normalizedQuery = normalizeSearchText(query.trim())
  const filteredItems = items.filter((item) => normalizeSearchText(getSearchText(item)).includes(normalizedQuery))
  const totalPages = Math.max(1, Math.ceil(filteredItems.length / pageSize))
  const currentPage = Math.min(page, totalPages)
  const start = (currentPage - 1) * pageSize

  return {
    query,
    setQuery: (value) => {
      setQuery(value)
      setPage(1)
    },
    page: currentPage,
    setPage,
    pageSize,
    totalPages,
    totalItems: filteredItems.length,
    pageItems: filteredItems.slice(start, start + pageSize),
  }
}
