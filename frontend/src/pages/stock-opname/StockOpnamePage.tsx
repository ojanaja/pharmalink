import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Play } from 'lucide-react'
import { useMemo, useState } from 'react'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Input } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { Pagination } from '../../components/ui/Pagination'
import { ApiError, api } from '../../lib/api'
import { opnameStatusLabel } from '../../lib/medicine'
import type { LaravelPaginated, OpnameItem, StockOpname } from '../../lib/types'

type WizardStep = 'hitung' | 'konfirmasi'

interface CountDraft {
  physical: number
  reason: string
}

export function StockOpnamePage() {
  const queryClient = useQueryClient()
  const [active, setActive] = useState<StockOpname | null>(null)
  const [step, setStep] = useState<WizardStep>('hitung')
  const [drafts, setDrafts] = useState<Map<number, CountDraft>>(new Map())
  const [touched, setTouched] = useState<Set<number>>(new Set())
  const [page, setPage] = useState(1)
  const [detailId, setDetailId] = useState<number | null>(null)

  const [starting, setStarting] = useState(false)
  const [saving, setSaving] = useState(false)
  const [confirmOpen, setConfirmOpen] = useState(false)
  const [confirming, setConfirming] = useState(false)
  const [confirmError, setConfirmError] = useState<string | null>(null)
  const [done, setDone] = useState<StockOpname | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)

  const history = useQuery({
    queryKey: ['stock-opnames', page],
    queryFn: () => api<LaravelPaginated<StockOpname>>(`/stock-opnames?page=${page}`),
  })

  const draftOf = (item: OpnameItem): CountDraft =>
    drafts.get(item.id) ?? { physical: item.physical_qty, reason: item.reason ?? '' }

  const diffOf = (item: OpnameItem): number => draftOf(item).physical - item.system_qty

  const items = useMemo(() => active?.items ?? [], [active])
  // Tanpa useMemo: daftar item opname kecil (satuan puluhan), filter langsung cukup.
  const diffItems = items.filter((item) => diffOf(item) !== 0)

  // Alasan wajib untuk setiap selisih — aturan operasional, server tidak memvalidasinya.
  const missingReasons = diffItems.filter((item) => draftOf(item).reason.trim().length < 3)

  const changedItems = items.filter((item) => {
    const draft = draftOf(item)
    return (
      draft.physical !== item.physical_qty ||
      (draft.reason.trim() !== (item.reason ?? '') && draft.reason.trim() !== '')
    )
  })

  async function startSession() {
    setStarting(true)
    setActionError(null)
    try {
      const response = await api<{ data: StockOpname }>('/stock-opnames', {
        method: 'POST',
        body: { note: null },
      })
      setActive(response.data)
      setDrafts(new Map())
      setTouched(new Set())
      setStep('hitung')
      setDone(null)
    } catch (err) {
      setActionError(err instanceof ApiError ? err.message : 'Gagal membuat sesi opname.')
    } finally {
      setStarting(false)
    }
  }

  function updateDraft(itemId: number, patch: Partial<CountDraft>) {
    const item = items.find((entry) => entry.id === itemId)
    if (!item) return
    setDrafts((prev) => {
      const next = new Map(prev)
      next.set(itemId, { ...draftOf(item), ...patch })
      return next
    })
    setTouched((prev) => new Set(prev).add(itemId))
  }

  async function saveCounts() {
    if (!active) return
    setSaving(true)
    setActionError(null)
    try {
      const payload = changedItems.map((item) => {
        const draft = draftOf(item)
        return {
          opname_item_id: item.id,
          physical_qty: draft.physical,
          reason: draft.reason.trim() === '' ? null : draft.reason.trim(),
        }
      })
      const response = await api<{ data: StockOpname }>(`/stock-opnames/${active.id}/items`, {
        method: 'PUT',
        body: { counts: payload },
      })
      setActive(response.data)
      setDrafts(new Map())
    } catch (err) {
      setActionError(err instanceof ApiError ? err.message : 'Gagal menyimpan hasil hitung.')
    } finally {
      setSaving(false)
    }
  }

  async function confirmOpname() {
    if (!active) return
    setConfirming(true)
    setConfirmError(null)
    try {
      const response = await api<{ data: StockOpname }>(`/stock-opnames/${active.id}/confirm`, {
        method: 'POST',
        body: {},
      })
      setDone(response.data)
      setActive(null)
      setConfirmOpen(false)
      setDrafts(new Map())
      setTouched(new Set())
      queryClient.invalidateQueries({ queryKey: ['stock-opnames'] })
    } catch (err) {
      // Backend memakai 422 (bukan 409) saat opname sudah dikonfirmasi.
      setConfirmError(
        err instanceof ApiError ? err.message : 'Konfirmasi gagal. Periksa koneksi dan coba lagi.',
      )
    } finally {
      setConfirming(false)
    }
  }

  /* ------------------------------ Tampilan selesai ------------------------------ */
  if (done) {
    return (
      <div className="flex flex-col items-center gap-4 rounded-card border border-line bg-surface px-6 py-16 text-center">
        <span className="flex size-16 items-center justify-center rounded-full bg-success-bg">
          <Check size={32} className="text-success-ink" aria-hidden="true" />
        </span>
        <div>
          <p className="text-2xl font-bold text-ink">Stock Opname Selesai</p>
          <p className="mt-1 text-[13px] font-semibold text-primary">{done.opname_number}</p>
          <p className="mt-1 text-[13px] text-ink-secondary">
            {done.summary.adjusted_items} dari {done.summary.total_items} item memiliki selisih (
            {done.summary.increased_items} bertambah, {done.summary.decreased_items} berkurang).
            Mutasi penyesuaian stok telah dibuat.
          </p>
        </div>
        <Button onClick={() => setDone(null)}>Kembali</Button>
      </div>
    )
  }

  /* ------------------------------ Wizard sesi aktif ------------------------------ */
  if (active) {
    return (
      <div className="flex flex-col gap-5">
        <div className="flex items-center justify-between gap-4">
          <div>
            <div className="flex items-center gap-3">
              <h2 className="text-2xl font-bold text-ink">{active.opname_number}</h2>
              <Badge variant="warning">Draft</Badge>
            </div>
            <p className="mt-1 text-sm text-ink-secondary">
              {touched.size} / {active.summary.total_items} item dihitung ·{' '}
              {diffItems.length} item selisih
            </p>
          </div>
          <div className="flex gap-2">
            <button
              type="button"
              onClick={() => setStep('hitung')}
              className={`rounded-full border px-4 py-2 text-[13px] font-bold transition-colors ${
                step === 'hitung'
                  ? 'border-primary bg-primary text-white'
                  : 'border-line bg-surface text-ink-secondary'
              }`}
            >
              1 · Hitung
            </button>
            <button
              type="button"
              onClick={() => setStep('konfirmasi')}
              disabled={missingReasons.length > 0}
              className={`rounded-full border px-4 py-2 text-[13px] font-bold transition-colors disabled:cursor-not-allowed disabled:opacity-50 ${
                step === 'konfirmasi'
                  ? 'border-primary bg-primary text-white'
                  : 'border-line bg-surface text-ink-secondary'
              }`}
            >
              2 · Konfirmasi
            </button>
          </div>
        </div>

        {step === 'hitung' ? (
          <>
            <div className="overflow-hidden rounded-card border border-line bg-surface">
              <table className="w-full text-left text-sm">
                <thead>
                  <tr className="bg-table-header text-[10px] font-bold uppercase tracking-wide text-ink-secondary">
                    <th className="px-5 py-3">Obat</th>
                    <th className="px-4 py-3">Batch</th>
                    <th className="px-4 py-3 text-right">Stok Sistem</th>
                    <th className="px-4 py-3 text-right">Stok Fisik</th>
                    <th className="px-4 py-3 text-right">Selisih</th>
                    <th className="px-4 py-3">Alasan (wajib bila selisih)</th>
                  </tr>
                </thead>
                <tbody>
                  {items.map((item) => {
                    const draft = draftOf(item)
                    const diff = diffOf(item)
                    return (
                      <tr key={item.id} className="border-t border-line">
                        <td className="px-5 py-2.5">
                          <p className="text-[13px] font-semibold text-ink">{item.medicine.name}</p>
                          <p className="text-[11px] text-ink-secondary">{item.medicine.code}</p>
                        </td>
                        <td className="px-4 py-2.5 text-[13px] text-ink-secondary">
                          {item.batch.batch_number}
                        </td>
                        <td className="px-4 py-2.5 text-right text-[13px] text-ink-secondary">
                          {item.system_qty}
                        </td>
                        <td className="px-4 py-2.5 text-right">
                          <Input
                            type="number"
                            min={0}
                            value={draft.physical}
                            onChange={(event) =>
                              updateDraft(item.id, { physical: Number(event.target.value) })
                            }
                            className="ml-auto h-8 w-24 text-right"
                            aria-label={`Stok fisik ${item.medicine.name} ${item.batch.batch_number}`}
                          />
                        </td>
                        <td
                          className={`px-4 py-2.5 text-right text-[13px] font-bold ${
                            diff === 0
                              ? 'text-ink-secondary'
                              : diff > 0
                                ? 'text-success-ink'
                                : 'text-danger-ink'
                          }`}
                        >
                          {diff === 0 ? '0' : `${diff > 0 ? '+' : '−'}${Math.abs(diff)}`}
                        </td>
                        <td className="px-4 py-2.5">
                          {diff !== 0 ? (
                            <Input
                              value={draft.reason}
                              onChange={(event) =>
                                updateDraft(item.id, { reason: event.target.value })
                              }
                              placeholder="Wajib diisi…"
                              invalid={draft.reason.trim().length < 3}
                              className="h-8"
                              aria-label={`Alasan selisih ${item.medicine.name}`}
                            />
                          ) : (
                            <span className="text-[11px] text-placeholder">—</span>
                          )}
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
              <div className="flex items-center justify-between border-t border-line px-5 py-3">
                <span className="text-xs text-ink-secondary">
                  {missingReasons.length > 0
                    ? `${missingReasons.length} selisih belum ada alasan.`
                    : `${diffItems.length} item selisih · semua sudah beralasan`}
                </span>
                <div className="flex gap-2">
                  <Button
                    variant="secondary"
                    loading={saving}
                    disabled={changedItems.length === 0}
                    onClick={saveCounts}
                  >
                    Simpan perubahan
                  </Button>
                  <Button
                    disabled={missingReasons.length > 0 || diffItems.length !== diffItems.length}
                    onClick={() => {
                      // Lanjut ke konfirmasi hanya setelah perubahan tersimpan.
                      if (changedItems.length > 0) {
                        setActionError('Simpan perubahan hitungan terlebih dahulu.')
                        return
                      }
                      setStep('konfirmasi')
                    }}
                  >
                    Lanjut Konfirmasi
                  </Button>
                </div>
              </div>
            </div>
            {actionError && (
              <p role="alert" className="rounded-lg bg-danger-bg px-3 py-2 text-xs text-danger-ink">
                {actionError}
              </p>
            )}
          </>
        ) : (
          <div className="overflow-hidden rounded-card border border-line bg-surface">
            <div className="border-b border-line px-5 py-4">
              <h3 className="text-[15px] font-bold text-ink">Ringkasan Selisih</h3>
              <p className="mt-0.5 text-xs text-ink-secondary">
                Hanya item dengan selisih ≠ 0 ditampilkan. Konfirmasi mengunci hasil dan membuat
                mutasi penyesuaian stok per batch.
              </p>
            </div>
            {diffItems.length === 0 ? (
              <p className="px-6 py-12 text-center text-sm text-ink-secondary">
                Tidak ada selisih — semua stok fisik cocok dengan sistem.
              </p>
            ) : (
              <table className="w-full text-left text-sm">
                <thead>
                  <tr className="bg-table-header text-[10px] font-bold uppercase tracking-wide text-ink-secondary">
                    <th className="px-5 py-3">Obat</th>
                    <th className="px-4 py-3">Batch</th>
                    <th className="px-4 py-3 text-right">Sistem</th>
                    <th className="px-4 py-3 text-right">Fisik</th>
                    <th className="px-4 py-3 text-right">Selisih</th>
                    <th className="px-4 py-3">Alasan</th>
                  </tr>
                </thead>
                <tbody>
                  {diffItems.map((item) => {
                    const diff = diffOf(item)
                    return (
                      <tr key={item.id} className="border-t border-line">
                        <td className="px-5 py-2.5">
                          <p className="text-[13px] font-semibold text-ink">{item.medicine.name}</p>
                          <p className="text-[11px] text-ink-secondary">{item.medicine.code}</p>
                        </td>
                        <td className="px-4 py-2.5 text-[13px] text-ink-secondary">
                          {item.batch.batch_number}
                        </td>
                        <td className="px-4 py-2.5 text-right text-[13px] text-ink-secondary">
                          {item.system_qty}
                        </td>
                        <td className="px-4 py-2.5 text-right text-[13px] text-ink">
                          {draftOf(item).physical}
                        </td>
                        <td
                          className={`px-4 py-2.5 text-right text-[13px] font-bold ${
                            diff > 0 ? 'text-success-ink' : 'text-danger-ink'
                          }`}
                        >
                          {diff > 0 ? `+${diff}` : `−${Math.abs(diff)}`}
                        </td>
                        <td className="px-4 py-2.5 text-[13px] text-ink">{draftOf(item).reason}</td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            )}
            <div className="flex items-center justify-end gap-3 border-t border-line px-5 py-3">
              <Button variant="secondary" onClick={() => setStep('hitung')}>
                Kembali Periksa
              </Button>
              <Button onClick={() => setConfirmOpen(true)}>Selesaikan Opname</Button>
            </div>
          </div>
        )}

        {/* Modal konfirmasi — pola DS 05.2 / Figma #44:4908 */}
        <Modal
          open={confirmOpen}
          title="Selesaikan Stock Opname?"
          onClose={() => setConfirmOpen(false)}
          footer={
            <>
              <Button variant="secondary" onClick={() => setConfirmOpen(false)}>
                Kembali Periksa
              </Button>
              <Button loading={confirming} onClick={confirmOpname}>
                Ya, Selesaikan Opname
              </Button>
            </>
          }
        >
          <p>
            Tindakan ini mengunci hasil hitung dan membuat mutasi penyesuaian stok per batch. Data
            yang sudah selesai tidak dapat diedit.
          </p>
          <div className="mt-4 grid grid-cols-3 gap-2 text-center">
            <div className="rounded-lg bg-table-header p-3">
              <p className="text-lg font-bold text-ink">{active.summary.total_items}</p>
              <p className="text-[10px] font-bold uppercase text-ink-secondary">Item dihitung</p>
            </div>
            <div className="rounded-lg bg-warning-bg p-3">
              <p className="text-lg font-bold text-warning-ink">{diffItems.length}</p>
              <p className="text-[10px] font-bold uppercase text-warning-ink">Dengan selisih</p>
            </div>
            <div className="rounded-lg bg-success-bg p-3">
              <p className="text-lg font-bold text-success-ink">
                {active.summary.total_items - diffItems.length}
              </p>
              <p className="text-[10px] font-bold uppercase text-success-ink">Tanpa selisih</p>
            </div>
          </div>
          {confirmError && (
            <p role="alert" className="mt-3 rounded-lg bg-danger-bg px-3 py-2 text-xs text-danger-ink">
              {confirmError}
            </p>
          )}
        </Modal>
      </div>
    )
  }

  /* ------------------------------ Halaman awal + riwayat ------------------------------ */
  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-end justify-between gap-4">
        <div>
          <h2 className="text-2xl font-bold text-ink">Stock Opname</h2>
          <p className="mt-1 text-sm text-ink-secondary">
            Bandingkan stok fisik dengan stok sistem dan telusuri setiap penyesuaian per batch.
          </p>
        </div>
        <Button icon={<Play size={15} />} loading={starting} onClick={startSession}>
          Mulai Stock Opname
        </Button>
      </div>

      {actionError && (
        <p role="alert" className="rounded-lg bg-danger-bg px-3 py-2 text-xs text-danger-ink">
          {actionError}
        </p>
      )}

      <div className="overflow-hidden rounded-card border border-line bg-surface">
        <div className="border-b border-line px-5 py-4">
          <h3 className="text-[15px] font-bold text-ink">Sesi Stock Opname</h3>
          <p className="mt-0.5 text-xs text-ink-secondary">
            {history.data?.meta.total ?? 0} sesi tercatat.
          </p>
        </div>
        {history.isError ? (
          <div className="flex flex-col items-center gap-3 px-6 py-12 text-center">
            <p className="text-sm text-danger-ink">Gagal memuat riwayat opname.</p>
            <button
              type="button"
              onClick={() => history.refetch()}
              className="text-[13px] font-bold text-primary hover:underline"
            >
              Coba lagi
            </button>
          </div>
        ) : (
          <table className="w-full text-left text-sm">
            <thead>
              <tr className="bg-table-header text-[10px] font-bold uppercase tracking-wide text-ink-secondary">
                <th className="px-5 py-3">Nomor</th>
                <th className="px-4 py-3">Tanggal</th>
                <th className="px-4 py-3">Petugas</th>
                <th className="px-4 py-3 text-right">Item</th>
                <th className="px-4 py-3 text-right">Selisih</th>
                <th className="px-4 py-3">Status</th>
                <th className="px-4 py-3" aria-label="Aksi" />
              </tr>
            </thead>
            <tbody>
              {history.isPending &&
                Array.from({ length: 4 }, (_, i) => (
                  <tr key={i} className="border-t border-line">
                    {Array.from({ length: 7 }, (_, j) => (
                      <td key={j} className="px-4 py-3">
                        <div className="h-4 animate-pulse rounded bg-neutral-bg" />
                      </td>
                    ))}
                  </tr>
                ))}
              {history.data?.data.map((opname) => {
                const status = opnameStatusLabel(opname.status)
                return (
                  <tr key={opname.id} className="border-t border-line">
                    <td className="px-5 py-3 font-semibold text-ink">{opname.opname_number}</td>
                    <td className="px-4 py-3 text-ink-secondary">{opname.opname_at}</td>
                    <td className="px-4 py-3 text-ink-secondary">{opname.user?.name ?? '—'}</td>
                    <td className="px-4 py-3 text-right text-ink-secondary">
                      {opname.summary.total_items}
                    </td>
                    <td className="px-4 py-3 text-right font-semibold text-ink">
                      {opname.summary.adjusted_items}
                    </td>
                    <td className="px-4 py-3">
                      <Badge variant={status.variant}>{status.label}</Badge>
                    </td>
                    <td className="px-4 py-3 text-right">
                      <button
                        type="button"
                        onClick={() => setDetailId(opname.id)}
                        className="text-[13px] font-bold text-primary hover:underline"
                      >
                        Lihat
                      </button>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        )}
        {history.data && (
          <Pagination
            currentPage={history.data.meta.current_page}
            lastPage={history.data.meta.last_page}
            total={history.data.meta.total}
            from={history.data.meta.from ?? 0}
            to={history.data.meta.to ?? 0}
            onPageChange={setPage}
          />
        )}
      </div>

      <OpnameDetailModal opnameId={detailId} onClose={() => setDetailId(null)} />
    </div>
  )
}

/** Detail baca-saja — dipakai untuk riwayat sesi terkonfirmasi. */
function OpnameDetailModal({ opnameId, onClose }: { opnameId: number | null; onClose: () => void }) {
  const { data, isPending } = useQuery({
    queryKey: ['stock-opname', opnameId],
    queryFn: () => api<{ data: StockOpname }>(`/stock-opnames/${opnameId}`),
    enabled: opnameId !== null,
  })
  const opname = data?.data
  const diffs = (opname?.items ?? []).filter((item) => item.difference !== 0)

  return (
    <Modal
      open={opnameId !== null}
      title={opname ? `Detail ${opname.opname_number}` : 'Detail Stock Opname'}
      onClose={onClose}
      wide
    >
      {isPending || !opname ? (
        <div className="h-32 animate-pulse rounded bg-neutral-bg" />
      ) : (
        <div className="flex flex-col gap-4">
          <p className="text-[13px] text-ink-secondary">
            {opname.opname_at} · {opname.user?.name ?? '—'} · {opname.summary.total_items} item ·{' '}
            {opname.summary.adjusted_items} selisih
            {opname.note ? ` · Catatan: ${opname.note}` : ''}
          </p>
          {diffs.length === 0 ? (
            <p className="rounded-lg bg-success-bg px-4 py-3 text-[13px] text-success-ink">
              Semua stok fisik cocok dengan sistem.
            </p>
          ) : (
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="bg-table-header text-[10px] font-bold uppercase tracking-wide text-ink-secondary">
                  <th className="px-3 py-2">Obat</th>
                  <th className="px-3 py-2">Batch</th>
                  <th className="px-3 py-2 text-right">Sistem</th>
                  <th className="px-3 py-2 text-right">Fisik</th>
                  <th className="px-3 py-2 text-right">Selisih</th>
                  <th className="px-3 py-2">Alasan</th>
                </tr>
              </thead>
              <tbody>
                {diffs.map((item) => (
                  <tr key={item.id} className="border-t border-line">
                    <td className="px-3 py-2 text-[13px] font-semibold text-ink">
                      {item.medicine.name}
                    </td>
                    <td className="px-3 py-2 text-[13px] text-ink-secondary">
                      {item.batch.batch_number}
                    </td>
                    <td className="px-3 py-2 text-right text-[13px] text-ink-secondary">
                      {item.system_qty}
                    </td>
                    <td className="px-3 py-2 text-right text-[13px] text-ink">{item.physical_qty}</td>
                    <td
                      className={`px-3 py-2 text-right text-[13px] font-bold ${
                        item.difference > 0 ? 'text-success-ink' : 'text-danger-ink'
                      }`}
                    >
                      {item.difference > 0
                        ? `+${item.difference}`
                        : `−${Math.abs(item.difference)}`}
                    </td>
                    <td className="px-3 py-2 text-[13px] text-ink">{item.reason ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      )}
    </Modal>
  )
}
