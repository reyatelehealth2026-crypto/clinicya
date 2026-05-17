'use client'

import { Component, useRef, type ErrorInfo, type ReactNode } from 'react'
import { BottomNav } from '@/components/miniapp/BottomNav'
import { MiniAppHeader } from '@/components/miniapp/MiniAppHeader'
import { GuestBanner } from '@/components/miniapp/GuestBanner'
import { usePullToRefresh } from '@/lib/hooks'
import { cn } from '@/lib/utils'
import type { OdooCustomerProfile } from '@/types/odoo-profile'

/** Minimal class-based ErrorBoundary (no external dep) */
class ErrorBoundary extends Component<{ children: ReactNode }, { hasError: boolean }> {
  state = { hasError: false }

  static getDerivedStateFromError(): { hasError: boolean } {
    return { hasError: true }
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    if (typeof window !== 'undefined') {
      // eslint-disable-next-line no-console
      console.error('AppShell ErrorBoundary:', error, info)
    }
  }

  handleRetry = (): void => {
    if (typeof window !== 'undefined') {
      window.location.reload()
    }
  }

  render(): ReactNode {
    if (this.state.hasError) {
      return (
        <div className="flex flex-col items-center justify-center gap-4 py-16 text-center">
          <div className="flex h-16 w-16 items-center justify-center rounded-full bg-rose-50">
            <span className="text-3xl">⚠️</span>
          </div>
          <div>
            <p className="text-base font-semibold text-slate-800">เกิดข้อผิดพลาด</p>
            <p className="mt-1 text-xs text-slate-500">เราพบปัญหาบางอย่างขณะแสดงผล</p>
          </div>
          <button
            type="button"
            onClick={this.handleRetry}
            className="rounded-2xl bg-line px-6 py-2.5 text-sm font-semibold text-white transition-opacity hover:opacity-90"
          >
            ลองอีกครั้ง
          </button>
        </div>
      )
    }
    return this.props.children
  }
}

type AppShellProps = {
  title?: string
  subtitle?: string
  showAvatar?: boolean
  odooProfile?: OdooCustomerProfile | null
  onRefresh?: () => Promise<void>
  header?: ReactNode
  showBottomNav?: boolean
  contentClassName?: string
  children: ReactNode
}

export function AppShell({
  title,
  subtitle,
  showAvatar,
  odooProfile,
  onRefresh,
  header,
  showBottomNav = true,
  contentClassName,
  children,
}: AppShellProps) {
  const mainRef = useRef<HTMLElement>(null)
  const { isRefreshing, pullY } = usePullToRefresh(onRefresh, mainRef)

  return (
    <div className="fixed inset-0 flex flex-col bg-surface-secondary">
      {header ?? (
        <MiniAppHeader
          title={title}
          subtitle={subtitle}
          showAvatar={showAvatar}
          odooProfile={odooProfile}
        />
      )}
      <GuestBanner />
      <main ref={mainRef} className="relative flex-1 overflow-y-auto overscroll-none">
        {onRefresh && (
          <div
            className="flex items-end justify-center overflow-hidden transition-all duration-200"
            style={{ height: isRefreshing ? 44 : Math.min(pullY, 44) }}
          >
            <div className="flex items-center gap-2 pb-2 text-xs font-medium text-slate-400">
              <div className={`h-3.5 w-3.5 rounded-full border-2 border-line border-t-transparent ${isRefreshing ? 'animate-spin' : ''}`} />
              <span>{isRefreshing ? 'กำลังโหลด...' : pullY >= 70 ? 'ปล่อยเพื่อรีเฟรช' : ''}</span>
            </div>
          </div>
        )}
        <div
          className={cn(
            'mx-auto flex w-full max-w-md flex-col gap-4 px-4 pb-8 pt-5',
            !showBottomNav && 'pb-0',
            contentClassName
          )}
        >
          <ErrorBoundary>{children}</ErrorBoundary>
        </div>
      </main>
      {showBottomNav ? <BottomNav /> : null}
    </div>
  )
}
