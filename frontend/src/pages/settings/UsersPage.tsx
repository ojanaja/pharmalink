import { useQuery, useQueryClient } from '@tanstack/react-query'
import { KeyRound, Pencil, Plus, Power } from 'lucide-react'
import { useState } from 'react'
import { useAuth } from '../../auth/useAuth'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Field, Input, Select } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { SortHeader } from '../../components/ui/SortHeader'
import { ApiError, api } from '../../lib/api'
import { sortRows, useSort } from '../../lib/sort'
import type { AppUser } from '../../lib/types'

const ROLE_OPTIONS = [
  { value: 'apoteker', label: 'Apoteker' },
  { value: 'owner', label: 'Owner' },
]

export function UsersPage() {
  const { user: currentUser } = useAuth()
  const queryClient = useQueryClient()
  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState<AppUser | null>(null)
  const [resetTarget, setResetTarget] = useState<AppUser | null>(null)
  const [toggleTarget, setToggleTarget] = useState<AppUser | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)
  const [roleFilter, setRoleFilter] = useState<'all' | 'owner' | 'apoteker'>('all')
  const [activeFilter, setActiveFilter] = useState<'all' | 'active' | 'inactive'>('all')
  const { sort, toggleSort } = useSort()

  const { data, isPending, isError, refetch } = useQuery({
    queryKey: ['users'],
    queryFn: () => api<{ data: AppUser[] }>('/users'),
  })

  // Sort & filter client-side — daftar user relatif kecil.
  const visible = sortRows(
    (data?.data ?? []).filter(
      (user) =>
        (roleFilter === 'all' || user.role === roleFilter) &&
        (activeFilter === 'all' ||
          (activeFilter === 'active' ? user.is_active : !user.is_active)),
    ),
    sort,
    (user, key) => {
      switch (key) {
        case 'name':
          return user.name
        case 'email':
          return user.email
        case 'role':
          return user.role
        case 'status':
          return user.is_active ? 1 : 0
        default:
          return null
      }
    },
  )

  function invalidate() {
    queryClient.invalidateQueries({ queryKey: ['users'] })
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-center justify-between gap-4">
        <div className="flex items-end gap-3">
          <p className="pb-2 text-sm text-ink-secondary">
            {data?.data.length ?? 0} akun staf · role menentukan akses menu dan tindakan.
          </p>
          <div className="w-40">
            <Select
              value={roleFilter}
              onChange={(e) => setRoleFilter(e.target.value as typeof roleFilter)}
              aria-label="Filter role"
            >
              <option value="all">Semua role</option>
              <option value="owner">Owner</option>
              <option value="apoteker">Apoteker</option>
            </Select>
          </div>
          <div className="w-40">
            <Select
              value={activeFilter}
              onChange={(e) => setActiveFilter(e.target.value as typeof activeFilter)}
              aria-label="Filter status"
            >
              <option value="all">Semua status</option>
              <option value="active">Aktif</option>
              <option value="inactive">Nonaktif</option>
            </Select>
          </div>
        </div>
        <Button
          size="sm"
          icon={<Plus size={14} />}
          onClick={() => {
            setEditing(null)
            setFormOpen(true)
          }}
        >
          Tambah user
        </Button>
      </div>

      {actionError && (
        <p role="alert" className="rounded-lg bg-danger-bg px-3 py-2 text-xs text-danger-ink">
          {actionError}
        </p>
      )}

      <div className="overflow-hidden rounded-card border border-line bg-surface shadow-low">
        {isError ? (
          <div className="flex flex-col items-center gap-3 px-6 py-16 text-center">
            <p className="text-sm text-danger-ink">Gagal memuat daftar user.</p>
            <button
              type="button"
              onClick={() => refetch()}
              className="text-[13px] font-bold text-primary hover:underline"
            >
              Coba lagi
            </button>
          </div>
        ) : (
          <table className="w-full text-left text-sm">
            <thead>
              <tr className="bg-table-header text-[10px] font-bold uppercase tracking-wide text-ink-secondary">
                <SortHeader label="Nama" sortKey="name" sort={sort} onToggle={toggleSort} />
                <SortHeader label="Email" sortKey="email" sort={sort} onToggle={toggleSort} />
                <SortHeader label="Role" sortKey="role" sort={sort} onToggle={toggleSort} />
                <SortHeader label="Status" sortKey="status" sort={sort} onToggle={toggleSort} />
                <th className="px-4 py-3" aria-label="Aksi" />
              </tr>
            </thead>
            <tbody>
              {isPending &&
                Array.from({ length: 4 }, (_, i) => (
                  <tr key={i} className="border-t border-line">
                    {Array.from({ length: 5 }, (_, j) => (
                      <td key={j} className="px-4 py-3">
                        <div className="h-4 animate-pulse rounded bg-neutral-bg" />
                      </td>
                    ))}
                  </tr>
                ))}

              {visible.map((user) => (
                <tr key={user.id} className="border-t border-line">
                  <td className="px-5 py-3 font-semibold text-ink">
                    {user.name}
                    {user.id === currentUser?.id && (
                      <span className="ml-2 text-[11px] font-normal text-ink-secondary">(Anda)</span>
                    )}
                  </td>
                  <td className="px-4 py-3 text-ink-secondary">{user.email}</td>
                  <td className="px-4 py-3">
                    <Badge variant={user.role === 'owner' ? 'info' : 'neutral'}>
                      {user.role === 'owner' ? 'Owner' : 'Apoteker'}
                    </Badge>
                  </td>
                  <td className="px-4 py-3">
                    <Badge variant={user.is_active ? 'success' : 'danger'}>
                      {user.is_active ? 'Aktif' : 'Nonaktif'}
                    </Badge>
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex justify-end gap-2">
                      <button
                        type="button"
                        aria-label={`Edit ${user.name}`}
                        onClick={() => {
                          setEditing(user)
                          setFormOpen(true)
                        }}
                        className="text-ink-secondary transition-colors hover:text-primary"
                      >
                        <Pencil size={15} />
                      </button>
                      <button
                        type="button"
                        aria-label={`Reset password ${user.name}`}
                        onClick={() => setResetTarget(user)}
                        className="text-ink-secondary transition-colors hover:text-primary"
                      >
                        <KeyRound size={15} />
                      </button>
                      <button
                        type="button"
                        aria-label={`${user.is_active ? 'Nonaktifkan' : 'Aktifkan'} ${user.name}`}
                        // Self-protection juga dijaga server (422); tombol sendiri disembunyikan agar jelas.
                        disabled={user.id === currentUser?.id}
                        onClick={() => {
                          setToggleTarget(user)
                          setActionError(null)
                        }}
                        className={`transition-colors disabled:cursor-not-allowed disabled:opacity-30 ${
                          user.is_active
                            ? 'text-ink-secondary hover:text-danger-ink'
                            : 'text-ink-secondary hover:text-success-ink'
                        }`}
                      >
                        <Power size={15} />
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <UserFormModal
        open={formOpen}
        user={editing}
        onClose={() => setFormOpen(false)}
        onSaved={() => {
          setFormOpen(false)
          invalidate()
        }}
      />

      <ResetPasswordModal
        target={resetTarget}
        onClose={() => setResetTarget(null)}
        onSaved={() => {
          setResetTarget(null)
          invalidate()
        }}
      />

      <ToggleActiveModal
        target={toggleTarget}
        onClose={() => setToggleTarget(null)}
        onError={setActionError}
        onSaved={() => {
          setToggleTarget(null)
          invalidate()
        }}
      />
    </div>
  )
}

/* -------------------------------- Form tambah/edit -------------------------------- */

function UserFormModal({
  open,
  user,
  onClose,
  onSaved,
}: {
  open: boolean
  user: AppUser | null
  onClose: () => void
  onSaved: () => void
}) {
  const isEdit = user !== null
  const [form, setForm] = useState({ name: '', email: '', password: '', role: 'apoteker' })
  const [initialized, setInitialized] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  if (open && !initialized) {
    setForm({
      name: user?.name ?? '',
      email: user?.email ?? '',
      password: '',
      role: user?.role ?? 'apoteker',
    })
    setInitialized(true)
    setError(null)
  }
  if (!open && initialized) {
    setInitialized(false)
  }

  async function handleSubmit() {
    setSubmitting(true)
    setError(null)
    try {
      if (isEdit) {
        await api(`/users/${user.id}`, {
          method: 'PUT',
          body: { name: form.name.trim(), email: form.email.trim(), role: form.role },
        })
      } else {
        await api('/users', {
          method: 'POST',
          body: {
            name: form.name.trim(),
            email: form.email.trim(),
            password: form.password,
            role: form.role,
          },
        })
      }
      setInitialized(false)
      onSaved()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Gagal menyimpan user. Coba lagi.')
    } finally {
      setSubmitting(false)
    }
  }

  const passwordInvalid = !isEdit && form.password !== '' && form.password.length < 8
  const canSubmit =
    form.name.trim() !== '' &&
    form.email.trim() !== '' &&
    (isEdit || form.password.length >= 8) &&
    !submitting

  return (
    <Modal
      open={open}
      title={isEdit ? `Edit User — ${user.name}` : 'Tambah User'}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button loading={submitting} disabled={!canSubmit} onClick={handleSubmit}>
            {isEdit ? 'Simpan perubahan' : 'Buat user'}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <Field label="Nama lengkap *">
          <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
        </Field>
        <Field label="Email *">
          <Input
            type="email"
            value={form.email}
            onChange={(e) => setForm({ ...form, email: e.target.value })}
          />
        </Field>
        {!isEdit && (
          <Field
            label="Password awal * (minimal 8 karakter)"
            error={passwordInvalid ? 'Password minimal 8 karakter.' : undefined}
          >
            <Input
              type="password"
              value={form.password}
              onChange={(e) => setForm({ ...form, password: e.target.value })}
              invalid={passwordInvalid}
            />
          </Field>
        )}
        <Field label="Role *">
          <Select value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })}>
            {ROLE_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </Select>
        </Field>
        <p className="rounded-lg bg-table-header px-3 py-2 text-[11px] text-ink-secondary">
          {form.role === 'owner'
            ? 'Owner memiliki akses penuh termasuk pengaturan, kelola user, void transaksi, dan master data.'
            : 'Apoteker dapat mengelola transaksi, persediaan, dan pembelian; tidak dapat mengubah master data atau pengaturan.'}
        </p>
        {error && (
          <div role="alert" className="rounded-lg bg-danger-bg px-3 py-2 text-xs text-danger-ink">
            {error}
          </div>
        )}
      </div>
    </Modal>
  )
}

/* ------------------------------- Reset password ------------------------------- */

function ResetPasswordModal({
  target,
  onClose,
  onSaved,
}: {
  target: AppUser | null
  onClose: () => void
  onSaved: () => void
}) {
  const [password, setPassword] = useState('')
  const [initialized, setInitialized] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  if (target && !initialized) {
    setPassword('')
    setError(null)
    setInitialized(true)
  }
  if (!target && initialized) {
    setInitialized(false)
  }

  async function handleSubmit() {
    if (!target) return
    setSubmitting(true)
    setError(null)
    try {
      await api(`/users/${target.id}/reset-password`, {
        method: 'POST',
        body: { password },
      })
      onSaved()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Reset password gagal. Coba lagi.')
    } finally {
      setSubmitting(false)
    }
  }

  const invalid = password !== '' && password.length < 8

  return (
    <Modal
      open={target !== null}
      title={`Reset password ${target?.name ?? ''}?`}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button loading={submitting} disabled={password.length < 8} onClick={handleSubmit}>
            Reset password
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <p>
          Password lama {target?.name ?? ''} tidak dapat digunakan lagi. Semua sesi aktif user
          tersebut akan dicabut.
        </p>
        <Field
          label="Password baru * (minimal 8 karakter)"
          error={invalid ? 'Password minimal 8 karakter.' : undefined}
        >
          <Input
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            invalid={invalid}
          />
        </Field>
        {error && (
          <div role="alert" className="rounded-lg bg-danger-bg px-3 py-2 text-xs text-danger-ink">
            {error}
          </div>
        )}
      </div>
    </Modal>
  )
}

/* ------------------------------ Toggle aktif/nonaktif ------------------------------ */

function ToggleActiveModal({
  target,
  onClose,
  onSaved,
  onError,
}: {
  target: AppUser | null
  onClose: () => void
  onSaved: () => void
  onError: (message: string) => void
}) {
  const [submitting, setSubmitting] = useState(false)

  async function handleSubmit() {
    if (!target) return
    setSubmitting(true)
    try {
      await api(`/users/${target.id}/toggle-active`, { method: 'POST', body: {} })
      onSaved()
    } catch (err) {
      // 422: menonaktifkan diri sendiri / owner aktif terakhir.
      onError(err instanceof ApiError ? err.message : 'Gagal mengubah status user.')
      onClose()
    } finally {
      setSubmitting(false)
    }
  }

  const deactivating = target?.is_active ?? false

  return (
    <Modal
      open={target !== null}
      title={deactivating ? `Nonaktifkan user ${target?.name ?? ''}?` : `Aktifkan user ${target?.name ?? ''}?`}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button
            variant={deactivating ? 'danger' : 'primary'}
            loading={submitting}
            onClick={handleSubmit}
          >
            {deactivating ? 'Ya, nonaktifkan' : 'Ya, aktifkan'}
          </Button>
        </>
      }
    >
      <p>
        {deactivating
          ? `${target?.name ?? ''} akan langsung kehilangan akses ke aplikasi dan semua sesinya dicabut. Riwayat transaksi tetap tersimpan.`
          : `${target?.name ?? ''} dapat masuk kembali ke aplikasi dengan kredensial yang sama.`}
      </p>
    </Modal>
  )
}

/* --------------------------------- Matriks akses --------------------------------- */

/** Informatif statis — belum ada backend matriks; selaras Figma #45:8160 (read-only). */
const MATRIX_ROWS: Array<{ menu: string; owner: boolean; apoteker: boolean }> = [
  { menu: 'Dashboard', owner: true, apoteker: true },
  { menu: 'Kasir (Penjualan)', owner: true, apoteker: true },
  { menu: 'Riwayat, retur & void transaksi', owner: true, apoteker: true },
  { menu: 'Persediaan & detail obat', owner: true, apoteker: true },
  { menu: 'Stock opname & koreksi stok', owner: true, apoteker: true },
  { menu: 'Pembelian & penerimaan', owner: true, apoteker: true },
  { menu: 'Master data (lihat)', owner: true, apoteker: true },
  { menu: 'Master data (tambah/ubah/hapus)', owner: true, apoteker: false },
  { menu: 'Laporan', owner: true, apoteker: true },
  { menu: 'Pengaturan', owner: true, apoteker: false },
  { menu: 'User & hak akses', owner: true, apoteker: false },
]

export function AccessMatrixTab() {
  return (
    <div className="overflow-hidden rounded-card border border-line bg-surface">
      <div className="border-b border-line px-5 py-4">
        <h3 className="text-[15px] font-bold text-ink">Matriks Akses Menu</h3>
        <p className="mt-0.5 text-xs text-ink-secondary">
          Ringkasan menu yang dapat diakses setiap role. Tanda centang berarti menu dapat dibuka.
        </p>
      </div>
      <table className="w-full text-left text-sm">
        <thead>
          <tr className="bg-table-header text-[10px] font-bold uppercase tracking-wide text-ink-secondary">
            <th className="px-5 py-3">Menu</th>
            <th className="px-4 py-3 text-center">Owner</th>
            <th className="px-4 py-3 text-center">Apoteker</th>
          </tr>
        </thead>
        <tbody>
          {MATRIX_ROWS.map((row) => (
            <tr key={row.menu} className="border-t border-line">
              <td className="px-5 py-3 text-[13px] text-ink">{row.menu}</td>
              <td className="px-4 py-3 text-center text-success-ink">{row.owner ? '✓' : '−'}</td>
              <td className="px-4 py-3 text-center text-success-ink">
                {row.apoteker ? '✓' : <span className="text-placeholder">−</span>}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      <p className="border-t border-line px-5 py-3 text-[11px] text-ink-secondary">
        Matriks ini bersifat informatif — perubahan role dilakukan dari halaman User & Hak Akses.
      </p>
    </div>
  )
}
