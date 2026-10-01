import { Bell, ChevronDown } from 'lucide-react'
import { useAuth } from '../../auth/useAuth'

interface TopbarProps {
  breadcrumb: string
  title: string
}

export function Topbar({ breadcrumb, title }: TopbarProps) {
  const { user } = useAuth()

  return (
    <header className="flex h-[72px] shrink-0 items-center justify-between border-b border-line bg-surface px-7">
      <div>
        <p className="text-xs text-ink-secondary">{breadcrumb}</p>
        <h1 className="text-lg font-bold text-ink">{title}</h1>
      </div>
      <div className="flex items-center gap-4">
        <button
          type="button"
          aria-label="Notifikasi"
          className="relative text-ink-secondary transition-colors hover:text-ink"
        >
          <Bell size={20} />
          <span
            className="absolute -right-0.5 -top-0.5 size-2 rounded-full bg-danger-ink"
            aria-hidden="true"
          />
        </button>
        {user && (
          <div className="flex items-center gap-2">
            <span className="flex size-9 items-center justify-center rounded-full bg-primary-soft text-[13px] font-bold text-primary">
              {user.name
                .split(' ')
                .filter(Boolean)
                .slice(0, 2)
                .map((part) => part[0]?.toUpperCase())
                .join('')}
            </span>
            <ChevronDown size={16} className="text-ink-secondary" aria-hidden="true" />
          </div>
        )}
      </div>
    </header>
  )
}
