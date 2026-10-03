import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Info, Wrench } from 'lucide-react'
import { useMemo, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { Badge } from '../components/ui/Badge'
import { Pagination } from '../components/ui/Pagination'
import { SortHeader } from '../components/ui/SortHeader'
import { Select } from '../components/ui/Field'
import { api } from '../lib/api'
import { formatDateId, formatDateTimeId, formatRupiah } from '../lib/format'
import { movementTypeLabel, stockStatus } from '../lib/medicine'
import { sortRows, useSort } from '../lib/sort'
import type { Batch, LaravelPaginated, Medicine, StockMovement } from '../lib/types'
import { AdjustmentModal } from './AdjustmentModal'

interface MedicineDetail extends Medicine {
  description: string | null
  batches: Batch[]
}

/** Sisa hari menuju kedaluwarsa dihitung terhadap hari ini (Asia/Jakarta). */
function daysUntil(date: string): number {
  const now = new Date()
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  const target = new Date(`${date}T00:00:00`)
  return Math.round((target.getTime() - today.getTime()) / 86_400_000)
}

function expiryVariant(days: number): { label: string; className: string } {
  if (days < 0) return { label: 'Kedaluwarsa', className: 'bg-danger-bg text-danger-ink' }
  if (days <= 30) return { label: '≤ 30 hari', className: 'bg-danger-bg text-danger-ink' }
  if (days <= 60) return { label: '≤ 60 hari', className: 'bg-warning-bg text-warning-ink' }
  return { label: 'Aman', className: 'bg-success-bg text-success-ink' }
}

export function MedicineDetailPage() {
  const { id } = useParams<{ id: string }>()
  const queryClient = useQueryClient()
  const [page, setPage] = useState(1)
  const [adjusting, setAdjusting] = useState<Batch | null>(null)
  const [movementTypeFilter, setMovementTypeFilter] = useState('all')
  const { sort: batchSort, toggleSort: toggleBatchSort } = useSort()
  const { sort: movementSort, toggleSort: toggleMovementSort } = useSort()

  const medicineQuery = useQuery({
    queryKey: ['medicine', id],
    queryFn: () => api<{ data: MedicineDetail }>(`/medicines/${id}`),
    enabled: Boolean(id),
  })
  const movementsQuery = useQuery({
    queryKey: ['movements', id, page],
    queryFn: () => api<LaravelPaginated<StockMovement>>(`/medicines/${id}/movements?page=${page}`),
    enabled: Boolean(id),
  })

  const medicine = medicineQuery.data?.data
  // Batch diurutkan kedaluwarsa terdekat dulu — prioritas FEFO untuk pengeluaran stok.
  const batchesFefo = useMemo(
    () => [...(medicine?.batches ?? [])].sort((a, b) => a.expiry_date.localeCompare(b.expiry_date)),
    [medicine],
  )
  // Tampilan tabel batch bisa di-sort user; urutan FEFO tetap dipakai untuk catatan FEFO.
  const batches = sortRows(batchesFefo, batchSort, (batch, key) => {
    switch (key) {
      case 'batch':
        return batch.batch_number
      case 'expiry':
        return batch.expiry_date
      case 'qty':
        return batch.quantity_on_hand
      case 'price':
        return Number(batch.purchase_price)
      default:
        return null
    }
  })

  // Filter tipe + sort kartu stok — client-side di halaman yang sedang tampil.
  const visibleMovements = sortRows(
    (movementsQuery.data?.data ?? []).filter(
      (m) => movementTypeFilter === 'all' || m.type === movementTypeFilter,
    ),
    movementSort,
    (m, key) => {
      switch (key) {
        case 'tanggal':
          return m.created_at
        case 'tipe':
          return m.type
        case 'qty':
          return Math.abs(m.quantity)
        case 'saldo':
          return m.balance_after
        default:
          return null
      }
    },
  )

  if (medicineQuery.isPending) {
    return (
      <div className="flex flex-col gap-4">
        <div className="h-8 w-64 animate-pulse rounded bg-neutral-bg" />
        <div className="grid grid-cols-3 gap-4">
          {Array.from({ length: 3 }, (_, i) => (
            <div key={i} className="h-28 animate-pulse rounded-card bg-neutral-bg" />
          ))}
        </div>
        <div className="h-64 animate-pulse rounded-card bg-neutral-bg" />
      </div>
    )
  }

  if (medicineQuery.isError || !medicine) {
    return (
      <div className="flex flex-col items-center gap-3 rounded-card border border-line bg-surface px-6 py-16 text-center">
        <p className="text-sm text-danger-ink">Gagal memuat detail obat.</p>
        <button
          type="button"
          onClick={() => medicineQuery.refetch()}
          className="text-[13px] font-bold text-primary hover:underline"
        >
          Coba lagi
        </button>
      </div>
    )
  }

  const stock = Number(medicine.stock_total)
  const status = stockStatus(stock, medicine.min_stock)
  const nearestBatch = batches.find((batch) => batch.quantity_on_hand > 0) ?? batches[0]
  const nearestDays = nearestBatch ? daysUntil(nearestBatch.expiry_date) : null

  const info: Array<[string, string]> = [
    ['Kode obat', medicine.code],
    ['Kategori', medicine.category?.name ?? '—'],
    ['Satuan', medicine.unit?.name ?? '—'],
    ['Harga jual', `${formatRupiah(medicine.sale_price)} / ${medicine.unit?.name ?? 'unit'}`],
    ['Minimum stok', `${new Intl.NumberFormat('id-ID').format(medicine.min_stock)}`],
    ['Status', medicine.is_active ? 'Aktif' : 'Nonaktif'],
  ]

  return (
    <div className="flex flex-col gap-6">
      <Link
        to="/persediaan"
        className="flex w-fit items-center gap-1.5 text-[13px] font-semibold text-ink-secondary transition-colors hover:text-primary"
      >
        <ArrowLeft size={15} aria-hidden="true" />
        Kembali ke Stok Obat
      </Link>

      {/* Kepala */}
      <div>
        <p className="text-[13px] font-semibold text-ink-secondary">
          {medicine.code} • {medicine.category?.name ?? 'Tanpa kategori'}
        </p>
        <div className="mt-1 flex items-center gap-3">
          <h2 className="text-2xl font-bold text-ink">{medicine.name}</h2>
          <Badge variant={status.variant}>{status.label}</Badge>
        </div>
        <p className="mt-1 text-sm text-ink-secondary">
          {medicine.unit?.name ?? ''} · stok dikelola berdasarkan batch dan tanggal kedaluwarsa.
        </p>
      </div>

      {/* Sorotan — lokasi penyimpanan tidak tersedia di API M1–M3, tidak digambar */}
      <div className="grid grid-cols-3 gap-4">
        <div className="rounded-card border border-line bg-surface p-5">
          <p className="text-[10px] font-bold uppercase tracking-wide text-ink-secondary">
            Total Stok
          </p>
          <p className="mt-2 text-2xl font-bold text-ink">
            {new Intl.NumberFormat('id-ID').format(stock)}{' '}
            <span className="text-sm font-semibold text-ink-secondary">
              {medicine.unit?.name ?? ''}
            </span>
          </p>
          <p className="mt-1 text-xs text-ink-secondary">Akumulasi {batches.length} batch</p>
        </div>
        <div className="rounded-card border border-line bg-surface p-5">
          <p className="text-[10px] font-bold uppercase tracking-wide text-ink-secondary">
            Batch Terdekat Expired
          </p>
          <p className="mt-2 text-2xl font-bold text-ink">
            {nearestBatch ? formatDateId(nearestBatch.expiry_date) : '—'}
          </p>
          <p className="mt-1 text-xs text-ink-secondary">
            {nearestBatch && nearestDays !== null
              ? `${nearestDays >= 0 ? nearestDays : 0} hari lagi · Batch ${nearestBatch.batch_number}`
              : 'Tidak ada batch'}
          </p>
        </div>
        <div className="rounded-card border border-line bg-surface p-5">
          <p className="text-[10px] font-bold uppercase tracking-wide text-ink-secondary">
            Minimum Stok
          </p>
          <p className="mt-2 text-2xl font-bold text-ink">
            {new Intl.NumberFormat('id-ID').format(medicine.min_stock)}
          </p>
          <p className="mt-1 text-xs text-ink-secondary">Batas peringatan stok menipis</p>
        </div>
      </div>

      {/* Informasi obat */}
      <div className="rounded-card border border-line bg-surface p-5">
        <h3 className="text-[15px] font-bold text-ink">Informasi Obat</h3>
        <dl className="mt-4 grid grid-cols-3 gap-x-8 gap-y-3">
          {info.map(([label, value]) => (
            <div key={label}>
              <dt className="text-xs text-ink-secondary">{label}</dt>
              <dd className="mt-0.5 text-[13px] font-semibold text-ink">{value}</dd>
            </div>
          ))}
        </dl>
      </div>

      {/* Stok per batch */}
      <div className="overflow-hidden rounded-card border border-line bg-surface">
        <div className="flex items-center justify-between border-b border-line px-5 py-4">
          <div>
            <h3 className="text-[15px] font-bold text-ink">Stok per Batch</h3>
            <p className="mt-0.5 text-xs text-ink-secondary">
              Satu obat dapat tersimpan pada beberapa batch dengan tanggal kedaluwarsa berbeda.
            </p>
          </div>
          <Badge variant="neutral">
            Total {new Intl.NumberFormat('id-ID').format(stock)} {medicine.unit?.name ?? ''}
          </Badge>
        </div>
        <table className="w-full text-left text-sm">
          <thead>
            <tr className="bg-table-header text-[10px] font-bold uppercase tracking-wide text-ink-secondary">
              <SortHeader label="Batch" sortKey="batch" sort={batchSort} onToggle={toggleBatchSort} />
              <SortHeader label="Expired" sortKey="expiry" sort={batchSort} onToggle={toggleBatchSort} />
              <SortHeader label="Qty" sortKey="qty" sort={batchSort} onToggle={toggleBatchSort} align="right" />
              <SortHeader
                label="Harga Beli"
                sortKey="price"
                sort={batchSort}
                onToggle={toggleBatchSort}
                align="right"
              />
              <th className="px-4 py-3" aria-label="Aksi" />
            </tr>
          </thead>
          <tbody>
            {batches.length === 0 && (
              <tr>
                <td colSpan={5} className="px-6 py-12 text-center text-sm text-ink-secondary">
                  Belum ada batch untuk obat ini.
                </td>
              </tr>
            )}
            {batches.map((batch) => {
              const days = daysUntil(batch.expiry_date)
              const expiry = expiryVariant(days)
              return (
                <tr key={batch.id} className="border-t border-line">
                  <td className="px-5 py-3 text-[13px] font-semibold text-ink">
                    {batch.batch_number}
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-2">
                      <span className="text-[13px] text-ink-secondary">
                        {formatDateId(batch.expiry_date)}
                      </span>
                      <span
                        className={`rounded-full px-2 py-0.5 text-[10px] font-bold ${expiry.className}`}
                      >
                        {expiry.label}
                      </span>
                    </div>
                  </td>
                  <td className="px-4 py-3 text-right text-[13px] font-semibold text-ink">
                    {new Intl.NumberFormat('id-ID').format(batch.quantity_on_hand)}
                  </td>
                  <td className="px-4 py-3 text-right text-[13px] text-ink-secondary">
                    {formatRupiah(batch.purchase_price)}
                  </td>
                  <td className="px-4 py-3 text-right">
                    <button
                      type="button"
                      aria-label={`Koreksi stok batch ${batch.batch_number}`}
                      onClick={() => setAdjusting(batch)}
                      className="text-ink-secondary transition-colors hover:text-primary"
                    >
                      <Wrench size={15} />
                    </button>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
        <div className="flex items-center gap-2 border-t border-line bg-info-bg px-3.5 py-3 text-[11px] text-info-ink">
          <Info size={14} className="shrink-0" aria-hidden="true" />
          Pengeluaran stok mengikuti metode FEFO: batch dengan kedaluwarsa paling dekat
          {nearestBatch ? ` (${nearestBatch.batch_number})` : ''} diprioritaskan.
        </div>
      </div>

      <AdjustmentModal
        open={adjusting !== null}
        medicineName={medicine.name}
        batch={adjusting}
        onClose={() => setAdjusting(null)}
        onSaved={() => {
          setAdjusting(null)
          // Koreksi mengubah qty batch + menambah movement — segarkan keduanya.
          queryClient.invalidateQueries({ queryKey: ['medicine', id] })
          queryClient.invalidateQueries({ queryKey: ['movements', id] })
        }}
      />

      {/* Kartu stok */}
      <div className="overflow-hidden rounded-card border border-line bg-surface">
        <div className="flex items-center justify-between border-b border-line px-5 py-4">
          <div>
            <h3 className="text-[15px] font-bold text-ink">Kartu Stok</h3>
            <p className="mt-0.5 text-xs text-ink-secondary">
              Riwayat seluruh pergerakan stok obat ini.
            </p>
          </div>
          <div className="w-48">
            <Select
              value={movementTypeFilter}
              onChange={(event) => setMovementTypeFilter(event.target.value)}
              aria-label="Filter tipe pergerakan"
            >
              <option value="all">Semua tipe</option>
              <option value="sale">Penjualan</option>
              <option value="sale_cancellation">Pembatalan</option>
              <option value="purchase_receipt">Penerimaan</option>
              <option value="adjustment">Koreksi</option>
              <option value="opname">Opname</option>
              <option value="return_in">Retur Masuk</option>
              <option value="return_out">Retur Keluar</option>
            </Select>
          </div>
        </div>

        {movementsQuery.isError ? (
          <div className="flex flex-col items-center gap-3 px-6 py-12 text-center">
            <p className="text-sm text-danger-ink">Gagal memuat kartu stok.</p>
            <button
              type="button"
              onClick={() => movementsQuery.refetch()}
              className="text-[13px] font-bold text-primary hover:underline"
            >
              Coba lagi
            </button>
          </div>
        ) : (
          <table className="w-full text-left text-sm">
            <thead>
              <tr className="bg-table-header text-[10px] font-bold uppercase tracking-wide text-ink-secondary">
                <SortHeader label="Tanggal" sortKey="tanggal" sort={movementSort} onToggle={toggleMovementSort} />
                <SortHeader label="Tipe" sortKey="tipe" sort={movementSort} onToggle={toggleMovementSort} />
                <th className="px-4 py-3">Referensi</th>
                <th className="px-4 py-3">Batch</th>
                <th className="px-4 py-3 text-right">Masuk</th>
                <th className="px-4 py-3 text-right">Keluar</th>
                <SortHeader label="Saldo" sortKey="saldo" sort={movementSort} onToggle={toggleMovementSort} align="right" />
              </tr>
            </thead>
            <tbody>
              {movementsQuery.isPending &&
                Array.from({ length: 5 }, (_, i) => (
                  <tr key={i} className="border-t border-line">
                    {Array.from({ length: 7 }, (_, j) => (
                      <td key={j} className="px-4 py-3">
                        <div className="h-4 animate-pulse rounded bg-neutral-bg" />
                      </td>
                    ))}
                  </tr>
                ))}

              {movementsQuery.data && visibleMovements.length === 0 && (
                <tr>
                  <td colSpan={7} className="px-6 py-12 text-center text-sm text-ink-secondary">
                    Tidak ada pergerakan stok yang cocok dengan filter.
                  </td>
                </tr>
              )}

              {visibleMovements.map((movement) => {
                const type = movementTypeLabel(movement.type)
                const qtyIn = movement.quantity > 0 ? movement.quantity : null
                const qtyOut = movement.quantity < 0 ? Math.abs(movement.quantity) : null
                return (
                  <tr key={movement.id} className="border-t border-line">
                    <td className="px-5 py-3 text-[13px] text-ink-secondary">
                      {formatDateTimeId(movement.created_at)}
                    </td>
                    <td className="px-4 py-3">
                      <Badge variant={type.variant}>{type.label}</Badge>
                    </td>
                    <td className="px-4 py-3 text-[13px] text-ink">
                      {movement.reference?.number ?? '—'}
                    </td>
                    <td className="px-4 py-3 text-[13px] text-ink-secondary">
                      {movement.batch?.batch_number ?? '—'}
                    </td>
                    <td className="px-4 py-3 text-right text-[13px] font-semibold text-success-ink">
                      {qtyIn !== null ? `+${new Intl.NumberFormat('id-ID').format(qtyIn)}` : ''}
                    </td>
                    <td className="px-4 py-3 text-right text-[13px] font-semibold text-danger-ink">
                      {qtyOut !== null ? `−${new Intl.NumberFormat('id-ID').format(qtyOut)}` : ''}
                    </td>
                    <td className="px-4 py-3 text-right text-[13px] font-semibold text-ink">
                      {new Intl.NumberFormat('id-ID').format(movement.balance_after)}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        )}

        {movementsQuery.data && (
          <Pagination
            currentPage={movementsQuery.data.meta.current_page}
            lastPage={movementsQuery.data.meta.last_page}
            total={movementsQuery.data.meta.total}
            from={movementsQuery.data.meta.from ?? 0}
            to={movementsQuery.data.meta.to ?? 0}
            onPageChange={setPage}
          />
        )}
      </div>
    </div>
  )
}
