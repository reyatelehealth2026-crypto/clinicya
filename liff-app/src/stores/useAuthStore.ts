import { create } from 'zustand'
import type { Member, Tier } from '@/types/member'

interface LiffProfile {
  userId: string
  displayName: string
  pictureUrl?: string
  statusMessage?: string
}

interface AuthState {
  isLoggedIn: boolean
  profile: LiffProfile | null
  member: Member | null
  tier: Tier | null
  setProfile: (profile: LiffProfile) => void
  setMember: (member: Member, tier: Tier | null) => void
  logout: () => void
}

export const useAuthStore = create<AuthState>((set) => ({
  isLoggedIn: false,
  profile: null,
  member: null,
  tier: null,
  setProfile: (profile) => set({ isLoggedIn: true, profile }),
  setMember: (member, tier) => set({ member, tier }),
  logout: () => set({ isLoggedIn: false, profile: null, member: null, tier: null }),
}))
