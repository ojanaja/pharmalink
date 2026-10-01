import { createContext } from 'react'

export interface AuthUser {
  id: number
  name: string
  email: string
  role: string
}

export interface AuthState {
  user: AuthUser | null
  login: (email: string, password: string) => Promise<void>
  logout: () => void
}

export const AuthContext = createContext<AuthState | null>(null)
