import { useQuery } from '@tanstack/react-query'
import { CircleAlert, Minus, Plus, Search, Trash2 } from 'lucide-react'
import { useMemo, useState } from 'react'
import type { FormEvent } from 'react'
import { useAuth } from '../../auth/useAuth'
import { Button } from '../../components/ui/Button'
import { Input } from '../../components/ui/Field'
import { ApiError, api } from '../../lib/api'
import { formatRupiah } from '../../lib/format'
import { useDebounce } from '../../lib/useDebounce'
import type { LaravelPaginated, Medicine } from '../../lib/types'
import { SaleSuccessView } from './SaleSuccessView'

interface CartItem {
  medicine: Medicine
  quantity: number
}

/** Bentuk `errors.items` saat 422 stok tidak cukup dari SaleService. */
interface StockErrorItem {
  medicine_id: number
  name: string
  requested: number
  available: number
}

function parseStockErrors(errors: unknown): StockErrorItem[] {
  if (errors && typeof errors === 'object' && 'items' in errors) {
    const items = (errors as { items?: unknown }).items
    if (Array.isArray(items)) return items as StockErrorItem[]
  }
  return []
}

interface SaleCreated {
  id: number
  invoice_number: string
  sold_at: string
  subtotal: string
  discount: string | null
  total: string
  payment_method: string | null
}

function parseNumber(value: string): number {
  const parsed = Number(value)
  return Number.isFinite(parsed) && parsed > 0 ? parsed : 0
}

export function CashierPage() {
  const { user } = useAuth()

  const [search, setSearch] = useState('')
  const debouncedSearch = useDebounce(search)
  const [cart, setCart] = useState<CartItem[]>([])
  const [discount, setDiscount] = useState('')
  const [paid, setPaid] = useState('')
  const [stockErrors, setStockErrors] = useState<StockErrorItem[]>([])
  const [submitting, setSubmitting] = useState(false)
  const [submitError, setSubmitError] = useState<string | null>(null)
  const [sale, setSale] = useState<SaleCreated | null>(null)
  const [lastCart, setLastCart] = useState<CartItem[]>([])

  const { data, isPending, isError, refetch } = useQuery({
    queryKey: ['medicines-search', debouncedSearch],
    queryFn: () =>
      api<LaravelPaginated<Medicine>>(
        `/medicines?search=${encodeURIComponent(debouncedSearch)}`,
      ),
    enabled: sale === null,
  })

  const subtotal = useMemo(
    () =>
      cart.reduce((sum, item) => sum + Number(item.medicine.sale_price) * item.quantity, 0),
    [cart],
  )
  const discountNum = parseNumber(discount)
  const discountInvalid = discountNum > subtotal && subtotal > 0
  const total = Math.max(subtotal - (discountInvalid ? 0 : discountNum), 0)
  const paidNum = parseNumber(paid)
  const paidShort = total - paidNum

  function addToCart(medicine: Medicine) {
    setSubmitError(null)
    setCart((prev) => {
      const existing = prev.find((item) => item.medicine.id === medicine.id)
      if (existing) {
        return prev.map((item) =>
          item.medicine.id === medicine.id ? { ...item, quantity: item.quantity + 1 } : item,
        )
      }
      return [...prev, { medicine, quantity: 1 }]
    })
  }

  function updateQuantity(medicineId: number, quantity: number) {
    // Perubahan qty menyembunyikan error stok item tersebut; validasi ulang milik server.
    setStockErrors((prev) => prev.filter((error) => error.medicine_id !== medicineId))
    setCart((prev) =>
      quantity <= 0
        ? prev.filter((item) => item.medicine.id !== medicineId)
        : prev.map((item) => (item.medicine.id === medicineId ? { ...item, quantity } : item)),
    )
  }

  function clearCart() {
    setCart([])
    setStockErrors([])
    setDiscount('')
    setPaid('')
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    if (cart.length === 0 || paidShort > 0 || discountInvalid || submitting) return
    setSubmitting(true)
    setSubmitError(null)
    try {
      const created = await api<SaleCreated>('/sales', {
        method: 'POST',
        body: {
          items: cart.map((item) => ({
            medicine_id: item.medicine.id,
            quantity: item.quantity,
          })),
          discount: discountNum > 0 ? discountNum : null,
          payment_method: 'tunai',
        },
      })
      setSale(created)
      setLastCart(cart)
      clearCart()
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) {
        const items = parseStockErrors(err.errors)
        if (items.length > 0) {
          setStockErrors(items)
          setSubmitError(null)
          return
        }
      }
      setSubmitError(
        err instanceof ApiError ? err.message : 'Transaksi gagal. Periksa koneksi dan coba lagi.',
      )
    } finally {
      setSubmitting(false)
    }
  }

  if (sale) {
    return (
      <SaleSuccessView
        sale={sale}
        cashierName={user?.name ?? 'Kasir'}
        cart={lastCart}
        onReset={() => {
          setSale(null)
          setSearch('')
          setLastCart([])
          clearCart()
        }}
      />
    )
  }

  const itemCount = cart.reduce((sum, item) => sum + item.quantity, 0)

  return (
    <div className="flex flex-col gap-4">
      <div>
        <h2 className="text-2xl font-bold text-ink">Penjualan / Kasir</h2>
        <p className="mt-1 text-sm text-ink-secondary">
          Cari obat atau pindai barcode untuk menambahkan ke keranjang.
        </p>
      </div>

      <div className="flex items-start gap-6">
        {/* Panel kiri — pencarian obat */}
        <div className="min-w-0 flex-1">
          <div className="overflow-hidden rounded-card border border-line bg-surface shadow-low">
            <div className="border-b border-line p-4">
              <div className="relative">
                <Search
                  size={16}
                  className="absolute left-3 top-1/2 -translate-y-1/2 text-placeholder"
                  aria-hidden="true"
                />
                <Input
                  value={search}
                  onChange={(event) => setSearch(event.target.value)}
                  placeholder="Cari nama, kode obat, atau pindai barcode…"
                  className="pl-9"
                  aria-label="Cari obat"
                />
              </div>
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
                    <th className="px-5 py-3">Obat</th>
                    <th className="px-4 py-3">Stok Tersedia</th>
                    <th className="px-4 py-3 text-right">Harga</th>
                    <th className="px-4 py-3" aria-label="Aksi" />
                  </tr>
                </thead>
                <tbody>
                  {isPending &&
                    Array.from({ length: 4 }, (_, i) => (
                      <tr key={i} className="border-t border-line">
                        {Array.from({ length: 4 }, (_, j) => (
                          <td key={j} className="px-4 py-3">
                            <div className="h-4 animate-pulse rounded bg-neutral-bg" />
                          </td>
                        ))}
                      </tr>
                    ))}

                  {data && data.data.length === 0 && (
                    <tr>
                      <td colSpan={4} className="px-6 py-16 text-center text-sm text-ink-secondary">
                        Tidak ada obat yang cocok dengan pencarian.
                      </td>
                    </tr>
                  )}

                  {data?.data.map((medicine) => {
                    const stock = Number(medicine.stock_total)
                    const outOfStock = stock <= 0
                    return (
                      <tr key={medicine.id} className="border-t border-line">
                        <td className="px-5 py-3">
                          <p className="text-[13px] font-semibold text-ink">{medicine.name}</p>
                          <p className="text-[11px] text-ink-secondary">{medicine.code}</p>
                        </td>
                        <td
                          className={`px-4 py-3 text-[13px] ${
                            outOfStock ? 'font-semibold text-danger-ink' : 'text-ink-secondary'
                          }`}
                        >
                          {new Intl.NumberFormat('id-ID').format(stock)} {medicine.unit?.name ?? ''}
                        </td>
                        <td className="px-4 py-3 text-right text-[13px] text-ink">
                          {formatRupiah(medicine.sale_price)}
                        </td>
                        <td className="px-4 py-3 text-right">
                          <Button
                            size="sm"
                            variant={outOfStock ? 'secondary' : 'primary'}
                            disabled={outOfStock}
                            onClick={() => addToCart(medicine)}
                          >
                            {outOfStock ? 'Habis' : 'Tambah'}
                          </Button>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            )}
          </div>
        </div>

        {/* Panel kanan — keranjang (~470px) */}
        <form
          onSubmit={handleSubmit}
          className="flex w-[470px] shrink-0 flex-col gap-4 rounded-card border border-line bg-surface p-5 shadow-low"
        >
          <div className="flex items-center justify-between">
            <h3 className="text-[15px] font-bold text-ink">
              Keranjang — {cart.length} jenis obat · {itemCount} item
            </h3>
            {cart.length > 0 && (
              <button
                type="button"
                onClick={clearCart}
                className="text-xs font-bold text-danger-ink hover:underline"
              >
                Kosongkan
              </button>
            )}
          </div>

          {cart.length === 0 ? (
            <p className="rounded-lg bg-table-header px-4 py-8 text-center text-[13px] text-ink-secondary">
              Keranjang masih kosong. Tambahkan obat dari daftar pencarian.
            </p>
          ) : (
            <ul className="flex flex-col">
              {cart.map((item) => {
                const error = stockErrors.find(
                  (stockError) => stockError.medicine_id === item.medicine.id,
                )
                return (
                  <li key={item.medicine.id} className="border-t border-line py-3 first:border-t-0 first:pt-0">
                    <div className="flex items-center gap-3">
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-[13px] font-semibold text-ink">
                          {item.medicine.name}
                        </p>
                        <p className="text-[11px] text-ink-secondary">
                          {formatRupiah(item.medicine.sale_price)} × {item.quantity}
                        </p>
                      </div>
                      <div className="flex items-center gap-1">
                        <button
                          type="button"
                          aria-label="Kurangi jumlah"
                          onClick={() => updateQuantity(item.medicine.id, item.quantity - 1)}
                          className="flex size-7 items-center justify-center rounded-md border border-input-border text-ink-secondary transition-colors hover:border-primary hover:text-primary"
                        >
                          <Minus size={13} />
                        </button>
                        <span className="w-8 text-center text-[13px] font-semibold text-ink">
                          {item.quantity}
                        </span>
                        <button
                          type="button"
                          aria-label="Tambah jumlah"
                          onClick={() => updateQuantity(item.medicine.id, item.quantity + 1)}
                          className="flex size-7 items-center justify-center rounded-md border border-input-border text-ink-secondary transition-colors hover:border-primary hover:text-primary"
                        >
                          <Plus size={13} />
                        </button>
                      </div>
                      <p className="w-20 text-right text-[13px] font-semibold text-ink">
                        {formatRupiah(Number(item.medicine.sale_price) * item.quantity)}
                      </p>
                      <button
                        type="button"
                        aria-label={`Hapus ${item.medicine.name}`}
                        onClick={() => updateQuantity(item.medicine.id, 0)}
                        className="text-ink-secondary transition-colors hover:text-danger-ink"
                      >
                        <Trash2 size={15} />
                      </button>
                    </div>
                    {error && (
                      <div className="mt-2 flex items-start gap-1.5 rounded-lg bg-danger-bg px-3 py-2 text-xs text-danger-ink">
                        <CircleAlert size={13} className="mt-px shrink-0" aria-hidden="true" />
                        Stok tidak cukup. Diminta {error.requested}, tersedia {error.available}{' '}
                        {item.medicine.unit?.name ?? ''}. Kurangi jumlah menjadi {error.available}{' '}
                        atau kurang.
                      </div>
                    )}
                  </li>
                )
              })}
            </ul>
          )}

          <div className="flex flex-col gap-2 border-t border-line pt-3 text-[13px]">
            <div className="flex items-center justify-between">
              <span className="text-ink-secondary">Subtotal</span>
              <span className="font-semibold text-ink">{formatRupiah(subtotal)}</span>
            </div>
            <div className="flex items-center justify-between gap-4">
              <span className="text-ink-secondary">Diskon (opsional)</span>
              <div className="flex w-40 flex-col gap-1">
                <Input
                  type="number"
                  min={0}
                  placeholder="0"
                  value={discount}
                  onChange={(event) => setDiscount(event.target.value)}
                  invalid={discountInvalid}
                  className="h-8 text-right"
                  aria-label="Diskon"
                />
                {discountInvalid && (
                  <span className="text-right text-[11px] text-danger-ink">
                    Diskon melebihi subtotal.
                  </span>
                )}
              </div>
            </div>
            <div className="flex items-center justify-between border-t border-line pt-2">
              <span className="text-ink-secondary">Total</span>
              <span className="text-xl font-bold text-primary">{formatRupiah(total)}</span>
            </div>
          </div>

          {/* Keputusan desain: metode selain Tunai disembunyikan dulu (tunai dulu). */}
          <div className="flex flex-col gap-1.5">
            <span className="text-[13px] font-semibold text-ink">Metode Pembayaran</span>
            <span className="inline-flex w-fit items-center rounded-full border border-primary bg-primary-soft px-3 py-1.5 text-[13px] font-bold text-primary">
              Tunai
            </span>
          </div>

          <div className="flex flex-col gap-1.5">
            <label htmlFor="paid" className="text-[13px] font-semibold text-ink">
              Pembayaran
            </label>
            <Input
              id="paid"
              type="number"
              min={0}
              placeholder="0"
              value={paid}
              onChange={(event) => setPaid(event.target.value)}
              className="text-right"
            />
          </div>

          {paidNum > 0 && paidShort <= 0 && (
            <div className="flex items-center justify-between rounded-lg bg-table-header px-3 py-2 text-[13px]">
              <span className="text-ink-secondary">Kembalian</span>
              <span className="font-bold text-ink">{formatRupiah(paidNum - total)}</span>
            </div>
          )}
          {paidNum > 0 && paidShort > 0 && (
            <div className="flex items-center gap-1.5 rounded-lg bg-danger-bg px-3 py-2 text-xs text-danger-ink">
              <CircleAlert size={13} aria-hidden="true" />
              Pembayaran kurang {formatRupiah(paidShort)}.
            </div>
          )}

          {submitError && (
            <div role="alert" className="rounded-lg bg-danger-bg px-3 py-2 text-xs text-danger-ink">
              {submitError}
            </div>
          )}

          <Button
            type="submit"
            size="lg"
            loading={submitting}
            disabled={cart.length === 0 || paidShort > 0 || discountInvalid}
            className="w-full"
          >
            Bayar · {formatRupiah(total)}
          </Button>
        </form>
      </div>
    </div>
  )
}
