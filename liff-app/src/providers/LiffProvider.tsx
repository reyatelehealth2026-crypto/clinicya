import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'
import liff from '@line/liff'
import { env } from '@/config/env'
import { useAuthStore } from '@/stores/useAuthStore'
import { useAppStore } from '@/stores/useAppStore'

interface LiffProfile { userId: string; displayName: string; pictureUrl?: string; statusMessage?: string }

interface LiffContextValue {
  isReady: boolean
  isInClient: boolean
  isLoggedIn: boolean
  profile: LiffProfile | null
  login: () => void
  logout: () => void
}

const LiffContext = createContext<LiffContextValue>({
  isReady: false, isInClient: false, isLoggedIn: false, profile: null,
  login: () => {}, logout: () => {},
})

export function useLiff() { return useContext(LiffContext) }

export function LiffProvider({ children }: { children: ReactNode }) {
  const [isReady, setIsReady] = useState(false)
  const [isInClient, setIsInClient] = useState(false)
  const setProfile = useAuthStore((s) => s.setProfile)
  const authLogout = useAuthStore((s) => s.logout)
  const profile = useAuthStore((s) => s.profile)
  const isLoggedIn = useAuthStore((s) => s.isLoggedIn)
  const setAppReady = useAppStore((s) => s.setReady)

  useEffect(() => {
    if (!env.LIFF_ID) { setIsReady(true); setAppReady(true); return }
    liff.init({ liffId: env.LIFF_ID }).then(async () => {
      setIsInClient(liff.isInClient())
      if (liff.isLoggedIn()) {
        const p = await liff.getProfile()
        setProfile({ userId: p.userId, displayName: p.displayName, pictureUrl: p.pictureUrl, statusMessage: p.statusMessage })
      }
      setIsReady(true)
      setAppReady(true)
    }).catch((err) => {
      console.error('LIFF init failed:', err)
      setIsReady(true)
      setAppReady(true)
    })
  }, [])

  const login = () => { if (isReady && !liff.isLoggedIn()) liff.login() }
  const logout = () => { authLogout(); if (liff.isLoggedIn()) liff.logout() }

  return (
    <LiffContext.Provider value={{ isReady, isInClient, isLoggedIn, profile, login, logout }}>
      {children}
    </LiffContext.Provider>
  )
}
