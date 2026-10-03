import { useCallback, useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { api, onForcedLogout, setToken, USER_KEY } from '../lib/api'
import type { AuthState, AuthUser } from './AuthContext'
import { AuthContext } from './AuthContext'

function readStoredUser(): AuthUser | null {
  try {
    const raw = localStorage.getItem(USER_KEY)
    return raw ? (JSON.parse(raw) as AuthUser) : null
  } catch {
    return null
  }
}

interface LoginResponse {
  token: string
  user: AuthUser
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(readStoredUser)

  const login = useCallback(async (email: string, password: string) => {
    const response = await api<LoginResponse>('/auth/login', {
      method: 'POST',
      body: { email, password },
    })
    setToken(response.token)
    localStorage.setItem(USER_KEY, JSON.stringify(response.user))
    setUser(response.user)
  }, [])

  const logout = useCallback(() => {
    setToken(null)
    localStorage.removeItem(USER_KEY)
    setUser(null)
    window.location.assign('/login')
  }, [])

  // 401 dari api client membersihkan storage + memancar event — state ikut direset agar tidak loop reload.
  useEffect(() => onForcedLogout(() => setUser(null)), [])

  const value = useMemo<AuthState>(() => ({ user, login, logout }), [user, login, logout])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
