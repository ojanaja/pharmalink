import { Cross } from 'lucide-react'
import { useState } from 'react'
import type { FormEvent } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth/useAuth'
import { Button } from '../components/ui/Button'
import { Field, Input } from '../components/ui/Field'
import { ApiError } from '../lib/api'

export function LoginPage() {
  const { login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setLoading(true)
    try {
      await login(email, password)
      const redirect = (location.state as { from?: string } | null)?.from ?? '/'
      navigate(redirect, { replace: true })
    } catch (err) {
      setError(
        err instanceof ApiError && err.status === 401
          ? 'Email atau password salah.'
          : 'Gagal masuk. Periksa koneksi dan coba lagi.',
      )
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-app p-4">
      <div className="w-full max-w-[400px] rounded-card border border-line bg-surface p-8 shadow-medium">
        <div className="mb-6 flex flex-col items-center gap-3">
          <span className="flex size-12 items-center justify-center rounded-xl bg-primary">
            <Cross size={24} className="text-white" aria-hidden="true" />
          </span>
          <h1 className="text-xl font-bold text-ink">Pharmalink</h1>
          <p className="text-center text-[13px] text-ink-secondary">
            Masuk untuk mengelola operasional apotek
          </p>
        </div>

        {error && (
          <div
            role="alert"
            className="mb-4 rounded-lg border border-danger-ink bg-danger-bg px-3 py-2 text-[13px] text-danger-ink"
          >
            {error}
          </div>
        )}

        <form onSubmit={handleSubmit} className="flex flex-col gap-4">
          <Field label="Email">
            <Input
              type="email"
              autoComplete="email"
              placeholder="nama@apotek.id"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              required
            />
          </Field>
          <Field label="Password">
            <Input
              type="password"
              autoComplete="current-password"
              placeholder="••••••••"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              required
            />
          </Field>
          <Button type="submit" size="lg" loading={loading} className="mt-2 w-full">
            Masuk
          </Button>
        </form>
      </div>
    </div>
  )
}
