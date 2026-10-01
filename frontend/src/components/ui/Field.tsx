import { ChevronDown, CircleAlert } from 'lucide-react'
import type { InputHTMLAttributes, ReactNode, SelectHTMLAttributes } from 'react'

interface FieldProps {
  label: string
  error?: string
  children: ReactNode
}

/** Label persisten di atas kontrol (pola DS 03). */
export function Field({ label, error, children }: FieldProps) {
  return (
    <div className="flex flex-col gap-1.5">
      <label className="text-[13px] font-semibold text-ink">{label}</label>
      {children}
      {error && (
        <p className="flex items-center gap-1 text-xs text-danger-ink">
          <CircleAlert size={13} aria-hidden="true" />
          {error}
        </p>
      )}
    </div>
  )
}

const INPUT_BASE =
  'h-10 w-full rounded-lg border bg-surface px-3 text-sm text-ink outline-none transition-colors placeholder:text-placeholder disabled:bg-neutral-bg disabled:opacity-60'

interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
  invalid?: boolean
}

export function Input({ invalid = false, className = '', ...rest }: InputProps) {
  const border = invalid ? 'border-danger-ink' : 'border-input-border focus:border-primary'
  return <input className={`${INPUT_BASE} ${border} ${className}`} {...rest} />
}

interface SelectProps extends SelectHTMLAttributes<HTMLSelectElement> {
  invalid?: boolean
}

export function Select({ invalid = false, className = '', children, ...rest }: SelectProps) {
  const border = invalid ? 'border-danger-ink' : 'border-input-border focus:border-primary'
  return (
    <div className="relative">
      <select
        className={`${INPUT_BASE} ${border} appearance-none pr-9 ${className}`}
        {...rest}
      >
        {children}
      </select>
      <ChevronDown
        size={16}
        className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-ink-secondary"
        aria-hidden="true"
      />
    </div>
  )
}
