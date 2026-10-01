/** Format Rupiah gaya desain: "Rp2.450.000" (tanpa spasi, titik ribuan). */
export function formatRupiah(value: number | string): string {
  const amount = Number(value)
  if (Number.isNaN(amount)) return 'Rp0'
  return `Rp${new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(amount)}`
}

/** Format tanggal singkat Bahasa Indonesia: "29 Sep 2026" (zona Asia/Jakarta). */
export function formatDateId(value: string | Date): string {
  const date = typeof value === 'string' ? new Date(value) : value
  return new Intl.DateTimeFormat('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    timeZone: 'Asia/Jakarta',
  }).format(date)
}
