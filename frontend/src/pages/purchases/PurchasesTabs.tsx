import { NavLink, Outlet } from 'react-router-dom'

const SUB_TABS = [
  { path: '/pembelian', label: 'Pembelian', end: true },
  { path: '/pembelian/retur', label: 'Retur Pembelian', end: false },
]

/** Sub-nav kecil halaman Pembelian — transaksi di index, pencatatan retur di sub-tab. */
export function PurchasesTabs() {
  return (
    <div className="flex flex-col gap-4">
      <nav className="flex gap-2" aria-label="Bagian pembelian">
        {SUB_TABS.map((tab) => (
          <NavLink
            key={tab.path}
            to={tab.path}
            end={tab.end}
            className={({ isActive }) =>
              `rounded-full border px-4 py-2 text-[13px] font-bold transition-colors ${
                isActive
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
