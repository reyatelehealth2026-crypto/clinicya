'use client'

import { Suspense } from 'react'
import { useSearchParams } from 'next/navigation'
import { OrderDetailClient } from '@/components/miniapp/OrderDetailClient'

function OrderDetailFromQuery() {
  const sp = useSearchParams()
  const id = sp.get('id') ?? ''
  if (!id) {
    return (
      <div className="p-8 text-center text-sm text-gray-500">
        Missing order id.
      </div>
    )
  }
  return <OrderDetailClient orderId={id} />
}

// `output: 'export'` cannot pre-render unknown `/order/[id]` paths, so we
// switched to query-string routing (/order?id=…). useSearchParams must sit
// inside a Suspense boundary in App Router client trees.
export default function OrderDetailPage() {
  return (
    <Suspense fallback={null}>
      <OrderDetailFromQuery />
    </Suspense>
  )
}
