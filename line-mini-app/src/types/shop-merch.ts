import type { HomeSection, MiniAppBanner } from '@/types/miniapp-home'

export type ShopMerchResponse = {
  success: boolean
  data?: {
    banners: MiniAppBanner[]
    sections: HomeSection[]
  }
  error?: string
  timestamp?: string
}
