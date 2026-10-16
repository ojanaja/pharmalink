import { Download } from 'lucide-react'
import { useState } from 'react'
import { Button } from '../../components/ui/Button'
import { authFetch } from '../../lib/api'

interface ExportButtonProps {
  report?: 'sales' | 'purchases' | 'stock' | 'expiry'
  params?: Record<string, string | number | undefined>
  format?: 'csv' | 'xlsx'
  /** Path lengkap alternatif (mis. /api/medicines/export) — menggantikan pola /api/reports/{report}/export. */
  url?: string
  label?: string
}

/**
 * Export laporan: <a href> tidak bisa membawa header Authorization,
 * jadi file di-fetch sebagai blob Bearer lalu diunduh via URL object.
 */
export function ExportButton({ report, params = {}, format = 'csv', url, label = 'Export' }: ExportButtonProps) {
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(false)

  async function handleExport() {
    setLoading(true)
    setError(false)
    try {
      const query = new URLSearchParams({ format })
      for (const [key, value] of Object.entries(params)) {
        if (value !== undefined && value !== '') query.set(key, String(value))
      }
      const endpoint = url ?? `/api/reports/${report}/export?${query}`
      const response = await authFetch(endpoint)
      if (!response.ok) throw new Error('export gagal')
      const blob = await response.blob()
      const disposition = response.headers.get('Content-Disposition') ?? ''
      const match = /filename="?([^";]+)"?/.exec(disposition)
      const filename = match?.[1] ?? (url ? 'export.xlsx' : `laporan-${report}.${format}`)
      const objectUrl = URL.createObjectURL(blob)
      const anchor = document.createElement('a')
      anchor.href = objectUrl
      anchor.download = filename
      anchor.click()
      URL.revokeObjectURL(objectUrl)
    } catch {
      setError(true)
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="flex flex-col items-end gap-1">
      <Button variant="secondary" size="sm" icon={<Download size={14} />} loading={loading} onClick={handleExport}>
        {label}
      </Button>
      {error && <span className="text-[11px] text-danger-ink">Export gagal. Coba lagi.</span>}
    </div>
  )
}
