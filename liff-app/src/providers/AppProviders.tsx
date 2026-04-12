import type { ReactNode } from 'react'
import { BrowserRouter } from 'react-router-dom'
import { QueryProvider } from './QueryProvider'
import { LiffProvider } from './LiffProvider'

export function AppProviders({ children }: { children: ReactNode }) {
  return (
    <BrowserRouter>
      <QueryProvider>
        <LiffProvider>
          {children}
        </LiffProvider>
      </QueryProvider>
    </BrowserRouter>
  )
}
