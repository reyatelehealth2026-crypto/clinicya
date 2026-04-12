import { AlertTriangle } from 'lucide-react'

type VerifiedOnlyNoticeProps = {
  title: string
  description: string
}

export function VerifiedOnlyNotice({ title, description }: VerifiedOnlyNoticeProps) {
  return (
    <div className="flex gap-3 rounded-2xl border border-amber-200/60 bg-amber-50/80 p-4">
      <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-amber-100">
        <AlertTriangle size={16} className="text-amber-600" />
      </div>
      <div className="min-w-0">
        <p className="text-sm font-semibold text-amber-900">{title}</p>
        <p className="mt-0.5 text-xs leading-relaxed text-amber-700">{description}</p>
      </div>
    </div>
  )
}
