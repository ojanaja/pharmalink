const TOKEN_KEY = 'pharmalink_token'

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY)
}

export function setToken(token: string | null): void {
  if (token === null) {
    localStorage.removeItem(TOKEN_KEY)
  } else {
    localStorage.setItem(TOKEN_KEY, token)
  }
}

export class ApiError extends Error {
  status: number
  /** Payload `errors` Laravel (mis. daftar item stok tidak cukup saat 422). */
  errors?: unknown

  constructor(status: number, message: string, errors?: unknown) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.errors = errors
  }
}

interface ApiOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  body?: unknown
}

/** Fetch wrapper base `/api` — otentikasi Bearer Sanctum, 401 → kembali ke login. */
export async function api<T>(path: string, options: ApiOptions = {}): Promise<T> {
  const headers: Record<string, string> = {}
  const token = getToken()
  if (token) headers.Authorization = `Bearer ${token}`
  if (options.body !== undefined) headers['Content-Type'] = 'application/json'

  const response = await fetch(`/api${path}`, {
    method: options.method ?? 'GET',
    headers,
    body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
  })

  if (response.status === 401) {
    setToken(null)
    if (!window.location.pathname.startsWith('/login')) {
      window.location.assign('/login')
    }
    throw new ApiError(401, 'Sesi berakhir. Silakan masuk kembali.')
  }

  const data: unknown = await response.json().catch(() => null)
  if (!response.ok) {
    const payload = data as { message?: string; errors?: unknown } | null
    const message = payload?.message ?? 'Terjadi kesalahan pada server.'
    throw new ApiError(response.status, message, payload?.errors)
  }
  return data as T
}
