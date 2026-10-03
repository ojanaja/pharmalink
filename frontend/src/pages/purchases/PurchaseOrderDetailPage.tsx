import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, PackageCheck, RotateCcw } from 'lucide-react'
import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { api } from '../../lib/api'
import { formatDateId, formatDateTimeId, formatRupiah } from '../../lib/format'
import { poStatusLabel } from '../../lib/medicine'
import type { PurchaseOrderDetail, PurchaseReceiptItem } from '../../lib/types'
import { PurchaseReturnModal } from './PurchaseReturnModal'
import { ReceiptFormModal } from './ReceiptFormModal'

export function PurchaseOrderDetailPage() {
  const { id } = useParams<{ id: string }>()
  const [receiptOpen, setReceiptOpen] = useState(false)
  const [returnTarget, setReturnTarget] = useState<PurchaseReceiptItem | null>(null)
  const [returnSaved, setReturnSaved] = useState<string | null>(null)

  const { data, isPending, isError, refetch } = useQuery({
    queryKey: ['purchase-order', id],
    queryFn: () => api<{ data: PurchaseOrderDetail }>(`/purchase-orders/${id}`),
    enabled: Boolean(id),
  })

  if (isPending) {
    return (
      <div className="flex flex-col gap-4">
        <div className="h-8 w-72 animate-pulse rounded bg-neutral-bg" />
        <div className="h-64 animate-pulse rounded-card bg-neutral-bg" />
      </div>
    )
  }

  if (isError || !data) {
    return (
      <div className="flex flex-col items-center gap-3 rounded-card border border-line bg-surface px-6 py-16 text-center">
        <p className="text-sm text-danger-ink">Gagal memuat detail pembelian.</p>
        <button
          type="button"
          onClick={() => refetch()}
          className="text-[13px] font-bold text-primary hover:underline"
        >
          Coba lagi
        </button>
      </div>
    )
  }

  const po = data.data
  const status = poStatusLabel(po.status)
  const canReceive = po.status !== 'received'

  return (
    <div className="flex flex-col gap-6">
      <Link
        to="/pembelian"
        className="flex w-fit items-center gap-1.5 text-[13px] font-semibold text-ink-secondary transition-colors hover:text-primary"
      >
        <ArrowLeft size={15} aria-hidden="true" />
        Kembali ke Pembelian
      </Link>

      <div className="flex items-start justify-between gap-4">
        <div>
          <div className="flex items-center gap-3">
            <h2 className="text-2xl font-bold text-ink">{po.po_number}</h2>
            <Badge variant={status.variant}>{status.label}</Badge>
          </div>
          <p className="mt-1 text-sm text-ink-secondary">
            {po.supplier?.name ?? 'Tanpa supplier'} · {formatDateId(po.ordered_at)}
            {po.expected_date ? ` · perkiraan datang ${formatDateId(po.expected_date)}` : ''} ·
            dibuat oleh {po.user?.name ?? '—'}
          </p>
          {po.note && <p className="mt-1 text-sm text-ink-secondary">Catatan: {po.note}</p>}
        </div>
        {canReceive && (
          <Button icon={<PackageCheck size={15} />} onClick={() => setReceiptOpen(true)}>
            Penerimaan
          </Button>
        )}
      </div>

      <div className="overflow-hidden rounded-card border border-line bg-surface">
        <div className="flex items-center justify-between border-b border-line px-5 py-4">
          <h3 className="text-[15px] font-bold text-ink">Item Purchase Order</h3>
          <span className="text-[15px] font-bold text-primary">{formatRupiah(po.total)}</span>
        </div>
        <table className="w-full text-left text-sm">
          <thead>
            <tr className="bg-table-header text-[10px] font-bold uppercase tracking-wide text-ink-secondary">
              <th className="px-5 py-3">Obat</th>
              <th className="px-4 py-3 text-right">Dipesan</th>
              <th className="px-4 py-3 text-right">Diterima</th>
              <th className="px-4 py-3 text-right">Harga</th>
              <th className="px-4 py-3 text-right">Subtotal</th>
            </tr>
          </thead>
          <tbody>
            {po.items.map((item) => {
              const received = item.received_quantity ?? 0
              return (
                <tr key={item.id} className="border-t border-line">
                  <td className="px-5 py-3">
                    <p className="text-[13px] font-semibold text-ink">
                      {item.medicine?.name ?? '—'}
                    </p>
                    <p className="text-[11px] text-ink-secondary">{item.medicine?.code ?? ''}</p>
                  </td>
                  <td className="px-4 py-3 text-right text-ink-secondary">{item.quantity}</td>
                  <td
                    className={`px-4 py-3 text-right font-semibold ${
                      received >= item.quantity ? 'text-success-ink' : 'text-warning-ink'
                    }`}
                  >
                    {received}
                  </td>
                  <td className="px-4 py-3 text-right text-ink-secondary">
                    {formatRupiah(item.unit_price)}
                  </td>
                  <td className="px-4 py-3 text-right font-semibold text-ink">
                    {formatRupiah(item.subtotal)}
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>

      <div className="overflow-hidden rounded-card border border-line bg-surface">
        <div className="border-b border-line px-5 py-4">
          <h3 className="text-[15px] font-bold text-ink">Riwayat Penerimaan</h3>
          <p className="mt-0.5 text-xs text-ink-secondary">
            {po.receipts.length} penerimaan tercatat untuk PO ini.
          </p>
        </div>
        {po.receipts.length === 0 ? (
          <p className="px-6 py-10 text-center text-sm text-ink-secondary">
            Belum ada penerimaan untuk PO ini.
          </p>
        ) : (
          po.receipts.map((receipt) => (
            <div key={receipt.id} className="border-t border-line first:border-t-0">
              <div className="flex items-center justify-between px-5 py-3">
                <div>
                  <p className="text-[13px] font-semibold text-ink">{receipt.receipt_number}</p>
                  <p className="text-[11px] text-ink-secondary">
                    {formatDateTimeId(receipt.received_at)}
                  </p>
                </div>
                <Badge variant="success">{receipt.items.length} item</Badge>
              </div>
              <table className="w-full text-left text-sm">
                <tbody>
                  {receipt.items.map((item) => (
                    <tr key={item.id} className="border-t border-line bg-table-header">
                      <td className="px-5 py-2 text-[13px] text-ink">
                        {item.medicine?.name ?? '—'}
                      </td>
                      <td className="px-4 py-2 text-[13px] text-ink-secondary">
                        Batch {item.batch?.batch_number ?? '—'}
                      </td>
                      <td className="px-4 py-2 text-right text-[13px] text-ink-secondary">
                        {item.quantity} unit
                      </td>
                      <td className="px-4 py-2 text-right text-[13px] font-semibold text-ink">
                        {formatRupiah(item.unit_cost)}
                      </td>
                      <td className="px-4 py-2 text-right">
                        {item.returnable_quantity > 0 ? (
                          <button
                            type="button"
                            onClick={() => setReturnTarget(item)}
                            className="inline-flex items-center gap-1 text-[12px] font-bold text-primary hover:underline"
                          >
                            <RotateCcw size={12} aria-hidden="true" />
                            Retur (sisa {item.returnable_quantity})
                          </button>
                        ) : (
                          <span className="text-[11px] text-placeholder">
                            {item.returned_quantity > 0 ? 'Sudah diretur' : '—'}
                          </span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ))
        )}
      </div>

      <ReceiptFormModal
        open={receiptOpen}
        poId={po.id}
        items={po.items}
        onClose={() => setReceiptOpen(false)}
        onCreated={() => {
          setReceiptOpen(false)
          refetch()
        }}
      />

      <PurchaseReturnModal
        open={returnTarget !== null}
        supplierId={po.supplier?.id ?? null}
        item={
          returnTarget
            ? {
                id: returnTarget.id,
                medicineName: returnTarget.medicine?.name ?? '—',
                batchNumber: returnTarget.batch?.batch_number ?? '—',
                returnable: returnTarget.returnable_quantity,
              }
            : null
        }
        onClose={() => setReturnTarget(null)}
        onSaved={(returnNumber) => {
          setReturnTarget(null)
          setReturnSaved(returnNumber)
          refetch()
        }}
      />

      {returnSaved && (
        <p className="rounded-lg bg-success-bg px-4 py-2.5 text-[13px] text-success-ink">
          Retur {returnSaved} tersimpan — stok batch berkurang dan dicatat pada kartu stok.
        </p>
      )}
    </div>
  )
}
