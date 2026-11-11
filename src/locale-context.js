import { createContext, useContext } from 'react'

export const languages = {
  vi: { label: 'Tiếng Việt', locale: 'vi-VN' },
  en: { label: 'English', locale: 'en-US' },
  ko: { label: '한국어', locale: 'ko-KR' },
}

export const LocaleContext = createContext(null)

export function useLocale() {
  const locale = useContext(LocaleContext)
  if (!locale) throw new Error('useLocale must be used inside LocaleProvider.')
  return locale
}
