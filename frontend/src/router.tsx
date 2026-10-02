import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { lazy, Suspense } from 'react'
import type { ReactNode } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AuthProvider } from './auth/AuthProvider'
import { useAuth } from './auth/useAuth'
import { AppShell } from './components/layout/AppShell'
import { CashierPage } from './pages/cashier/CashierPage'
import { LoginPage } from './pages/LoginPage'
import { MasterDataPage } from './pages/master/MasterDataPage'
import { MedicinesPage } from './pages/MedicinesPage'
import { MedicineDetailPage } from './pages/MedicineDetailPage'
import { PlaceholderPage } from './pages/PlaceholderPage'
import { PurchaseOrderDetailPage } from './pages/purchases/PurchaseOrderDetailPage'
import { PurchasesPage } from './pages/purchases/PurchasesPage'
import { ExpiryReport } from './pages/reports/ExpiryReport'
import { ProfitLossReport } from './pages/reports/ProfitLossReport'
import { PurchasesReport } from './pages/reports/PurchasesReport'
import { ReportsIndex, ReportsPage } from './pages/reports/ReportsPage'
import { SalesReport } from './pages/reports/SalesReport'
import { StockReport } from './pages/reports/StockReport'

// Dashboard memuat recharts — dipisah dari chunk utama aplikasi.
const DashboardPage = lazy(() =>
  import('./pages/DashboardPage').then((module) => ({ default: module.DashboardPage })),
)

function PageLoading() {
  return (
    <div className="flex items-center justify-center py-24">
      <div className="size-8 animate-spin rounded-full border-2 border-primary border-t-transparent" />
    </div>
  )
}

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
              <Route
                path="/"
                element={
                  <Suspense fallback={<PageLoading />}>
                    <DashboardPage />
                  </Suspense>
                }
              />
              <Route path="/dashboard" element={<Navigate to="/" replace />} />
              <Route path="/penjualan" element={<CashierPage />} />
              <Route path="/persediaan" element={<MedicinesPage />} />
              <Route path="/persediaan/:id" element={<MedicineDetailPage />} />
              <Route path="/pembelian" element={<PurchasesPage />} />
              <Route path="/pembelian/:id" element={<PurchaseOrderDetailPage />} />
              <Route path="/master-data" element={<MasterDataPage />} />
              <Route element={<ReportsPage />}>
                <Route path="/laporan" element={<ReportsIndex />} />
                <Route path="/laporan/penjualan" element={<SalesReport />} />
                <Route path="/laporan/pembelian" element={<PurchasesReport />} />
                <Route path="/laporan/stok" element={<StockReport />} />
                <Route path="/laporan/kedaluwarsa" element={<ExpiryReport />} />
                <Route path="/laporan/laba-rugi" element={<ProfitLossReport />} />
              </Route>
              <Route path="/pengaturan" element={<PlaceholderPage title="Pengaturan" />} />
            </Route>
            <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </BrowserRouter>
      </AuthProvider>
    </QueryClientProvider>
  )
}
