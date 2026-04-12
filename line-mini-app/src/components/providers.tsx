'use client'

import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { type ReactNode, useState, useEffect, createContext, useContext } from 'react'
import { bootstrapLine } from '@/lib/line-miniapp'
import type { LineBootstrapState } from '@/types/line'
import { ToastProvider } from '@/lib/toast'
import { ToastContainer } from '@/components/miniapp/ToastContainer'

const LineContext = createContext<LineBootstrapState | null>(null)

export function useLineContext() {
  const context = useContext(LineContext)
  if (!context) {
    throw new Error('useLineContext must be used within LineProvider')
  }
  return context
}

function LineProvider({ children }: { children: ReactNode }) {
  const [lineState, setLineState] = useState<LineBootstrapState>({
    isReady: false,
    isLoggedIn: false,
    isInClient: false,
    profile: null,
    accessToken: null,
    error: null
  })

  useEffect(() => {
    bootstrapLine().then((state) => {
      setLineState(state)
      if (state.needsLogin) {
        import('@line/liff').then((liff) => {
          liff.default.login()
        })
      }
    })
  }, [])

  if (!lineState.isReady) {
    return (
      <div className="flex min-h-[100dvh] flex-col items-center justify-center bg-surface-secondary">
        <div className="flex flex-col items-center gap-4">
          <div className="gradient-card flex h-16 w-16 items-center justify-center rounded-2xl shadow-glow">
            <div className="h-6 w-6 animate-spin rounded-full border-3 border-white border-t-transparent" />
          </div>
          <p className="text-sm font-medium text-slate-500">กำลังเตรียมระบบ...</p>
        </div>
      </div>
    )
  }

  if (lineState.needsLogin) {
    return (
      <div className="flex min-h-[100dvh] flex-col items-center justify-center bg-surface-secondary">
        <div className="flex flex-col items-center gap-4">
          <div className="gradient-card flex h-16 w-16 items-center justify-center rounded-2xl shadow-glow">
            <div className="h-6 w-6 animate-spin rounded-full border-3 border-white border-t-transparent" />
          </div>
          <p className="text-sm font-medium text-slate-500">กำลังเข้าสู่ระบบ LINE...</p>
        </div>
      </div>
    )
  }

  if (lineState.error) {
    return (
      <div className="flex min-h-[100dvh] flex-col items-center justify-center bg-surface-secondary px-6">
        <div className="w-full max-w-sm rounded-3xl bg-white p-6 text-center shadow-card">
          <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-red-50">
            <svg className="h-6 w-6 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
              <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
          <p className="mt-3 text-sm font-semibold text-slate-900">เกิดข้อผิดพลาด</p>
          <p className="mt-1 text-xs text-slate-500">{lineState.error}</p>
        </div>
      </div>
    )
  }

  return <LineContext.Provider value={lineState}>{children}</LineContext.Provider>
}

export function Providers({ children }: { children: ReactNode }) {
  const [queryClient] = useState(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: {
            staleTime: 30_000,
            retry: 1,
            refetchOnWindowFocus: false
          }
        }
      })
  )

  return (
    <ToastProvider>
      <ToastContainer />
      <QueryClientProvider client={queryClient}>
        <LineProvider>{children}</LineProvider>
      </QueryClientProvider>
    </ToastProvider>
  )
}
