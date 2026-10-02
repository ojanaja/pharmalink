import { useQuery } from '@tanstack/react-query'
import {
  CalendarClock,
  ClipboardList,
  Package,
  ReceiptText,
  ShoppingBag,
  TriangleAlert,
} from 'lucide-react'
import { Link } from 'react-router-dom'
import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { Badge } from '../components/ui/Badge'
import { StatCard } from '../components/ui/StatCard'
import { api } from '../lib/api'
import { formatDateId, formatRupiah } from '../lib/format'
import type { DashboardData } from '../lib/types'

/** Label tanggal pendek untuk sumbu X: "25 Sep". */
function shortDate(date: string): string {
  return new Intl.DateTimeFormat('id-ID', {
    day: '2-digit',
    month: 'short',
    timeZone: 'Asia/Jakarta',
  }).format(new Date(`${date}T00:00:00`))
}

const QUICK_ACTIONS = [
  {
    icon: ReceiptText,
    title: 'Transaksi Baru',
    desc: 'Mulai penjualan',
    to: '/penjualan',
    enabled: true,
  },
  {
    icon: Package,
    title: 'Penerimaan Barang',
    desc: 'Catat stok masuk',
    to: '/pembelian',
    enabled: true, // modul penerimaan masih bagian dari halaman Pembelian (placeholder)
  },
  {
    icon: ClipboardList,
    title: 'Stock Opname',
    desc: 'Cocokkan stok',
    to: null, // belum ada rute/modul — sengaja nonaktif
    enabled: false,
  },
  {
    icon: CalendarClock,
    title: 'Lihat Expired',
    desc: 'Batch perlu cek',
    to: '/laporan/kedaluwarsa',
    enabled: true,
  },
]

export function DashboardPage() {
  const { data, isPending, isError, refetch } = useQuery({
    queryKey: ['dashboard'],
    queryFn: () => api<{ data: DashboardData }>('/dashboard'),
  })

  if (isError) {
    return (
      <div className="flex flex-col items-center gap-3 rounded-card border border-line bg-surface px-6 py-16 text-center">
        <p className="text-sm text-danger-ink">Gagal memuat ringkasan apotek.</p>
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

  const dashboard = data?.data
  const chartData = (dashboard?.sales_chart ?? []).map((point, index, all) => ({
    ...point,
    total: Number(point.total),
    // Bar terakhir = hari ini, ditekankan seperti di Figma; hari lain pakai warna lembut.
    fill: index === all.length - 1 ? '#087F6A' : '#E2F4EF',
  }))
  const chartTotal = chartData.reduce((sum, point) => sum + point.total, 0)

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h2 className="text-2xl font-bold text-ink">Ringkasan apotek</h2>
        <p className="mt-1 text-sm text-ink-secondary">
          Pantau penjualan dan stok yang perlu perhatian.
        </p>
      </div>

      {/* Metrik utama */}
      {isPending ? (
        <div className="grid grid-cols-4 gap-4">
          {Array.from({ length: 4 }, (_, i) => (
            <div key={i} className="h-32 animate-pulse rounded-card bg-neutral-bg" />
          ))}
        </div>
      ) : (
        <div className="grid grid-cols-4 gap-4">
          <StatCard
            icon={ReceiptText}
            label="Penjualan Hari Ini"
            value={formatRupiah(dashboard?.sales_today.total ?? 0)}
          />
          <StatCard
            icon={ShoppingBag}
            label="Transaksi Hari Ini"
            value={`${dashboard?.sales_today.transactions ?? 0}`}
          />
          <StatCard
            icon={TriangleAlert}
            label="Stok Menipis"
            value={`${dashboard?.low_stock.length ?? 0} obat`}
            note={
              (dashboard?.out_of_stock.length ?? 0) > 0
                ? `${dashboard?.out_of_stock.length} obat habis`
                : 'Semua di atas nol'
            }
            noteClassName="text-warning-ink"
          />
          <StatCard
            icon={CalendarClock}
            label="Mendekati Kedaluwarsa"
            value={`${dashboard?.expiry.summary.within_60_days ?? 0} batch`}
            note="Dalam 60 hari"
            noteClassName="text-danger-ink"
          />
        </div>
      )}

      {/* Grafik + aksi cepat */}
      <div className="flex items-start gap-6">
        <div className="min-w-0 flex-1 rounded-card border border-line bg-surface p-5">
          <div className="mb-4 flex items-center justify-between">
            <h3 className="text-[15px] font-bold text-ink">Penjualan 7 hari terakhir</h3>
            <Badge variant="neutral">7 hari</Badge>
          </div>
          {isPending ? (
            <div className="h-[240px] animate-pulse rounded bg-neutral-bg" />
          ) : (
            <>
              <p className="mb-3 text-xs text-ink-secondary">
                Total {formatRupiah(chartTotal)} dalam 7 hari terakhir.
              </p>
              <div className="h-[240px]">
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart data={chartData} margin={{ top: 4, right: 4, left: 4, bottom: 0 }}>
                    <CartesianGrid vertical={false} stroke="#DDE6E3" />
                    <XAxis
                      dataKey="date"
                      tickFormatter={shortDate}
                      tick={{ fontSize: 11, fill: '#61706C' }}
                      axisLine={false}
                      tickLine={false}
                    />
                    <YAxis hide />
                    <Tooltip
                      cursor={{ fill: 'rgba(8, 127, 106, 0.06)' }}
                      formatter={(value) => [formatRupiah(Number(value)), 'Penjualan']}
                      labelFormatter={(label) => shortDate(String(label))}
                    />
                    <Bar dataKey="total" radius={[4, 4, 0, 0]}>
                      {chartData.map((point, index) => (
                        <Cell key={index} fill={point.fill} />
                      ))}
                    </Bar>
                  </BarChart>
                </ResponsiveContainer>
              </div>
            </>
          )}
        </div>

        {/* Panel aksi cepat */}
        <div className="flex w-[340px] shrink-0 flex-col gap-3">
          <h3 className="text-[15px] font-bold text-ink">Aksi cepat</h3>
          {QUICK_ACTIONS.map((action) => {
            const body = (
              <>
                <span className="flex size-9 items-center justify-center rounded-lg bg-primary-soft text-primary">
                  <action.icon size={18} aria-hidden="true" />
                </span>
                <div>
                  <p className="text-[13px] font-semibold text-ink">{action.title}</p>
                  <p className="text-[11px] text-ink-secondary">{action.desc}</p>
                </div>
                {!action.enabled && (
                  <span className="ml-auto rounded-full bg-neutral-bg px-2 py-0.5 text-[10px] font-bold text-neutral-ink">
                    Segera hadir
                  </span>
                )}
              </>
            )
            const className = `flex items-center gap-3 rounded-card border border-line bg-surface p-4 transition-colors ${
              action.enabled ? 'shadow-low hover:border-primary' : 'opacity-60'
            }`
            return action.enabled && action.to ? (
              <Link key={action.title} to={action.to} className={className}>
                {body}
              </Link>
            ) : (
              <div key={action.title} className={className} aria-disabled="true">
                {body}
              </div>
            )
          })}
        </div>
      </div>

      {/* Tabel perhatian */}
      {isPending ? (
        <div className="grid grid-cols-2 gap-6">
          {Array.from({ length: 2 }, (_, i) => (
            <div key={i} className="h-64 animate-pulse rounded-card bg-neutral-bg" />
          ))}
        </div>
      ) : (
        <div className="grid grid-cols-2 gap-6">
          <div className="overflow-hidden rounded-card border border-line bg-surface">
            <div className="flex items-center justify-between border-b border-line px-5 py-4">
              <div>
                <h3 className="text-[15px] font-bold text-ink">Stok menipis</h3>
                <p className="mt-0.5 text-xs text-ink-secondary">
                  {dashboard?.low_stock.length ?? 0} obat di bawah stok minimum
                </p>
              </div>
              <Link
                to="/persediaan"
                className="text-[13px] font-bold text-primary hover:underline"
              >
                Lihat semua
              </Link>
            </div>
            <AttentionTable
              emptyText="Tidak ada obat dengan stok menipis."
              columns={['Obat', 'Stok', 'Minimum', 'Status']}
              rows={[
                ...(dashboard?.low_stock ?? []).map((item) => ({
                  key: item.id,
                  to: `/persediaan/${item.id}`,
                  cells: [
                    <NameCell key="n" name={item.name} code={item.code} />,
                    <span key="s">{new Intl.NumberFormat('id-ID').format(item.stock_total)}</span>,
                    <span key="m">{new Intl.NumberFormat('id-ID').format(item.min_stock)}</span>,
                    <Badge key="b" variant="warning">
                      Menipis
                    </Badge>,
                  ],
                })),
                ...(dashboard?.out_of_stock ?? []).map((item) => ({
                  key: item.id,
                  to: `/persediaan/${item.id}`,
                  cells: [
                    <NameCell key="n" name={item.name} code={item.code} />,
                    <span key="s">0</span>,
                    <span key="m">{new Intl.NumberFormat('id-ID').format(item.min_stock)}</span>,
                    <Badge key="b" variant="danger">
                      Stok Habis
                    </Badge>,
                  ],
                })),
              ]}
            />
          </div>

          <div className="overflow-hidden rounded-card border border-line bg-surface">
            <div className="flex items-center justify-between border-b border-line px-5 py-4">
              <div>
                <h3 className="text-[15px] font-bold text-ink">Mendekati kedaluwarsa</h3>
                <p className="mt-0.5 text-xs text-ink-secondary">
                  {dashboard?.expiry.summary.within_60_days ?? 0} batch dalam 60 hari
                </p>
              </div>
              <Link
                to="/laporan/kedaluwarsa"
                className="text-[13px] font-bold text-primary hover:underline"
              >
                Lihat semua
              </Link>
            </div>
            <AttentionTable
              emptyText="Tidak ada batch yang mendekati kedaluwarsa."
              columns={['Obat', 'Batch', 'Tanggal Kedaluwarsa', 'Jumlah']}
              rows={(dashboard?.expiry.items ?? []).map((item) => ({
                key: `${item.medicine.id}-${item.batch_number}`,
                to: `/persediaan/${item.medicine.id}`,
                cells: [
                  <NameCell
                    key="n"
                    name={item.medicine.name}
                    code={item.medicine.code}
                  />,
                  <span key="b">{item.batch_number}</span>,
                  <span key="t">
                    {item.expiry_date ? formatDateId(item.expiry_date) : '—'}
                  </span>,
                  <span key="q">
                    {new Intl.NumberFormat('id-ID').format(item.quantity_on_hand)}
                  </span>,
                ],
              }))}
            />
          </div>
        </div>
      )}
    </div>
  )
}

function NameCell({ name, code }: { name: string; code: string }) {
  return (
    <div>
      <p className="text-[13px] font-semibold text-ink">{name}</p>
      <p className="text-[11px] text-ink-secondary">{code}</p>
    </div>
  )
}

interface AttentionRow {
  key: string | number
  to: string
  cells: React.ReactNode[]
}

function AttentionTable({
  columns,
  rows,
  emptyText,
}: {
  columns: string[]
  rows: AttentionRow[]
  emptyText: string
}) {
  return (
    <>
      <div
        className="grid border-t border-line bg-table-header px-5 py-2.5 text-[10px] font-bold uppercase tracking-wide text-ink-secondary"
        style={{ gridTemplateColumns: `repeat(${columns.length}, minmax(0, 1fr))` }}
      >
        {columns.map((column) => (
          <span key={column}>{column}</span>
        ))}
      </div>
      {rows.length === 0 ? (
        <p className="px-5 py-10 text-center text-[13px] text-ink-secondary">{emptyText}</p>
      ) : (
        rows.map((row) => (
          <Link
            key={row.key}
            to={row.to}
            className="grid items-center gap-2 border-t border-line px-5 py-2.5 text-[13px] text-ink transition-colors hover:bg-table-header"
            style={{ gridTemplateColumns: `repeat(${columns.length}, minmax(0, 1fr))` }}
          >
            {row.cells}
          </Link>
        ))
      )}
    </>
  )
}
