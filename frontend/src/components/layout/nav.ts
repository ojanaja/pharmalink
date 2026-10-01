import {
  Database,
  FileChartColumn,
  LayoutDashboard,
  Package,
  Settings,
  ShoppingCart,
  Truck,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'

export interface NavItem {
  path: string
  label: string
  icon: LucideIcon
}

export const NAV_ITEMS: NavItem[] = [
  { path: '/', label: 'Dashboard', icon: LayoutDashboard },
  { path: '/penjualan', label: 'Penjualan', icon: ShoppingCart },
  { path: '/persediaan', label: 'Persediaan', icon: Package },
  { path: '/pembelian', label: 'Pembelian', icon: Truck },
  { path: '/master-data', label: 'Master Data', icon: Database },
  { path: '/laporan', label: 'Laporan', icon: FileChartColumn },
  { path: '/pengaturan', label: 'Pengaturan', icon: Settings },
]
