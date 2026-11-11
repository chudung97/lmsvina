import { useEffect, useMemo, useState } from 'react'
import { setDialogLanguage } from './i18n-core'
import { languages, LocaleContext } from './locale-context'

function LocaleProvider({ children }) {
  const [language, setLanguage] = useState(() => {
    const saved = window.localStorage.getItem('workdesk-language')
    return Object.hasOwn(languages, saved) ? saved : 'vi'
  })

  useEffect(() => {
    setDialogLanguage(language)
    window.localStorage.setItem('workdesk-language', language)
    document.documentElement.lang = language
  }, [language])

  const value = useMemo(() => ({
    language,
    locale: languages[language].locale,
    setLanguage,
  }), [language])

  return <LocaleContext.Provider value={value}>{children}</LocaleContext.Provider>
}

export default LocaleProvider
