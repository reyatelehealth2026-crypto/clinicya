'use client'

import { useRef, type ReactNode } from 'react'
import { BottomNav } from '@/components/miniapp/BottomNav'
import { MiniAppHeader } from '@/components/miniapp/MiniAppHeader'
import { usePullToRefresh } from '@/lib/hooks'
import { cn } from '@/lib/utils'
import type { OdooCustomerProfile } from '@/types/odoo-profile'

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
          {children}
        </div>
      </main>
      {showBottomNav ? <BottomNav /> : null}
    </div>
  )
}
