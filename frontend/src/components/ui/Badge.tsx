import type { ReactNode } from 'react'

export type BadgeVariant = 'success' | 'warning' | 'danger' | 'info' | 'neutral'

const VARIANT_CLASSES: Record<BadgeVariant, string> = {
  success: 'bg-success-bg text-success-ink',
  warning: 'bg-warning-bg text-warning-ink',
  danger: 'bg-danger-bg text-danger-ink',
  info: 'bg-info-bg text-info-ink',
  neutral: 'bg-neutral-bg text-neutral-ink',
}

interface BadgeProps {
  variant?: BadgeVariant
  children: ReactNode
}

/** Pill status — selalu berlabel teks, bukan warna saja (aturan DS 04.3). */
export function Badge({ variant = 'neutral', children }: BadgeProps) {
  return (
    <span
      className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-[5px] text-[11px] font-bold ${VARIANT_CLASSES[variant]}`}
    >
      <span className={`size-2 rounded-full bg-current`} aria-hidden="true" />
      {children}
    </span>
  )
}
