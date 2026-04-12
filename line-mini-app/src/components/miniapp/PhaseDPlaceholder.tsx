'use client'

import Link from 'next/link'
import { AppShell } from '@/components/miniapp/AppShell'

type Props = {
  title: string
  subtitle?: string
}

/** Phase D extended routes — tracked in docs; not all are product-prioritized yet. */
export function PhaseDPlaceholder({ title, subtitle }: Props) {
  return (
    <AppShell title={title} subtitle={subtitle}>
      <div className="rounded-3xl bg-white p-6 text-center shadow-soft">
        <p className="text-sm text-slate-600">
          ฟีเจอร์นี้อยู่ระหว่างกำหนดลำดับความสำคัญกับทีม — ใช้แชท OA หรือโทรร้านหากต้องการความช่วยเหลือเร่งด่วน
        </p>
        <Link href="/" className="mt-4 inline-block text-sm font-semibold text-line">
          กลับหน้าหลัก
        </Link>
      </div>
    </AppShell>
  )
}
