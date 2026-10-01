import { Cross } from 'lucide-react'
import { NavLink } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth'
import { NAV_ITEMS } from './nav'

function initials(name: string): string {
  return name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join('')
}

export function Sidebar() {
  const { user } = useAuth()

  return (
    <aside className="flex h-screen w-60 shrink-0 flex-col bg-sidebar">
      {/* Brand */}
      <div className="flex items-center gap-2.5 px-4 pb-4 pt-5">
        <span className="flex size-9 items-center justify-center rounded-[10px] bg-primary">
          <Cross size={18} className="text-white" aria-hidden="true" />
        </span>
        <span className="text-xs font-bold text-white">Apotek Sehat Sentosa</span>
      </div>

      {/* Menu — 7 item, tinggi 42, ikon 18 + label 13 */}
      <nav className="flex flex-1 flex-col gap-1 px-3 py-2" aria-label="Navigasi utama">
        {NAV_ITEMS.map(({ path, label, icon: Icon }) => (
          <NavLink
            key={path}
            to={path}
            end={path === '/'}
            className={({ isActive }) =>
              `flex h-[42px] items-center gap-2.5 rounded-lg px-3 text-[13px] transition-colors ${
                isActive
                  ? 'bg-[rgba(255,255,255,0.09)] font-bold text-white'
                  : 'font-medium text-sidebar-text hover:bg-[rgba(255,255,255,0.05)] hover:text-white'
              }`
            }
          >
            <Icon size={18} aria-hidden="true" />
            {label}
          </NavLink>
        ))}
      </nav>

      {/* Blok akun */}
      {user && (
        <div className="border-t border-[rgba(255,255,255,0.13)] px-4 py-4">
          <div className="flex items-center gap-3">
            <span className="flex size-[34px] shrink-0 items-center justify-center rounded-full bg-[#D9F3EC] text-[13px] font-bold text-[#075A4D]">
              {initials(user.name)}
            </span>
            <div className="min-w-0">
              <p className="truncate text-[13px] font-semibold text-white">{user.name}</p>
              <p className="truncate text-xs capitalize text-[#9CB3AE]">{user.role}</p>
            </div>
          </div>
        </div>
      )}
    </aside>
  )
}
