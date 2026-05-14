'use client'

import { Suspense } from 'react'
import { ShopProductDetailClient } from '@/components/miniapp/ShopProductDetailClient'

// `output: 'export'` cannot pre-render unknown `/shop/[id]` paths, so the
// detail page lives at /shop/product?id=… instead. The client reads `id`
// from useSearchParams, which must sit inside a Suspense boundary.
export default function ShopProductDetailPage() {
  return (
    <Suspense fallback={null}>
      <ShopProductDetailClient />
    </Suspense>
  )
}
