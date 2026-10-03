import { Check, History, PackageCheck } from 'lucide-react'
import { Link } from 'react-router-dom'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { formatDateTimeId, formatRupiah } from '../../lib/format'

export interface SaleCreated {
  id: number
  invoice_number: string
  sold_at: string
  subtotal: string
  discount: string | null
  total: string
  payment_method: string | null
}

export interface CartLine {
  medicine: { name: string; unit: { name: string } | null; sale_price: string }
  quantity: number
}

interface SaleSuccessViewProps {
  sale: SaleCreated
  cashierName: string
  /** Respons store tidak menyertakan items; rincian struk memakai keranjang yang baru saja terkirim. */
  cart: CartLine[]
  onReset: () => void
}

/** Kartu konfirmasi 570px radius 16 shadow sedang — pola Figma #18:3173. */
export function SaleSuccessView({ sale, cashierName, cart, onReset }: SaleSuccessViewProps) {
  const discount = Number(sale.discount ?? 0)

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h2 className="text-2xl font-bold text-ink">Pembayaran selesai</h2>
        <p className="mt-1 text-sm text-ink-secondary">
          Transaksi sudah tercatat dan stok telah diperbarui.
        </p>
      </div>

      <div className="flex items-start gap-6">
        {/* Konfirmasi transaksi */}
        <div className="w-[570px] shrink-0 overflow-hidden rounded-modal border border-line bg-surface shadow-medium">
          <div className="flex flex-col items-center gap-3.5 px-8 pb-6 pt-8">
            <span className="flex size-16 items-center justify-center rounded-full bg-success-bg">
              <Check size={32} className="text-success-ink" aria-hidden="true" />
            </span>
            <div className="text-center">
              <p className="text-[28px] font-bold text-ink">Transaksi Berhasil</p>
              <p className="mt-1 text-[13px] font-semibold text-primary">{sale.invoice_number}</p>
              <p className="mt-1 text-[11px] text-ink-secondary">
                {formatDateTimeId(sale.sold_at)} · Kasir {cashierName}
              </p>
            </div>
            <Badge variant="success">Pembayaran Tunai</Badge>
          </div>

          <div className="flex flex-col gap-3 border-t border-line bg-table-header px-6 py-6">
            <div className="flex items-center justify-between text-xs text-ink-secondary">
              <span>Subtotal</span>
              <span className="text-[13px] font-semibold text-ink">
                {formatRupiah(sale.subtotal)}
              </span>
            </div>
            {discount > 0 && (
              <div className="flex items-center justify-between text-xs text-ink-secondary">
                <span>Diskon</span>
                <span className="text-[13px] font-semibold text-ink">
                  −{formatRupiah(discount)}
                </span>
              </div>
            )}
            <div className="h-px bg-line" />
            <div className="flex items-center justify-between">
              <span className="text-xs text-ink-secondary">Total</span>
              <span className="text-xl font-bold text-primary">{formatRupiah(sale.total)}</span>
            </div>
          </div>

          <div className="flex flex-col gap-2.5 px-6 py-6">
            <Button size="lg" icon={<Check size={16} />} onClick={onReset}>
              Transaksi Baru
            </Button>
            <p className="text-center text-[10px] text-placeholder">
              Struk otomatis tersimpan di riwayat penjualan.
            </p>
          </div>
        </div>

        {/* Rincian struk + langkah berikutnya */}
        <div className="flex min-w-0 flex-1 flex-col gap-4">
          <div className="overflow-hidden rounded-card border border-line bg-surface">
            <div className="flex items-center justify-between px-[18px] py-4">
              <div>
                <p className="text-base font-bold text-ink">Rincian struk</p>
                <p className="mt-0.5 text-[11px] text-ink-secondary">
                  {cart.length} jenis obat ·{' '}
                  {cart.reduce((sum, line) => sum + line.quantity, 0)} item
                </p>
              </div>
              <Badge variant="success">Lunas</Badge>
            </div>
            <div className="grid grid-cols-[1fr_110px] gap-2 border-t border-line bg-table-header px-[18px] py-2.5 text-[10px] font-bold uppercase tracking-wide text-ink-secondary">
              <span>Obat</span>
              <span className="text-right">Subtotal</span>
            </div>
            {cart.map((line, index) => (
              <div
                key={index}
                className="flex items-center justify-between gap-4 border-t border-line px-[18px] py-2.5"
              >
                <div>
                  <p className="text-[13px] font-semibold text-ink">{line.medicine.name}</p>
                  <p className="text-[11px] text-ink-secondary">
                    {line.quantity} {line.medicine.unit?.name ?? ''} ×{' '}
                    {formatRupiah(line.medicine.sale_price)}
                  </p>
                </div>
                <p className="w-[110px] text-right text-[13px] font-semibold text-ink">
                  {formatRupiah(Number(line.medicine.sale_price) * line.quantity)}
                </p>
              </div>
            ))}
            <div className="flex items-center gap-2 border-t border-line bg-info-bg px-3.5 py-3.5 text-[11px] text-info-ink">
              <PackageCheck size={17} className="shrink-0" aria-hidden="true" />
              Stok {cart.length} obat telah diperbarui otomatis sesuai batch yang dipilih.
            </div>
          </div>

          <div className="flex flex-col gap-3 rounded-card border border-line bg-surface p-5">
            <p className="text-base font-bold text-ink">Langkah berikutnya</p>
            <Link
              to="/penjualan/riwayat"
              className="flex items-center gap-3 rounded-lg bg-table-header p-4 transition-colors hover:bg-primary-soft"
            >
              <History size={20} className="shrink-0 text-primary" aria-hidden="true" />
              <div>
                <p className="text-[13px] font-semibold text-ink">Lihat riwayat penjualan</p>
                <p className="text-[11px] text-ink-secondary">
                  Buka detail transaksi kapan saja.
                </p>
              </div>
            </Link>
          </div>
        </div>
      </div>
    </div>
  )
}
