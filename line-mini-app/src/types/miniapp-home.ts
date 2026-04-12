export type LinkType = 'url' | 'miniapp' | 'liff' | 'line_chat' | 'deep_link' | 'none'
export type SectionStyle = 'flash_sale' | 'horizontal_scroll' | 'grid' | 'banner_list'

export interface UniversalLink {
  type: LinkType
  value: string
  label?: string
}

export interface MiniAppBanner {
  id: number
  title: string | null
  subtitle: string | null
  description: string | null
  imageUrl: string
  imageMobileUrl: string | null
  link: UniversalLink
  bgColor: string | null
  position: string
  displayOrder: number
}

export interface ProductBadge {
  text: string
  color: string
}

export interface HomeProduct {
  id: number
  title: string
  shortDescription: string | null
  imageUrl: string
  imageGallery: string[]
  originalPrice: number | null
  salePrice: number | null
  discountPercent: number | null
  priceUnit: string | null
  promotionTags: string[]
  promotionLabel: string | null
  badges: ProductBadge[]
  customLabel: string | null
  stockQty: number | null
  limitQty: number | null
  showStockBadge: boolean
  deliveryOptions: string[]
  link: Omit<UniversalLink, 'label'>
  displayOrder: number
}

export interface HomeSection {
  id: number
  sectionKey: string
  title: string
  subtitle: string | null
  style: SectionStyle
  bgColor: string | null
  textColor: string | null
  iconUrl: string | null
  countdownEndsAt: string | null
  displayOrder: number
  products: HomeProduct[]
}

export interface HomeAllResponse {
  success: boolean
  data?: {
    banners: MiniAppBanner[]
    sections: HomeSection[]
  }
  error?: string
}
