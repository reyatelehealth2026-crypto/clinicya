'use client'

import { Suspense } from 'react'
import { useSearchParams } from 'next/navigation'
import { ClaimClient } from '@/components/miniapp/ClaimClient'

function ClaimFromQuery() {
  const sp = useSearchParams()
  const token = sp.get('token') ?? ''
  if (!token) {
    return (
      <div className="p-8 text-center text-sm text-gray-500">
        ไม่พบรหัสรับแต้ม / Missing claim token.
      </div>
    )
  }
  return <ClaimClient token={token} />
}

// `output: 'export'` with query-string routing (/claim?token=…). The give-points
// QR opens https://liff.line.me/{liffId}/claim?token=… which LIFF maps onto the
// Mini App endpoint (re-ya.com/miniapp/claim/). useSearchParams must sit inside a
// Suspense boundary in the App Router client tree.
export default function ClaimPage() {
  return (
    <Suspense fallback={null}>
      <ClaimFromQuery />
    </Suspense>
  )
}
