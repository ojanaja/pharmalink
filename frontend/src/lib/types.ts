export interface Medicine {
  id: number
  code: string
  name: string
  category: { id: number; name: string } | null
  unit: { id: number; name: string } | null
  sale_price: string
  min_stock: number
  stock_total: string
  is_active: boolean
}

export interface LaravelMeta {
  current_page: number
  last_page: number
  total: number
  from: number | null
  to: number | null
}

export interface LaravelPaginated<T> {
  data: T[]
  meta: LaravelMeta
}

export interface Batch {
  id: number
  batch_number: string
  expiry_date: string
  quantity_on_hand: number
  purchase_price: string
  received_at: string
}

export interface StockMovement {
  id: number
  type: string
  quantity: number
  balance_after: number
  unit_cost: string | null
  reason: string | null
  created_at: string
  batch: { id: number; batch_number: string; expiry_date: string } | null
  user: { id: number; name: string } | null
  reference: { type: string; id: number; number: string } | null
}

export interface DashboardData {
  sales_today: { total: string; transactions: number }
  sales_chart: Array<{ date: string; total: string }>
  low_stock: Array<{
    id: number
    code: string
    name: string
    stock_total: number
    min_stock: number
  }>
  out_of_stock: Array<{ id: number; code: string; name: string; min_stock: number }>
  expiry: {
    warning_days: number
    items: Array<{
      medicine: { id: number; code: string; name: string }
      batch_number: string
      expiry_date: string | null
      quantity_on_hand: number
    }>
    summary: { within_30_days: number; within_60_days: number }
  }
}

export interface SaleTransaction {
  id: number
  invoice_number: string
  sold_at: string
  subtotal: string
  discount: string | null
  total: string
  payment_method: string | null
  user: { id: number; name: string } | null
  items_count?: number
}

export interface SalesReport {
  summary: { omzet: string; transactions: number; average: string }
  medicines: Array<{ code: string; name: string; quantity: number; omzet: string }>
  transactions: LaravelPaginated<SaleTransaction>
}

export interface PurchasesReport {
  summary: { total: string; receipts: number }
  medicines: Array<{ code: string; name: string; quantity: number; total: string }>
  suppliers: Array<{ id: number | null; name: string; receipts: number; total: string }>
}

export interface StockReport {
  medicines: Array<{
    code: string
    name: string
    category: string | null
    stock_total: number
    min_stock: number
    status: string
    stock_value: string
  }>
  total_value: string
  items_without_purchase_price: number
}

export interface ExpiryReport {
  expired: Array<{
    medicine: { code: string; name: string }
    batch_number: string
    expiry_date: string | null
    quantity_on_hand: number
    stock_value: string
  }>
  expiring: Array<{
    medicine: { code: string; name: string }
    batch_number: string
    expiry_date: string | null
    quantity_on_hand: number
    stock_value: string
  }>
  counts: { expired: number; expiring: number }
}

export interface ProfitLossReport {
  sales: string
  cogs: string
  gross_profit: string
  items_without_cost: number
}

export interface Supplier {
  id: number
  name: string
  contact_person: string | null
  phone: string | null
  address: string | null
}

export interface Category {
  id: number
  name: string
}

export interface Unit {
  id: number
  name: string
}

export interface PurchaseOrderListItem {
  id: number
  po_number: string
  ordered_at: string
  expected_date: string | null
  status: string
  note: string | null
  supplier: { id: number; name: string } | null
  total: string
  items_count: number
}

export interface PoItem {
  id: number
  quantity: number
  /** Terisi di endpoint show; di index bernilai null. */
  received_quantity: number | null
  unit_price: string
  subtotal: string
  medicine: { id: number; code: string; name: string } | null
}

export interface PurchaseReceiptItem {
  id: number
  quantity: number
  unit_cost: string
  medicine: { id: number; code: string; name: string } | null
  batch: { id: number; batch_number: string } | null
}

export interface PurchaseReceipt {
  id: number
  receipt_number: string
  received_at: string
  note: string | null
  items: PurchaseReceiptItem[]
}

export interface PurchaseOrderDetail {
  id: number
  po_number: string
  ordered_at: string
  expected_date: string | null
  status: string
  note: string | null
  supplier: { id: number; name: string } | null
  user: { id: number; name: string } | null
  total: string
  items: PoItem[]
  receipts: PurchaseReceipt[]
}
