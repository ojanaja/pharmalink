import { Outlet, useLocation } from 'react-router-dom'
import { NAV_ITEMS } from './nav'
import { Sidebar } from './Sidebar'
import { Topbar } from './Topbar'

export function AppShell() {
  const location = useLocation()
  const current = NAV_ITEMS.find(
    (item) => item.path === '/' || location.pathname.startsWith(item.path),
  )

  return (
    <div className="flex min-h-screen bg-app">
      <Sidebar />
      <div className="flex min-w-0 flex-1 flex-col">
        <Topbar breadcrumb={current?.label ?? 'Dashboard'} title={current?.label ?? 'Dashboard'} />
        <main className="flex-1 p-7">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
