import { ShieldAlert } from 'lucide-react'
import { NavLink, Navigate, Outlet } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth'

const SETTING_TABS = [
  { path: '/pengaturan/profil', label: 'Profil Apotek' },
  { path: '/pengaturan/transaksi', label: 'Transaksi' },
  { path: '/pengaturan/users', label: 'User & Hak Akses' },
  { path: '/pengaturan/matriks', label: 'Matriks Akses' },
]

/** Seluruh area pengaturan owner-only — PUT /settings & semua endpoint users dilarang untuk apoteker. */
export function SettingsPage() {
  const { user } = useAuth()

  if (user?.role !== 'owner') {
    return (
      <div className="flex flex-col items-center gap-3 rounded-card border border-line bg-surface px-6 py-16 text-center">
        <span className="rounded-xl bg-danger-bg p-3 text-danger-ink">
          <ShieldAlert size={24} aria-hidden="true" />
        </span>
        <div>
          <p className="text-lg font-bold text-ink">Akses dibatasi</p>
          <p className="mt-1 text-sm text-ink-secondary">
            Halaman pengaturan hanya dapat diakses oleh Owner.
          </p>
        </div>
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-5">
      <div>
        <h2 className="text-2xl font-bold text-ink">Pengaturan</h2>
        <p className="mt-1 text-sm text-ink-secondary">
          Kelola profil apotek, konfigurasi transaksi, dan akun staf.
        </p>
      </div>

      <nav className="flex flex-wrap gap-2" aria-label="Bagian pengaturan">
        {SETTING_TABS.map((tab) => (
          <NavLink
            key={tab.path}
            to={tab.path}
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

export function SettingsIndex() {
  return <Navigate to="/pengaturan/profil" replace />
}
