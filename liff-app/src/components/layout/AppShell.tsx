import type { ReactNode } from 'react'
import { BottomNav } from './BottomNav'

export function AppShell({ children }: { children: ReactNode }) {
  return (
    <div className="min-h-screen bg-gray-50 pb-16">
      {children}
      <BottomNav />
    </div>
  )
}
