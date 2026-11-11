import { localize, translateText } from './i18n-core'
import { languages, useLocale } from './locale-context'

export function LocalizedTree({ children }) {
  const { language } = useLocale()
  return localize(children, language)
}
LocalizedTree.isLocalized = true

export function LanguagePicker() {
  const { language, setLanguage } = useLocale()
  return (
    <label className="language-picker">
      <span>{translateText('Ngôn ngữ', language)}</span>
      <select value={language} onChange={(event) => setLanguage(event.target.value)}>
        {Object.entries(languages).map(([code, item]) => (
          <option key={code} value={code}>{item.label}</option>
        ))}
      </select>
    </label>
  )
}