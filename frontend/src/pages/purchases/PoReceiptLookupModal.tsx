import { useQuery } from '@tanstack/react-query'
import { Search } from 'lucide-react'
import { useState } from 'react'
import { Badge } from '../../components/ui/Badge'
import { Input } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { ApiError, api } from '../../lib/api'
import { formatRupiah } from '../../lib/format'
import { poStatusLabel } from '../../lib/medicine'
import { useDebounce } from '../../lib/useDebounce'
import type { LaravelPaginated, PurchaseOrderDetail, PurchaseOrderListItem } from '../../lib/types'
import { ReceiptFormModal } from './ReceiptFormModal'

interface PoReceiptLookupModalProps {
  open: boolean
  onClose: () => void
  onReceived: () => void
}

const RECEIVABLE = ['ordered', 'partially_received']

/**
 * Penerimaan barang berdasarkan PO tanpa daftar: cari nomor PO → pilih →
 * buka form penerimaan (batch & expired dicatat per item, stok masuk FEFO).
 */
export function PoReceiptLookupModal({ open, onClose, onReceived }: PoReceiptLookupModalProps) {
  const [search, setSearch] = useState('')
  const [initialized, setInitialized] = useState(false)
  const debouncedSearch = useDebounce(search)
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [error, setError] = useState<string | null>(null)

  if (open && !initialized) {
    setSearch('')
    setSelectedId(null)
    setError(null)
    setInitialized(true)
  }
  if (!open && initialized) {
    setInitialized(false)
  }

  const { data, isPending } = useQuery({
    queryKey: ['po-lookup', debouncedSearch],
    queryFn: () =>
      api<LaravelPaginated<PurchaseOrderListItem>>(
        `/purchase-orders?search=${encodeURIComponent(debouncedSearch)}&per_page=10`,
      ),
    enabled: open && debouncedSearch.trim().length >= 2,
  })

  // Detail PO dibutuhkan karena list tidak memuat received_quantity per item.
  const detailQuery = useQuery({
    queryKey: ['purchase-order', selectedId],
    queryFn: () => api<{ data: PurchaseOrderDetail }>(`/purchase-orders/${selectedId}`),
    enabled: selectedId !== null,
    retry: false,
  })

  const poDetail = detailQuery.data?.data

  function handleClose() {
    setSelectedId(null)
    onClose()
  }

  return (
    <>
      <Modal open={open && selectedId === null} title="Penerimaan Berdasarkan PO" onClose={handleClose}>
        <div className="flex flex-col gap-4">
          <p className="text-[13px] text-ink-secondary">
            Cari nomor purchase order untuk mencatat penerimaan barang, batch, dan tanggal
            kedaluwarsa.
          </p>
          <div className="relative">
            <Search
              size={16}
              className="absolute left-3 top-1/2 -translate-y-1/2 text-placeholder"
              aria-hidden="true"
            />
            <Input
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Ketik nomor PO, mis. PO-20261024-…"
              className="pl-9"
              aria-label="Cari nomor PO"
              autoFocus
            />
          </div>

          {search.trim().length < 2 ? (
            <p className="rounded-lg bg-table-header px-4 py-6 text-center text-[13px] text-ink-secondary">
              Ketik minimal 2 karakter untuk mencari PO.
            </p>
          ) : isPending ? (
            <div className="flex flex-col gap-2">
              {Array.from({ length: 3 }, (_, i) => (
                <div key={i} className="h-12 animate-pulse rounded-lg bg-neutral-bg" />
              ))}
            </div>
          ) : data && data.data.length === 0 ? (
            <p className="rounded-lg bg-table-header px-4 py-6 text-center text-[13px] text-ink-secondary">
              Tidak ada PO yang cocok dengan pencarian.
            </p>
          ) : (
            <ul className="flex flex-col gap-2">
              {data?.data.map((po) => {
                const status = poStatusLabel(po.status)
                const receivable = RECEIVABLE.includes(po.status)
                return (
                  <li key={po.id}>
                    <button
                      type="button"
                      disabled={!receivable}
                      onClick={() => setSelectedId(po.id)}
                      className={`flex w-full items-center justify-between gap-3 rounded-lg border px-4 py-3 text-left transition-colors ${
                        receivable
                          ? 'border-line hover:border-primary'
                          : 'cursor-not-allowed border-line opacity-60'
                      }`}
                    >
                      <div>
                        <p className="text-[13px] font-semibold text-ink">{po.po_number}</p>
                        <p className="text-[11px] text-ink-secondary">
                          {po.supplier?.name ?? '—'} · {formatRupiah(po.total)}
                        </p>
                      </div>
                      <Badge variant={status.variant}>{status.label}</Badge>
                    </button>
                  </li>
                )
              })}
            </ul>
          )}
          {error && (
            <p role="alert" className="rounded-lg bg-danger-bg px-3 py-2 text-xs text-danger-ink">
              {error}
            </p>
          )}
        </div>
      </Modal>

      {selectedId !== null && detailQuery.isPending && (
        <Modal open title="Memuat PO…" onClose={() => setSelectedId(null)}>
          <div className="h-24 animate-pulse rounded bg-neutral-bg" />
        </Modal>
      )}

      {selectedId !== null && poDetail && (
        <ReceiptFormModal
          open
          poId={selectedId}
          items={poDetail.items}
          onClose={() => setSelectedId(null)}
          onCreated={() => {
            setSelectedId(null)
            onReceived()
          }}
        />
      )}

      {selectedId !== null && detailQuery.isError && (
        <Modal open title="Gagal memuat PO" onClose={() => setSelectedId(null)}>
          <p className="text-sm text-danger-ink">
            {detailQuery.error instanceof ApiError
              ? detailQuery.error.message
              : 'Gagal memuat detail PO.'}
          </p>
        </Modal>
      )}
    </>
  )
}
