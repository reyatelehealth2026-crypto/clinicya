'use client'

import { useQuery } from '@tanstack/react-query'
import { useLineContext } from '@/components/providers'
import { AppShell } from '@/components/miniapp/AppShell'
import { RedemptionHistoryList } from '@/components/miniapp/RedemptionHistoryList'
import { VerifiedOnlyNotice } from '@/components/miniapp/VerifiedOnlyNotice'
import { getMyRedemptions } from '@/lib/rewards-api'

function LoadingSkeleton() {
  return (
    <div className="space-y-3">
      {[1, 2, 3].map((i) => (
        <div key={i} className="skeleton h-24 w-full rounded-3xl" />
      ))}
    </div>
  )
}

export function HistoryClient() {
  const line = useLineContext()
  const lineUserId = line.profile?.userId || ''

  const historyQuery = useQuery({
    queryKey: ['reward-history', lineUserId],
    queryFn: () => getMyRedemptions(lineUserId),
    enabled: Boolean(lineUserId)
  })

  return (
    <AppShell title="ประวัติการแลก" subtitle="ติดตามสถานะการแลกรางวัลของคุณ">
      {line.error ? <VerifiedOnlyNotice title="LINE bootstrap issue" description={line.error} /> : null}

      {historyQuery.isLoading ? <LoadingSkeleton /> : null}

      {!historyQuery.isLoading ? (
        <RedemptionHistoryList items={historyQuery.data?.redemptions || []} />
      ) : null}
    </AppShell>
  )
}
