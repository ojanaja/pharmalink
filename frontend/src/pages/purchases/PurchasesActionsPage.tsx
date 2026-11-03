import { useQueryClient } from '@tanstack/react-query'
import { ClipboardCheck, PackagePlus } from 'lucide-react'
import { useState } from 'react'
import { Button } from '../../components/ui/Button'
import { ManualReceiptModal } from './ManualReceiptModal'
import { PoReceiptLookupModal } from './PoReceiptLookupModal'
import { PurchaseOrderFormModal } from './PurchaseOrderFormModal'

const STEPS = [
  'Pembelian — buat & setujui PO',
  'Penerimaan — catat batch & expired',
  'Stok — saldo otomatis bertambah',
]

/** Halaman Pembelian murni transaksi: buat PO dan penerimaan barang (daftar PO ada di Laporan). */
export function PurchasesActionsPage() {
  const queryClient = useQueryClient()
  const [poFormOpen, setPoFormOpen] = useState(false)
  const [poLookupOpen, setPoLookupOpen] = useState(false)
  const [manualOpen, setManualOpen] = useState(false)
  const [success, setSuccess] = useState<string | null>(null)

  function invalidateStock() {
    queryClient.invalidateQueries({ queryKey: ['dashboard'] })
    queryClient.invalidateQueries({ queryKey: ['medicines'] })
    queryClient.invalidateQueries({ queryKey: ['purchase-orders'] })
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-end justify-between gap-4">
        <div>
          <h2 className="text-2xl font-bold text-ink">Pembelian</h2>
          <p className="mt-1 text-sm text-ink-secondary">
            Buat purchase order dan catat penerimaan barang. Daftar PO ada di Laporan Pembelian.
          </p>
        </div>
      </div>

      {/* Alur persediaan — stepper 3 langkah per Figma #50:823 */}
      <div className="flex items-center gap-3 rounded-card border border-line bg-surface px-5 py-4">
        {STEPS.map((step, index) => (
          <div key={step} className="flex flex-1 items-center gap-3">
            <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-bold text-white">
              {index + 1}
            </span>
            <span className="text-xs font-semibold text-ink">{step}</span>
            {index < STEPS.length - 1 && <span className="h-px flex-1 bg-line" aria-hidden="true" />}
          </div>
        ))}
      </div>

      {success && (
        <p className="rounded-lg bg-success-bg px-4 py-2.5 text-[13px] text-success-ink">{success}</p>
      )}

      <div className="grid grid-cols-2 gap-4">
        <div className="flex flex-col gap-3 rounded-card border border-line bg-surface p-6 shadow-low">
          <span className="flex size-11 items-center justify-center rounded-xl bg-primary-soft text-primary">
            <ClipboardCheck size={22} aria-hidden="true" />
          </span>
          <div>
            <p className="text-[15px] font-bold text-ink">Buat Pembelian</p>
            <p className="mt-0.5 text-[13px] text-ink-secondary">
              Buat purchase order baru ke supplier dengan item, jumlah, dan harga beli.
            </p>
          </div>
          <div>
            <Button onClick={() => setPoFormOpen(true)}>Buat pembelian</Button>
          </div>
        </div>

        <div className="flex flex-col gap-3 rounded-card border border-line bg-surface p-6 shadow-low">
          <span className="flex size-11 items-center justify-center rounded-xl bg-primary-soft text-primary">
            <PackagePlus size={22} aria-hidden="true" />
          </span>
          <div>
            <p className="text-[15px] font-bold text-ink">Penerimaan Barang</p>
            <p className="mt-0.5 text-[13px] text-ink-secondary">
              Catat barang datang berdasarkan nomor PO, atau penerimaan manual tanpa PO. Batch dan
              tanggal kedaluwarsa dicatat di sini.
            </p>
          </div>
          <div className="flex gap-2">
            <Button variant="secondary" onClick={() => setPoLookupOpen(true)}>
              Penerimaan PO
            </Button>
            <Button variant="secondary" onClick={() => setManualOpen(true)}>
              Penerimaan Manual
            </Button>
          </div>
        </div>
      </div>

      <PurchaseOrderFormModal
        open={poFormOpen}
        onClose={() => setPoFormOpen(false)}
        onCreated={(poNumber) => {
          setPoFormOpen(false)
          setSuccess(`PO ${poNumber} dibuat.`)
          queryClient.invalidateQueries({ queryKey: ['purchase-orders'] })
        }}
      />

      <PoReceiptLookupModal
        open={poLookupOpen}
        onClose={() => setPoLookupOpen(false)}
        onReceived={() => {
          setPoLookupOpen(false)
          setSuccess('Penerimaan tersimpan — stok bertambah.')
          invalidateStock()
        }}
      />

      <ManualReceiptModal
        open={manualOpen}
        onClose={() => {
          setManualOpen(false)
          invalidateStock()
        }}
      />
    </div>
  )
}
