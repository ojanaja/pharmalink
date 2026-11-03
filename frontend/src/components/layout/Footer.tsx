import { formatDateId } from '../../lib/format'

/** Footer global — versi aplikasi dan tanggal update terakhir (di-inject saat build). */
export function Footer() {
  return (
    <footer className="border-t border-line px-7 py-3 text-[11px] text-ink-secondary">
      Pharmalink v{__APP_VERSION__} · Terakhir diperbarui {formatDateId(__APP_UPDATED__)}
      {__APP_COMMIT__ ? ` · ${__APP_COMMIT__}` : ''}
    </footer>
  )
}
