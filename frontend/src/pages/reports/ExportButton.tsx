import { Download } from 'lucide-react'
import { useState } from 'react'
import { Button } from '../../components/ui/Button'
import { getToken } from '../../lib/api'

interface ExportButtonProps {
  report: 'sales' | 'purchases' | 'stock' | 'expiry'
  params?: Record<string, string | number | undefined>
  format?: 'csv' | 'xlsx'
}

/**
 * Export laporan: <a href> tidak bisa membawa header Authorization,
 * jadi file di-fetch sebagai blob Bearer lalu diunduh via URL object.
 */
export function ExportButton({ report, params = {}, format = 'csv' }: ExportButtonProps) {
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
      const response = await fetch(`/api/reports/${report}/export?${query}`, {
        headers: { Authorization: `Bearer ${getToken() ?? ''}` },
      })
      if (!response.ok) throw new Error('export gagal')
      const blob = await response.blob()
      const disposition = response.headers.get('Content-Disposition') ?? ''
      const match = /filename="?([^";]+)"?/.exec(disposition)
      const filename = match?.[1] ?? `laporan-${report}.${format}`
      const url = URL.createObjectURL(blob)
      const anchor = document.createElement('a')
      anchor.href = url
      anchor.download = filename
      anchor.click()
      URL.revokeObjectURL(url)
    } catch {
      setError(true)
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="flex flex-col items-end gap-1">
      <Button variant="secondary" size="sm" icon={<Download size={14} />} loading={loading} onClick={handleExport}>
        Export
      </Button>
      {error && <span className="text-[11px] text-danger-ink">Export gagal. Coba lagi.</span>}
    </div>
  )
}
