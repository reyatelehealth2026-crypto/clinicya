'use client'

// ─────────────────────────────────────────────────────────────────────────────
// PDPA data-subject rights page (สิทธิของเจ้าของข้อมูล) for the LINE Mini App.
// Lets the customer, from inside LIFF:
//   1. ถอนความยินยอม (withdraw consent)
//   2. ขอลบบัญชี/ข้อมูล (request deletion — soft flag, returns a confirmation code)
//   3. ดาวน์โหลดข้อมูลของฉัน (export own data as JSON)
//   4. เปิดนโยบายความเป็นส่วนตัว (privacy-policy.php on the PHP monolith)
// ─────────────────────────────────────────────────────────────────────────────

import { useState } from 'react'
import { Download, FileText, ShieldOff, Trash2 } from 'lucide-react'
import { useLineContext } from '@/components/providers'
import { AppShell } from '@/components/miniapp/AppShell'
import { VerifiedOnlyNotice } from '@/components/miniapp/VerifiedOnlyNotice'
import { appConfig } from '@/lib/config'
import {
  downloadJson,
  exportData,
  requestDeletion,
  withdrawConsent
} from '@/lib/data-rights-api'

type Status = { kind: 'idle' } | { kind: 'busy' } | { kind: 'ok'; text: string } | { kind: 'error'; text: string }

function StatusLine({ status }: { status: Status }) {
  if (status.kind === 'ok') {
    return <p className="mt-2 text-xs font-medium text-line">{status.text}</p>
  }
  if (status.kind === 'error') {
    return <p className="mt-2 text-xs font-medium text-red-600">{status.text}</p>
  }
  return null
}

function ActionCard({
  icon: Icon,
  title,
  description,
  children
}: {
  icon: typeof Trash2
  title: string
  description: string
  children: React.ReactNode
}) {
  return (
    <div className="rounded-2xl bg-white p-4 shadow-soft">
      <div className="flex items-start gap-3">
        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-line-soft">
          <Icon size={18} className="text-line" />
        </div>
        <div className="min-w-0 flex-1">
          <p className="text-sm font-semibold text-slate-900">{title}</p>
          <p className="mt-0.5 text-xs text-slate-500">{description}</p>
          {children}
        </div>
      </div>
    </div>
  )
}

export default function PrivacyRightsPage() {
  const line = useLineContext()
  const lineUserId = line.profile?.userId || ''

  const [withdrawStatus, setWithdrawStatus] = useState<Status>({ kind: 'idle' })
  const [deleteStatus, setDeleteStatus] = useState<Status>({ kind: 'idle' })
  const [exportStatus, setExportStatus] = useState<Status>({ kind: 'idle' })

  const privacyPolicyUrl = `${appConfig.apiBaseUrl}/privacy-policy.php`

  async function handleWithdraw() {
    if (!lineUserId) return
    setWithdrawStatus({ kind: 'busy' })
    try {
      const res = await withdrawConsent(lineUserId, 'health_data')
      if (res.success) {
        setWithdrawStatus({ kind: 'ok', text: res.message || 'ถอนความยินยอมเรียบร้อยแล้ว' })
      } else {
        setWithdrawStatus({ kind: 'error', text: res.message || 'ไม่สามารถถอนความยินยอมได้' })
      }
    } catch (e) {
      setWithdrawStatus({ kind: 'error', text: e instanceof Error ? e.message : 'เกิดข้อผิดพลาด' })
    }
  }

  async function handleRequestDeletion() {
    if (!lineUserId) return
    const confirmed = window.confirm(
      'ยืนยันการขอลบบัญชีและข้อมูลของคุณ?\n\nเราจะดำเนินการภายใน 30 วัน ' +
        '(ข้อมูลบางส่วนที่กฎหมายกำหนดให้เก็บ เช่น เอกสารภาษี จะถูกลบเมื่อพ้นระยะเวลาตามกฎหมาย)'
    )
    if (!confirmed) return

    setDeleteStatus({ kind: 'busy' })
    try {
      const res = await requestDeletion(lineUserId)
      if (res.success) {
        const code = res.confirmation_code ? ` รหัสยืนยัน: ${res.confirmation_code}` : ''
        setDeleteStatus({ kind: 'ok', text: `${res.message || 'รับคำขอลบข้อมูลแล้ว'}${code}` })
      } else {
        setDeleteStatus({ kind: 'error', text: res.message || 'ไม่สามารถส่งคำขอได้' })
      }
    } catch (e) {
      setDeleteStatus({ kind: 'error', text: e instanceof Error ? e.message : 'เกิดข้อผิดพลาด' })
    }
  }

  async function handleExport() {
    if (!lineUserId) return
    setExportStatus({ kind: 'busy' })
    try {
      const res = await exportData(lineUserId)
      if (res.success && res.data) {
        downloadJson(`reya-my-data-${Date.now()}.json`, res.data)
        setExportStatus({ kind: 'ok', text: 'ดาวน์โหลดไฟล์ข้อมูลของคุณเรียบร้อยแล้ว' })
      } else {
        setExportStatus({ kind: 'error', text: res.message || 'ไม่สามารถส่งออกข้อมูลได้' })
      }
    } catch (e) {
      setExportStatus({ kind: 'error', text: e instanceof Error ? e.message : 'เกิดข้อผิดพลาด' })
    }
  }

  const disabled = !lineUserId || !line.isReady
  const btnBase =
    'mt-3 inline-flex items-center justify-center rounded-xl px-4 py-2 text-sm font-semibold transition-colors disabled:opacity-50'

  return (
    <AppShell title="ความเป็นส่วนตัวและข้อมูล" subtitle="สิทธิของเจ้าของข้อมูล (PDPA)">
      {line.error ? <VerifiedOnlyNotice title="LINE bootstrap issue" description={line.error} /> : null}

      {line.isReady && !lineUserId && !line.error ? (
        <div className="rounded-2xl bg-amber-50 p-4 text-sm text-amber-800 shadow-soft">
          <p className="font-semibold">ยังไม่ได้เข้าสู่ระบบ LINE</p>
          <p className="mt-1 text-xs text-amber-700">กรุณาเปิดแอปผ่าน LINE เพื่อจัดการข้อมูลส่วนตัวของคุณ</p>
        </div>
      ) : null}

      <div className="space-y-3">
        <p className="px-1 text-xs text-slate-500">
          ตามพระราชบัญญัติคุ้มครองข้อมูลส่วนบุคคล พ.ศ. 2562 (PDPA) คุณมีสิทธิจัดการข้อมูลส่วนบุคคลของคุณได้ด้วยตนเอง
        </p>

        <ActionCard
          icon={ShieldOff}
          title="ถอนความยินยอม"
          description="ถอนความยินยอมให้เราใช้ข้อมูลสุขภาพของคุณ (สามารถให้ความยินยอมใหม่ได้ภายหลัง)"
        >
          <button
            type="button"
            onClick={handleWithdraw}
            disabled={disabled || withdrawStatus.kind === 'busy'}
            className={`${btnBase} bg-amber-500 text-white hover:bg-amber-600`}
          >
            {withdrawStatus.kind === 'busy' ? 'กำลังดำเนินการ...' : 'ถอนความยินยอม'}
          </button>
          <StatusLine status={withdrawStatus} />
        </ActionCard>

        <ActionCard
          icon={Download}
          title="ดาวน์โหลดข้อมูลของฉัน"
          description="ส่งออกข้อมูลส่วนตัว ประวัติการปรึกษา ความยินยอม และคำสั่งซื้อของคุณเป็นไฟล์ JSON"
        >
          <button
            type="button"
            onClick={handleExport}
            disabled={disabled || exportStatus.kind === 'busy'}
            className={`${btnBase} bg-line text-white hover:bg-line/90`}
          >
            {exportStatus.kind === 'busy' ? 'กำลังเตรียมไฟล์...' : 'ดาวน์โหลดข้อมูล'}
          </button>
          <StatusLine status={exportStatus} />
        </ActionCard>

        <ActionCard
          icon={Trash2}
          title="ขอลบบัญชี/ข้อมูล"
          description="ส่งคำขอให้เราลบบัญชีและข้อมูลของคุณ เราจะดำเนินการภายใน 30 วัน"
        >
          <button
            type="button"
            onClick={handleRequestDeletion}
            disabled={disabled || deleteStatus.kind === 'busy'}
            className={`${btnBase} bg-red-500 text-white hover:bg-red-600`}
          >
            {deleteStatus.kind === 'busy' ? 'กำลังส่งคำขอ...' : 'ขอลบข้อมูล'}
          </button>
          <StatusLine status={deleteStatus} />
        </ActionCard>

        <a
          href={privacyPolicyUrl}
          target="_blank"
          rel="noopener noreferrer"
          className="flex items-center gap-3 rounded-2xl bg-white px-4 py-3 shadow-soft transition-colors hover:bg-slate-50"
        >
          <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-line-soft">
            <FileText size={18} className="text-line" />
          </div>
          <span className="flex-1 text-sm font-medium text-slate-900">นโยบายความเป็นส่วนตัว</span>
          <span className="text-xs text-slate-400">เปิด</span>
        </a>
      </div>
    </AppShell>
  )
}
