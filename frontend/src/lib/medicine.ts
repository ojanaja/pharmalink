import type { BadgeVariant } from '../components/ui/Badge'

/**
 * Status stok mengikuti batas minimum obat:
 * stok > min_stock → Aman, 0 < stok ≤ min_stock → Menipis, stok = 0 → Habis.
 */
export function stockStatus(stock: number, minStock: number): { label: string; variant: BadgeVariant } {
  if (stock <= 0) return { label: 'Stok Habis', variant: 'danger' }
  if (stock <= minStock) return { label: 'Menipis', variant: 'warning' }
  return { label: 'Stok Aman', variant: 'success' }
}

/** Status stok dari API laporan (ReportService memakai kode id, bukan label UI). */
const API_STOCK_STATUS: Record<string, { label: string; variant: BadgeVariant }> = {
  aman: { label: 'Stok Aman', variant: 'success' },
  menipis: { label: 'Menipis', variant: 'warning' },
  habis: { label: 'Stok Habis', variant: 'danger' },
}

export function apiStockStatus(status: string): { label: string; variant: BadgeVariant } {
  return API_STOCK_STATUS[status] ?? { label: status, variant: 'neutral' }
}

/** Status purchase order dari backend; label Bahasa Indonesia sesuai pola badge Figma. */
const PO_STATUS: Record<string, { label: string; variant: BadgeVariant }> = {
  ordered: { label: 'Dipesan', variant: 'neutral' },
  partially_received: { label: 'Sebagian Diterima', variant: 'warning' },
  received: { label: 'Diterima', variant: 'success' },
}

export function poStatusLabel(status: string): { label: string; variant: BadgeVariant } {
  return PO_STATUS[status] ?? { label: status, variant: 'neutral' }
}

/** Label Bahasa Indonesia per MovementType backend; tipe tak dikenal ditampilkan apa adanya. */
const MOVEMENT_LABELS: Record<string, { label: string; variant: BadgeVariant }> = {
  sale: { label: 'Penjualan', variant: 'neutral' },
  sale_cancellation: { label: 'Pembatalan', variant: 'success' },
  purchase_receipt: { label: 'Penerimaan', variant: 'success' },
  adjustment: { label: 'Koreksi', variant: 'info' },
  opname: { label: 'Opname', variant: 'info' },
  return_in: { label: 'Retur Masuk', variant: 'success' },
  return_out: { label: 'Retur Keluar', variant: 'neutral' },
}

export function movementTypeLabel(type: string): { label: string; variant: BadgeVariant } {
  return MOVEMENT_LABELS[type] ?? { label: type, variant: 'neutral' }
}
