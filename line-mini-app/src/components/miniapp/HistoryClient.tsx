'use client'

import { useQuery } from '@tanstack/react-query'
import { useLineContext } from '@/components/providers'
import { AppShell } from '@/components/miniapp/AppShell'
import {
  PointsBalanceSummary,
  PointsTransactionsList
} from '@/components/miniapp/PointsTransactionsList'
import { VerifiedOnlyNotice } from '@/components/miniapp/VerifiedOnlyNotice'
import { getPointsHistory } from '@/lib/rewards-api'

function LoadingSkeleton() {
  return (
    <div className="space-y-3">
      <div className="skeleton h-32 w-full rounded-3xl" />
      {[1, 2, 3].map((i) => (
        <div key={i} className="skeleton h-20 w-full rounded-2xl" />
      ))}
    </div>
  )
}

export function HistoryClient() {
  const line = useLineContext()
  const lineUserId = line.profile?.userId || ''

  const historyQuery = useQuery({
    queryKey: ['points-history', lineUserId],
    queryFn: () => getPointsHistory(lineUserId, 50),
    enabled: Boolean(lineUserId)
  })

  const user = historyQuery.data?.user
  const items = historyQuery.data?.history ?? []

  return (
    <AppShell title="ประวัติแต้ม" subtitle="รายการสะสม + ใช้แต้มของคุณ">
      {line.error ? <VerifiedOnlyNotice title="LINE bootstrap issue" description={line.error} /> : null}

      {historyQuery.isLoading ? <LoadingSkeleton /> : null}

      {!historyQuery.isLoading && user ? (
        <PointsBalanceSummary
          available={Number(user.available_points) || 0}
          totalEarned={Number(user.total_points) || 0}
          totalUsed={Number(user.used_points) || 0}
        />
      ) : null}

      {!historyQuery.isLoading ? <PointsTransactionsList items={items} /> : null}
    </AppShell>
  )
}
