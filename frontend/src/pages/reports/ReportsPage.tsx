import { NavLink, Navigate, Outlet, useLocation } from 'react-router-dom'

const REPORT_TABS = [
  { path: '/laporan/penjualan', label: 'Penjualan' },
  { path: '/laporan/pembelian', label: 'Pembelian' },
  { path: '/laporan/stok', label: 'Stok' },
  { path: '/laporan/kedaluwarsa', label: 'Kedaluwarsa' },
  { path: '/laporan/laba-rugi', label: 'Laba/Rugi Sederhana' },
]

export function ReportsPage() {
  const location = useLocation()
  const active = REPORT_TABS.find((tab) => location.pathname.startsWith(tab.path))

  return (
    <div className="flex flex-col gap-5">
      <div className="flex items-end justify-between gap-4">
        <div>
          <h2 className="text-2xl font-bold text-ink">{active?.label ?? 'Laporan'}</h2>
          <p className="mt-1 text-sm text-ink-secondary">
            Data laporan diperbarui dari transaksi tercatat pada periode yang dipilih.
          </p>
        </div>
      </div>

      <nav className="flex gap-2" aria-label="Jenis laporan">
        {REPORT_TABS.map((tab) => (
          <NavLink
            key={tab.path}
            to={tab.path}
            className={({ isActive }) =>
              `rounded-full border px-4 py-2 text-[13px] font-bold transition-colors ${
                isActive || location.pathname === tab.path
                  ? 'border-primary bg-primary text-white'
                  : 'border-line bg-surface text-ink-secondary hover:border-primary hover:text-primary'
              }`
            }
          >
            {tab.label}
          </NavLink>
        ))}
      </nav>

      <Outlet />
    </div>
  )
}

export function ReportsIndex() {
  return <Navigate to="/laporan/penjualan" replace />
}
