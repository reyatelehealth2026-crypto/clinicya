import { create } from 'zustand'
import { env } from '@/config/env'

interface AppState {
  accountId: number
  isReady: boolean
  setReady: (v: boolean) => void
}

export const useAppStore = create<AppState>((set) => ({
  accountId: env.DEFAULT_ACCOUNT_ID,
  isReady: false,
  setReady: (v) => set({ isReady: v }),
}))
