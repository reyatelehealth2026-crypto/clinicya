'use client'

import { useEffect, useRef, useState } from 'react'
import { useLineContext } from '@/components/providers'
import { AppShell } from '@/components/miniapp/AppShell'
import { claimPoints, type ClaimResult, type ClaimState } from '@/lib/points-claim-api'

interface ClaimClientProps {
  token: string
}

type ViewState = 'loading' | 'claiming' | 'success' | 'error'

const DARK_GREEN = '#006400'
const LIGHT_GREEN = '#E8F5E9'

function StatusCard({
  icon,
  title,
  description,
  children
}: {
  icon: string
  title: string
  description?: string
  children?: React.ReactNode
}) {
  return (
    <div className="mx-auto w-full max-w-sm rounded-3xl bg-white p-6 text-center shadow-card">
      <div
        className="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl text-3xl"
        style={{ backgroundColor: LIGHT_GREEN }}
      >
        {icon}
      </div>
      <h1 className="mt-4 text-lg font-bold text-slate-900">{title}</h1>
      {description ? <p className="mt-1 text-sm text-slate-500">{description}</p> : null}
      {children}
    </div>
  )
}

export function ClaimClient({ token }: ClaimClientProps) {
  const line = useLineContext()
  const [view, setView] = useState<ViewState>('loading')
  const [result, setResult] = useState<ClaimResult | null>(null)
  const [errorState, setErrorState] = useState<ClaimState | 'network'>('invalid')
  const [errorMessage, setErrorMessage] = useState<string>('')
  // Guard against React 18 StrictMode double-invoke / re-renders firing claim twice.
  const claimedRef = useRef(false)

  useEffect(() => {
    if (!line.isReady) return

    // Not logged in (guest) — the customer must open inside LINE to be identified.
    if (!line.profile?.userId) {
      setView('error')
      setErrorState('invalid')
      setErrorMessage('กรุณาเปิดหน้านี้ผ่านแอป LINE เพื่อรับแต้ม')
      return
    }

    if (claimedRef.current) return
    claimedRef.current = true

    setView('claiming')
    claimPoints(token, {
      lineUserId: line.profile.userId,
      displayName: line.profile.displayName,
      pictureUrl: line.profile.pictureUrl
    })
      .then((res) => {
        if (res.success) {
          setResult(res)
          setView('success')
        } else {
          setView('error')
          setErrorState(res.state ?? 'invalid')
          setErrorMessage(res.message ?? 'ไม่สามารถรับแต้มได้')
        }
      })
      .catch((err: unknown) => {
        setView('error')
        setErrorState('network')
        setErrorMessage(err instanceof Error ? err.message : 'เกิดข้อผิดพลาดในการเชื่อมต่อ')
      })
  }, [line.isReady, line.profile, token])

  return (
    <AppShell
      showBottomNav={false}
      header={
        <div className="px-4 py-4 text-center text-white" style={{ backgroundColor: DARK_GREEN }}>
          <p className="text-base font-bold">⭐ รับแต้มสะสม</p>
        </div>
      }
    >
      {view === 'loading' || view === 'claiming' ? (
        <StatusCard icon="⏳" title="กำลังบันทึกแต้ม..." description="กรุณารอสักครู่" />
      ) : null}

      {view === 'success' && result ? (
        <StatusCard
          icon="✅"
          title="รับแต้มสำเร็จ!"
          description={result.shop_name ? `จาก ${result.shop_name}` : undefined}
        >
          <div
            className="mt-4 rounded-2xl border p-4"
            style={{ backgroundColor: LIGHT_GREEN, borderColor: DARK_GREEN }}
          >
            <div className="flex items-center justify-between">
              <span className="text-sm font-semibold" style={{ color: DARK_GREEN }}>
                แต้มที่ได้รับ
              </span>
              <span className="text-2xl font-extrabold" style={{ color: DARK_GREEN }}>
                +{(result.points ?? 0).toLocaleString()} ⭐
              </span>
            </div>
            <div className="mt-2 flex items-center justify-between border-t border-emerald-200 pt-2">
              <span className="text-xs text-slate-500">แต้มสะสมรวม</span>
              <span className="text-sm font-bold" style={{ color: DARK_GREEN }}>
                {(result.total_points ?? 0).toLocaleString()} ⭐
              </span>
            </div>
          </div>
          {result.voucher_no ? (
            <p className="mt-3 text-xs text-slate-400">เลขที่ {result.voucher_no}</p>
          ) : null}
          <p className="mt-2 text-xs text-slate-500">เราได้ส่งใบรับแต้มไปที่แชท LINE ของคุณแล้ว</p>
        </StatusCard>
      ) : null}

      {view === 'error' ? (
        <StatusCard
          icon={errorState === 'claimed' ? '🔁' : errorState === 'expired' ? '⌛' : '⚠️'}
          title={
            errorState === 'claimed'
              ? 'รหัสนี้ถูกใช้ไปแล้ว'
              : errorState === 'expired'
                ? 'รหัสหมดอายุแล้ว'
                : 'ไม่สามารถรับแต้มได้'
          }
          description={errorMessage || 'กรุณาติดต่อร้านค้า'}
        />
      ) : null}
    </AppShell>
  )
}
