/** Format Rupiah gaya desain: "Rp2.450.000" (tanpa spasi; sen ditampilkan bila ada — backend mengizinkan 2 desimal). */
export function formatRupiah(value: number | string): string {
  const amount = Number(value)
  if (Number.isNaN(amount)) return 'Rp0'
  return `Rp${new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(amount)}`
}

/** Tanggal hari ini format Y-m-d berbasis waktu lokal perangkat (bukan UTC) — untuk min input date. */
export function todayLocal(): string {
  const now = new Date()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  return `${now.getFullYear()}-${month}-${day}`
}

/** Periode default laporan = bulan berjalan (zona Asia/Jakarta), format Y-m-d untuk API. */
export function currentMonthPeriod(): { from: string; to: string } {
  const now = new Date()
  const year = now.getFullYear()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  return { from: `${year}-${month}-01`, to: `${year}-${month}-${day}` }
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

/** Format tanggal-waktu gaya struk: "28 Sep 2026, 14.32 WIB" (zona Asia/Jakarta). */
export function formatDateTimeId(value: string | Date): string {
  const date = typeof value === 'string' ? new Date(value) : value
  const parts = new Intl.DateTimeFormat('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
    timeZone: 'Asia/Jakarta',
  }).format(date)
  // id-ID dengan hour12:false menghasilkan "28 Sep 2026, 23.01"; ganti pemisah jam menjadi titik.
  return `${parts.replace(':', '.')} WIB`
}
