'use client'

import { type ReactNode } from 'react'
import { cn } from '@/lib/utils'

type StickyCommerceBarProps = {
  summary: ReactNode
  action: ReactNode
  className?: string
}

export function StickyCommerceBar({ summary, action, className }: StickyCommerceBarProps) {
  return (
    <div className={cn('pointer-events-none fixed inset-x-0 bottom-0 z-40 px-4 pb-4 safe-bottom', className)}>
      <div className="mx-auto max-w-md">
        <div className="floating-commerce-bar pointer-events-auto p-3">
          <div className="flex items-center gap-3">
            <div className="min-w-0 flex-1">{summary}</div>
            <div className="shrink-0">{action}</div>
          </div>
        </div>
      </div>
    </div>
  )
}
