import { useQuery } from '@tanstack/react-query'
import { Eye } from 'lucide-react'
import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Badge } from '../components/ui/Badge'
import { Pagination } from '../components/ui/Pagination'
import { api } from '../lib/api'
import { formatRupiah } from '../lib/format'
import { stockStatus } from '../lib/medicine'
import type { LaravelPaginated, Medicine } from '../lib/types'

export function MedicinesPage() {
  const navigate = useNavigate()
  const [page, setPage] = useState(1)
  const { data, isPending, isError, refetch } = useQuery({
    queryKey: ['medicines', page],
    queryFn: () => api<LaravelPaginated<Medicine>>(`/medicines?page=${page}`),
  })

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-end justify-between gap-4">
        <div>
          <h2 className="text-2xl font-bold text-ink">Persediaan Obat</h2>
          <p className="mt-1 text-sm text-ink-secondary">
            Pantau ketersediaan obat lintas batch, lokasi penyimpanan, dan batas minimum stok.
          </p>
        </div>
      </div>

      <div className="overflow-hidden rounded-card border border-line bg-surface shadow-low">
        <div className="border-b border-line px-5 py-4">
          <h3 className="text-[15px] font-bold text-ink">Daftar Stok Obat</h3>
          <p className="mt-0.5 text-xs text-ink-secondary">
            Stok total merupakan akumulasi seluruh batch aktif.
          </p>
        </div>

        {isError ? (
          <div className="flex flex-col items-center gap-3 px-6 py-16 text-center">
            <p className="text-sm text-danger-ink">Gagal memuat data obat.</p>
            <button
              type="button"
              onClick={() => refetch()}
              className="text-[13px] font-bold text-primary hover:underline"
            >
              Coba lagi
            </button>
          </div>
        ) : (
          <table className="w-full text-left text-sm">
            <thead>
              <tr className="bg-table-header text-[10px] font-bold uppercase tracking-wide text-ink-secondary">
                <th className="px-5 py-3">Kode</th>
                <th className="px-4 py-3">Nama Obat</th>
                <th className="px-4 py-3">Kategori</th>
                <th className="px-4 py-3">Satuan</th>
                <th className="px-4 py-3 text-right">Stok Total</th>
                <th className="px-4 py-3 text-right">Harga Jual</th>
                <th className="px-4 py-3">Status</th>
                <th className="px-4 py-3" aria-label="Aksi" />
              </tr>
            </thead>
            <tbody>
              {isPending &&
                Array.from({ length: 5 }, (_, i) => (
                  <tr key={i} className="border-t border-line">
                    {Array.from({ length: 8 }, (_, j) => (
                      <td key={j} className="px-4 py-3">
                        <div className="h-4 animate-pulse rounded bg-neutral-bg" />
                      </td>
                    ))}
                  </tr>
                ))}

              {data && data.data.length === 0 && (
                <tr>
                  <td colSpan={8} className="px-6 py-16 text-center text-sm text-ink-secondary">
                    Belum ada data obat.
                  </td>
                </tr>
              )}

              {data?.data.map((medicine) => {
                const stock = Number(medicine.stock_total)
                const status = stockStatus(stock, medicine.min_stock)
                return (
                  <tr
                    key={medicine.id}
                    onClick={() => navigate(`/persediaan/${medicine.id}`)}
                    className="cursor-pointer border-t border-line transition-colors hover:bg-table-header"
                  >
                    <td className="px-5 py-3 font-medium text-ink-secondary">{medicine.code}</td>
                    <td className="px-4 py-3 font-semibold text-ink">{medicine.name}</td>
                    <td className="px-4 py-3 text-ink-secondary">
                      {medicine.category?.name ?? '—'}
                    </td>
                    <td className="px-4 py-3 text-ink-secondary">{medicine.unit?.name ?? '—'}</td>
                    <td className="px-4 py-3 text-right font-semibold text-ink">
                      {new Intl.NumberFormat('id-ID').format(stock)}
                    </td>
                    <td className="px-4 py-3 text-right text-ink-secondary">
                      {formatRupiah(medicine.sale_price)}
                    </td>
                    <td className="px-4 py-3">
                      <Badge variant={status.variant}>{status.label}</Badge>
                    </td>
                    <td className="px-4 py-3 text-right">
                      <button
                        type="button"
                        aria-label={`Lihat detail ${medicine.name}`}
                        onClick={(event) => {
                          event.stopPropagation()
                          navigate(`/persediaan/${medicine.id}`)
                        }}
                        className="text-ink-secondary transition-colors hover:text-primary"
                      >
                        <Eye size={16} />
                      </button>
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
    </div>
  )
}
