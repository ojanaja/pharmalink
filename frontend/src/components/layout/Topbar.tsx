interface TopbarProps {
  breadcrumb: string
  title: string
}

export function Topbar({ breadcrumb, title }: TopbarProps) {
  return (
    <header className="flex h-[72px] shrink-0 items-center justify-between border-b border-line bg-surface px-7">
      <div>
        <p className="text-xs text-ink-secondary">{breadcrumb}</p>
        <h1 className="text-lg font-bold text-ink">{title}</h1>
      </div>
    </header>
  )
}
