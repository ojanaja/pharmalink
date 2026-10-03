import { Download, Upload } from 'lucide-react'
import { useState } from 'react'
import type { ChangeEvent } from 'react'
import { Button } from '../components/ui/Button'
import { Modal } from '../components/ui/Modal'
import { authFetch } from '../lib/api'

const MAX_SIZE_BYTES = 2 * 1024 * 1024

interface RowError {
  row: number
  message: string
}

interface ImportSummary {
  medicines_created: number
  medicines_updated: number
  batches_adjusted: number
  movements_created: number
  rows_processed: number
}

interface ImportMedicinesModalProps {
  open: boolean
  onClose: () => void
  onImported: () => void
}

/** Tarik pesan error 422 — format per baris {rows:[{row,message}]} atau Laravel standar {field:[pesan]}. */
function extractErrors(payload: unknown): RowError[] {
  const errors = (payload as { errors?: unknown } | null)?.errors
  if (errors && typeof errors === 'object') {
    const rows = (errors as { rows?: unknown }).rows
    if (Array.isArray(rows)) {
      return rows.map((entry) => {
        const item = entry as { row?: number; message?: string }
        return { row: item.row ?? 0, message: item.message ?? 'Baris tidak valid.' }
      })
    }
    return Object.entries(errors as Record<string, unknown>).map(([field, messages]) => ({
      row: 0,
      message: `${field}: ${Array.isArray(messages) ? messages.join(', ') : String(messages)}`,
    }))
  }
  return []
}

export function ImportMedicinesModal({ open, onClose, onImported }: ImportMedicinesModalProps) {
  const [file, setFile] = useState<File | null>(null)
  const [uploading, setUploading] = useState(false)
  const [downloading, setDownloading] = useState(false)
  const [rowErrors, setRowErrors] = useState<RowError[]>([])
  const [generalError, setGeneralError] = useState<string | null>(null)
  const [summary, setSummary] = useState<ImportSummary | null>(null)

  function reset() {
    setFile(null)
    setRowErrors([])
    setGeneralError(null)
    setSummary(null)
  }

  function handleFileChange(event: ChangeEvent<HTMLInputElement>) {
    const selected = event.target.files?.[0] ?? null
    setFile(selected)
    setRowErrors([])
    setGeneralError(null)
    setSummary(null)
  }

  async function downloadTemplate() {
    setDownloading(true)
    try {
      const response = await authFetch('/api/medicines/import/template')
      if (!response.ok) throw new Error('unduh gagal')
      const blob = await response.blob()
      const url = URL.createObjectURL(blob)
      const anchor = document.createElement('a')
      anchor.href = url
      anchor.download = 'template-obat.xlsx'
      anchor.click()
      URL.revokeObjectURL(url)
    } catch {
      setGeneralError('Unduh template gagal. Coba lagi.')
    } finally {
      setDownloading(false)
    }
  }

  async function handleImport() {
    if (!file) return
    setUploading(true)
    setRowErrors([])
    setGeneralError(null)
    try {
      const formData = new FormData()
      formData.append('file', file)
      const response = await authFetch('/api/medicines/import', {
        method: 'POST',
        body: formData,
      })
      const payload: unknown = await response.json().catch(() => null)
      if (!response.ok) {
        const rows = extractErrors(payload)
        if (rows.length > 0) {
          setRowErrors(rows)
        } else {
          setGeneralError(
            (payload as { message?: string } | null)?.message ?? 'Impor gagal. Periksa berkas dan coba lagi.',
          )
        }
        return
      }
      setSummary(payload as ImportSummary)
      onImported()
    } catch {
      setGeneralError('Impor gagal. Periksa koneksi dan coba lagi.')
    } finally {
      setUploading(false)
    }
  }

  const fileTooBig = file !== null && file.size > MAX_SIZE_BYTES
  const fileInvalid = file !== null && !file.name.toLowerCase().endsWith('.xlsx')
  const canUpload = file !== null && !fileTooBig && !fileInvalid && !uploading

  return (
    <Modal
      open={open}
      title="Impor Data Obat"
      onClose={() => {
        reset()
        onClose()
      }}
      footer={
        summary ? (
          <Button
            onClick={() => {
              reset()
              onClose()
            }}
          >
            Selesai
          </Button>
        ) : (
          <>
            <Button
              variant="secondary"
              onClick={() => {
                reset()
                onClose()
              }}
            >
              Batal
            </Button>
            <Button
              icon={<Upload size={14} />}
              loading={uploading}
              disabled={!canUpload}
              onClick={handleImport}
            >
              Impor
            </Button>
          </>
        )
      }
    >
      {summary ? (
        <div className="flex flex-col gap-2">
          <p className="rounded-lg bg-success-bg px-4 py-3 text-[13px] font-semibold text-success-ink">
            Impor selesai — {summary.rows_processed} baris diproses.
          </p>
          <ul className="grid grid-cols-2 gap-2 text-[13px] text-ink-secondary">
            <li>Obat baru: {summary.medicines_created}</li>
            <li>Obat diperbarui: {summary.medicines_updated}</li>
            <li>Batch disesuaikan: {summary.batches_adjusted}</li>
            <li>Mutasi stok dibuat: {summary.movements_created}</li>
          </ul>
        </div>
      ) : (
        <div className="flex flex-col gap-4">
          <p className="text-[13px] text-ink-secondary">
            Unduh template, isi data obat dan batch, lalu unggah kembali. Berkas XLSX maksimal 2 MB.
          </p>

          <Button
            variant="secondary"
            size="sm"
            icon={<Download size={14} />}
            loading={downloading}
            onClick={downloadTemplate}
            className="w-fit"
          >
            Unduh template
          </Button>

          <div className="flex flex-col gap-1.5">
            <label htmlFor="import-file" className="text-[13px] font-semibold text-ink">
              Berkas XLSX
            </label>
            <input
              id="import-file"
              type="file"
              accept=".xlsx"
              onChange={handleFileChange}
              className="block w-full text-[13px] text-ink-secondary file:mr-3 file:rounded-lg file:border file:border-input-border file:bg-surface file:px-3 file:py-2 file:text-[13px] file:font-bold file:text-ink hover:file:border-primary"
            />
            {file && (
              <p className="text-xs text-ink-secondary">
                {file.name} · {(file.size / 1024).toFixed(1)} KB
              </p>
            )}
            {fileTooBig && (
              <p className="text-xs text-danger-ink">Berkas melebihi 2 MB.</p>
            )}
            {fileInvalid && (
              <p className="text-xs text-danger-ink">Berkas harus berformat .xlsx.</p>
            )}
          </div>

          {rowErrors.length > 0 && (
            <div className="rounded-lg bg-danger-bg px-3 py-2">
              <p className="text-xs font-bold text-danger-ink">Impor gagal — perbaiki baris berikut:</p>
              <ul className="mt-1 flex max-h-40 flex-col gap-0.5 overflow-y-auto text-xs text-danger-ink">
                {rowErrors.map((error, index) => (
                  <li key={index}>
                    {error.row > 0 ? `Baris ${error.row}: ` : ''}
                    {error.message}
                  </li>
                ))}
              </ul>
            </div>
          )}
          {generalError && (
            <p role="alert" className="rounded-lg bg-danger-bg px-3 py-2 text-xs text-danger-ink">
              {generalError}
            </p>
          )}
        </div>
      )}
    </Modal>
  )
}
