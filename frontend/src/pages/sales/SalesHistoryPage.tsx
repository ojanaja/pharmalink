import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query'
import { Ban } from 'lucide-react'
import { useState } from 'react'
import { useAuth } from '../../auth/useAuth'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Field, Input } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { Pagination } from '../../components/ui/Pagination'
import { SortHeader } from '../../components/ui/SortHeader'
import { ApiError, api } from '../../lib/api'
import { formatDateTimeId, formatRupiah, todayLocal } from '../../lib/format'
import { saleStatusLabel } from '../../lib/medicine'
import { sortRows, useSort } from '../../lib/sort'
import type { LaravelPaginated, SaleDetail, SaleTransaction } from '../../lib/types'

// Filter default riwayat = hari ini (Y-m-d, waktu lokal), dihitung sekali saat modul dimuat.
const TODAY = todayLocal()

export function SalesHistoryPage() {
  const { user } = useAuth()
  const isOwner = user?.role === 'owner'
  const queryClient = useQueryClient()

  // Default filter = hari ini.
  const [from, setFrom] = useState(TODAY)
  const [to, setTo] = useState(TODAY)
  const [page, setPage] = useState(1)
  const [statusFilter, setStatusFilter] = useState<'all' | 'completed' | 'cancelled'>('all')
  const { sort, toggleSort } = useSort()

  const { data, isPending, isError, refetch } = useQuery({
    queryKey: ['sales-history', from, to, page],
    queryFn: () =>
      api<LaravelPaginated<SaleTransaction>>(`/sales?from=${from}&to=${to}&page=${page}`),
    placeholderData: keepPreviousData,
  })

  // Sort & filter client-side di halaman yang sedang tampil.
  const visible = sortRows(
    (data?.data ?? []).filter((sale) => statusFilter === 'all' || sale.status === statusFilter),
    sort,
    (sale, key) => {
      switch (key) {
        case 'tanggal':
          return sale.sold_at
        case 'nomor':
          return sale.invoice_number
        case 'total':
          return Number(sale.total)
        default:
          return null
      }
    },
  )
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [returnItem, setReturnItem] = useState<{ saleId: number; itemId: number; name: string } | null>(null)
  const [voidTarget, setVoidTarget] = useState<{ saleId: number; number: string } | null>(null)

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-end gap-3">
        <div className="flex flex-col gap-1">
          <label htmlFor="sales-from" className="text-[11px] text-ink-secondary">
            Dari
          </label>
          <Input id="sales-from" type="date" value={from} onChange={(e) => { setFrom(e.target.value); setPage(1) }} className="w-40" />
        </div>
        <div className="flex flex-col gap-1">
          <label htmlFor="sales-to" className="text-[11px] text-ink-secondary">
            Sampai
          </label>
          <Input id="sales-to" type="date" value={to} min={from} onChange={(e) => { setTo(e.target.value); setPage(1) }} className="w-40" />
        </div>
        <span className="pb-2.5 text-xs text-ink-secondary">
          {data?.meta.total ?? 0} transaksi pada periode ini.
        </span>
        <div className="ml-auto flex gap-2 pb-1">
          {(
            [
              { key: 'all', label: 'Semua' },
              { key: 'completed', label: 'Selesai' },
              { key: 'cancelled', label: 'Dibatalkan' },
            ] as const
          ).map((filter) => (
            <button
              key={filter.key}
              type="button"
              onClick={() => setStatusFilter(filter.key)}
              aria-pressed={statusFilter === filter.key}
              className={`rounded-full border px-3.5 py-1.5 text-[13px] font-bold transition-colors ${
                statusFilter === filter.key
                  ? 'border-primary bg-primary text-white'
                  : 'border-line bg-surface text-ink-secondary hover:border-primary hover:text-primary'
              }`}
            >
              {filter.label}
            </button>
          ))}
        </div>
      </div>

      <div className="overflow-hidden rounded-card border border-line bg-surface shadow-low">
        {isError ? (
          <div className="flex flex-col items-center gap-3 px-6 py-16 text-center">
            <p className="text-sm text-danger-ink">Gagal memuat riwayat penjualan.</p>
            <button type="button" onClick={() => refetch()} className="text-[13px] font-bold text-primary hover:underline">
              Coba lagi
            </button>
          </div>
        ) : (
          <table className="w-full text-left text-sm">
            <thead>
              <tr className="bg-table-header text-[10px] font-bold uppercase tracking-wide text-ink-secondary">
                <SortHeader label="No. Transaksi" sortKey="nomor" sort={sort} onToggle={toggleSort} />
                <SortHeader label="Waktu" sortKey="tanggal" sort={sort} onToggle={toggleSort} />
                <th className="px-4 py-3">Kasir</th>
                <th className="px-4 py-3 text-right">Item</th>
                <SortHeader label="Total" sortKey="total" sort={sort} onToggle={toggleSort} align="right" />
                <th className="px-4 py-3">Status</th>
              </tr>
            </thead>
            <tbody>
              {isPending &&
                Array.from({ length: 6 }, (_, i) => (
                  <tr key={i} className="border-t border-line">
                    {Array.from({ length: 6 }, (_, j) => (
                      <td key={j} className="px-4 py-3">
                        <div className="h-4 animate-pulse rounded bg-neutral-bg" />
                      </td>
                    ))}
                  </tr>
                ))}

              {data && visible.length === 0 && (
                <tr>
                  <td colSpan={6} className="px-6 py-16 text-center text-sm text-ink-secondary">
                    Tidak ada transaksi yang cocok dengan filter.
                  </td>
                </tr>
              )}

              {visible.map((sale) => {
                const status = saleStatusLabel(sale.status)
                return (
                  <tr
                    key={sale.id}
                    onClick={() => setSelectedId(sale.id)}
                    className="cursor-pointer border-t border-line transition-colors hover:bg-table-header"
                  >
                    <td className="px-5 py-3 font-semibold text-ink">{sale.invoice_number}</td>
                    <td className="px-4 py-3 text-ink-secondary">{formatDateTimeId(sale.sold_at)}</td>
                    <td className="px-4 py-3 text-ink-secondary">{sale.user?.name ?? '—'}</td>
                    <td className="px-4 py-3 text-right text-ink-secondary">{sale.items_count ?? 0}</td>
                    <td className="px-4 py-3 text-right font-semibold text-ink">
                      {formatRupiah(sale.total)}
                    </td>
                    <td className="px-4 py-3">
                      <Badge variant={status.variant}>{status.label}</Badge>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        )}
        {data && (
          <Pagination
            currentPage={data.meta.current_page}
            lastPage={data.meta.last_page}
            total={data.meta.total}
            from={data.meta.from ?? 0}
            to={data.meta.to ?? 0}
            onPageChange={setPage}
          />
        )}
      </div>

      <SaleDetailModal
        saleId={selectedId}
        isOwner={isOwner}
        onClose={() => setSelectedId(null)}
        onReturn={(saleId, item) =>
          setReturnItem({ saleId, itemId: item.id, name: item.medicine?.name ?? `Item #${item.id}` })
        }
        onVoid={(saleId, number) => setVoidTarget({ saleId, number })}
      />

      <ReturnModal
        target={returnItem}
        onClose={() => setReturnItem(null)}
        onSaved={() => {
          setReturnItem(null)
          queryClient.invalidateQueries({ queryKey: ['sales-history'] })
          queryClient.invalidateQueries({ queryKey: ['sale-detail'] })
        }}
      />

      <VoidModal
        target={voidTarget}
        onClose={() => setVoidTarget(null)}
        onSaved={() => {
          setVoidTarget(null)
          queryClient.invalidateQueries({ queryKey: ['sales-history'] })
          queryClient.invalidateQueries({ queryKey: ['sale-detail'] })
        }}
      />
    </div>
  )
}

/* ------------------------------- Detail transaksi ------------------------------- */

function SaleDetailModal({
  saleId,
  isOwner,
  onClose,
  onReturn,
  onVoid,
}: {
  saleId: number | null
  isOwner: boolean
  onClose: () => void
  onReturn: (saleId: number, item: SaleDetail['items'][number]) => void
  onVoid: (saleId: number, number: string) => void
}) {
  const { data, isPending } = useQuery({
    queryKey: ['sale-detail', saleId],
    queryFn: () => api<{ data: SaleDetail }>(`/sales/${saleId}`),
    enabled: saleId !== null,
  })
  const sale = data?.data

  return (
    <Modal
      open={saleId !== null}
      title={sale ? sale.invoice_number : 'Detail Transaksi'}
      onClose={onClose}
      wide
    >
      {isPending || !sale ? (
        <div className="h-32 animate-pulse rounded bg-neutral-bg" />
      ) : (
        <div className="flex flex-col gap-4">
          <div className="flex items-center justify-between">
            <p className="text-[13px] text-ink-secondary">
              {formatDateTimeId(sale.sold_at)} · {sale.user?.name ?? '—'} ·{' '}
              {sale.payment_method === 'tunai' ? 'Tunai' : (sale.payment_method ?? '—')}
            </p>
            <Badge variant={saleStatusLabel(sale.status).variant}>
              {saleStatusLabel(sale.status).label}
            </Badge>
          </div>

          {sale.status === 'cancelled' && (
            <p className="rounded-lg bg-danger-bg px-3 py-2 text-xs text-danger-ink">
              Transaksi dibatalkan {sale.cancelled_at ? `pada ${formatDateTimeId(sale.cancelled_at)}` : ''}{' '}
              oleh {sale.cancelled_by?.name ?? '—'}. Alasan: {sale.cancelled_reason ?? '—'}.
            </p>
          )}

          <table className="w-full text-left text-sm">
            <thead>
              <tr className="bg-table-header text-[10px] font-bold uppercase tracking-wide text-ink-secondary">
                <th className="px-3 py-2">Obat</th>
                <th className="px-3 py-2">Batch</th>
                <th className="px-3 py-2 text-right">Qty</th>
                <th className="px-3 py-2 text-right">Harga</th>
                <th className="px-3 py-2 text-right">Subtotal</th>
                <th className="px-3 py-2 text-right">Diretur</th>
                {sale.status === 'completed' && <th className="px-3 py-2" aria-label="Aksi" />}
              </tr>
            </thead>
            <tbody>
              {sale.items.map((item) => (
                <tr key={item.id} className="border-t border-line">
                  <td className="px-3 py-2 text-[13px] font-semibold text-ink">
                    {item.medicine?.name ?? '—'}
                    <span className="block text-[11px] font-normal text-ink-secondary">
                      {item.medicine?.code ?? ''}
                    </span>
                  </td>
                  <td className="px-3 py-2 text-[13px] text-ink-secondary">
                    {item.batch?.batch_number ?? '—'}
                  </td>
                  <td className="px-3 py-2 text-right text-[13px] text-ink-secondary">
                    {item.quantity}
                  </td>
                  <td className="px-3 py-2 text-right text-[13px] text-ink-secondary">
                    {formatRupiah(item.unit_price)}
                  </td>
                  <td className="px-3 py-2 text-right text-[13px] font-semibold text-ink">
                    {formatRupiah(item.subtotal)}
                  </td>
                  <td className="px-3 py-2 text-right text-[13px] text-ink-secondary">
                    {item.returned_quantity > 0 ? item.returned_quantity : '—'}
                    <span className="block text-[11px] text-placeholder">
                      sisa {item.returnable_quantity}
                    </span>
                  </td>
                  {sale.status === 'completed' && (
                    <td className="px-3 py-2 text-right">
                      {item.returnable_quantity > 0 && (
                        <button
                          type="button"
                          onClick={() => onReturn(sale.id, item)}
                          className="text-[12px] font-bold text-primary hover:underline"
                        >
                          Retur
                        </button>
                      )}
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>

          <div className="flex items-center justify-between border-t border-line pt-3">
            <div>
              <p className="text-xs text-ink-secondary">Subtotal {formatRupiah(sale.subtotal)}</p>
              {Number(sale.discount ?? 0) > 0 && (
                <p className="text-xs text-ink-secondary">
                  Diskon −{formatRupiah(sale.discount ?? 0)}
                </p>
              )}
            </div>
            <div className="flex items-center gap-4">
              <p className="text-xl font-bold text-primary">{formatRupiah(sale.total)}</p>
              {isOwner && sale.status === 'completed' && (
                <Button variant="danger" size="sm" icon={<Ban size={13} />} onClick={() => onVoid(sale.id, sale.invoice_number)}>
                  Void
                </Button>
              )}
            </div>
          </div>
        </div>
      )}
    </Modal>
  )
}

/* --------------------------------- Retur item --------------------------------- */

function ReturnModal({
  target,
  onClose,
  onSaved,
}: {
  target: { saleId: number; itemId: number; name: string } | null
  onClose: () => void
  onSaved: () => void
}) {
  const [quantity, setQuantity] = useState('')
  const [reason, setReason] = useState('')
  const [initialized, setInitialized] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  if (target && !initialized) {
    setQuantity('')
    setReason('')
    setError(null)
    setInitialized(true)
  }
  if (!target && initialized) {
    setInitialized(false)
  }

  async function handleSubmit() {
    if (!target) return
    setSubmitting(true)
    setError(null)
    try {
      await api(`/sales/${target.saleId}/returns`, {
        method: 'POST',
        body: {
          reason: reason.trim(),
          items: [{ sale_item_id: target.itemId, quantity: Number(quantity) }],
        },
      })
      onSaved()
    } catch (err) {
      setError(
        err instanceof ApiError ? err.message : 'Retur gagal. Periksa koneksi dan coba lagi.',
      )
    } finally {
      setSubmitting(false)
    }
  }

  const canSubmit = Number(quantity) > 0 && reason.trim().length >= 5 && !submitting

  return (
    <Modal
      open={target !== null}
      title="Retur Item"
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button loading={submitting} disabled={!canSubmit} onClick={handleSubmit}>
            Simpan Retur
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <p className="text-[13px] text-ink-secondary">
          {target?.name} — jumlah retur tidak boleh melebihi sisa yang dapat diretur.
        </p>
        <div className="grid grid-cols-2 gap-4">
          <Field label="Jumlah retur *">
            <Input
              type="number"
              min={1}
              value={quantity}
              onChange={(event) => setQuantity(event.target.value)}
            />
          </Field>
          <Field label="Alasan retur * (minimal 5 karakter)">
            <Input value={reason} onChange={(event) => setReason(event.target.value)} placeholder="Contoh: Kemasan rusak" />
          </Field>
        </div>
        {error && (
          <div role="alert" className="rounded-lg bg-danger-bg px-3 py-2 text-xs text-danger-ink">
            {error}
          </div>
        )}
      </div>
    </Modal>
  )
}

/* ----------------------------------- Void ----------------------------------- */

function VoidModal({
  target,
  onClose,
  onSaved,
}: {
  target: { saleId: number; number: string } | null
  onClose: () => void
  onSaved: () => void
}) {
  const [reason, setReason] = useState('')
  const [initialized, setInitialized] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  if (target && !initialized) {
    setReason('')
    setError(null)
    setInitialized(true)
  }
  if (!target && initialized) {
    setInitialized(false)
  }

  async function handleSubmit() {
    if (!target) return
    setSubmitting(true)
    setError(null)
    try {
      await api(`/sales/${target.saleId}/void`, {
        method: 'POST',
        body: { reason: reason.trim() },
      })
      onSaved()
    } catch (err) {
      // 409 bila transaksi sudah dibatalkan sebelumnya; 403 bila bukan owner.
      setError(err instanceof ApiError ? err.message : 'Void gagal. Periksa koneksi dan coba lagi.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Modal
      open={target !== null}
      title={`Void transaksi ${target?.number ?? ''}?`}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button variant="danger" loading={submitting} disabled={reason.trim().length < 5} onClick={handleSubmit}>
            Ya, batalkan transaksi
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <p>
          Seluruh item transaksi dikembalikan ke batch asal dan laba dihitung ulang. Tindakan ini
          tidak dapat dibatalkan.
        </p>
        <Field label="Alasan void * (minimal 5 karakter)">
          <Input value={reason} onChange={(event) => setReason(event.target.value)} placeholder="Contoh: Salah input transaksi" />
        </Field>
        {error && (
          <div role="alert" className="rounded-lg bg-danger-bg px-3 py-2 text-xs text-danger-ink">
            {error}
          </div>
        )}
      </div>
    </Modal>
  )
}
