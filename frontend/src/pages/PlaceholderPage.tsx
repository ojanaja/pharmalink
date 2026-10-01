import { Construction } from 'lucide-react'

interface PlaceholderPageProps {
  title: string
  description?: string
}

export function PlaceholderPage({ title, description }: PlaceholderPageProps) {
  return (
    <div className="flex flex-col items-center justify-center gap-4 rounded-card border border-dashed border-input-border bg-surface px-6 py-24 text-center">
      <span className="rounded-xl bg-primary-soft p-3 text-primary">
        <Construction size={28} aria-hidden="true" />
      </span>
      <div>
        <h2 className="text-lg font-bold text-ink">{title}</h2>
        <p className="mt-1 text-sm text-ink-secondary">
          {description ?? 'Halaman ini segera hadir pada milestone berikutnya.'}
        </p>
      </div>
    </div>
  )
}
