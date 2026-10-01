import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AuthProvider } from './auth/AuthProvider'
import { useAuth } from './auth/useAuth'
import { AppShell } from './components/layout/AppShell'
import { LoginPage } from './pages/LoginPage'
import { MedicinesPage } from './pages/MedicinesPage'
import { PlaceholderPage } from './pages/PlaceholderPage'

const queryClient = new QueryClient()

function RequireAuth({ children }: { children: ReactNode }) {
  const { user } = useAuth()
  if (!user) return <Navigate to="/login" replace />
  return <>{children}</>
}

function LoginRedirect() {
  const { user } = useAuth()
  if (user) return <Navigate to="/" replace />
  return <LoginPage />
}

export function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <BrowserRouter>
          <Routes>
            <Route path="/login" element={<LoginRedirect />} />
            <Route
              element={
                <RequireAuth>
                  <AppShell />
                </RequireAuth>
              }
            >
              <Route path="/" element={<PlaceholderPage title="Dashboard" />} />
              <Route path="/penjualan" element={<PlaceholderPage title="Penjualan" />} />
              <Route path="/persediaan" element={<MedicinesPage />} />
              <Route path="/pembelian" element={<PlaceholderPage title="Pembelian" />} />
              <Route path="/master-data" element={<PlaceholderPage title="Master Data" />} />
              <Route path="/laporan" element={<PlaceholderPage title="Laporan" />} />
              <Route path="/pengaturan" element={<PlaceholderPage title="Pengaturan" />} />
            </Route>
            <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </BrowserRouter>
      </AuthProvider>
    </QueryClientProvider>
  )
}
