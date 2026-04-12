'use client'

import { CheckCircle, Info, TriangleAlert, X, XCircle } from 'lucide-react'
import { cn } from '@/lib/utils'
import { useToast, type ToastType } from '@/lib/toast'

const config: Record<ToastType, { icon: React.ReactNode; border: string }> = {
  success: { icon: <CheckCircle size={16} className="shrink-0 text-emerald-500" />, border: 'border-l-emerald-400' },
  error:   { icon: <XCircle size={16} className="shrink-0 text-red-500" />,         border: 'border-l-red-400'     },
  warning: { icon: <TriangleAlert size={16} className="shrink-0 text-amber-500" />, border: 'border-l-amber-400'   },
  info:    { icon: <Info size={16} className="shrink-0 text-blue-500" />,           border: 'border-l-blue-400'    },
}

export function ToastContainer() {
  const { toasts, dismiss } = useToast()
  if (toasts.length === 0) return null

  return (
    <div className="pointer-events-none fixed inset-x-0 top-4 z-[200] flex flex-col items-center gap-2 px-4">
      {toasts.map(t => {
        const { icon, border } = config[t.type]
        return (
          <div
            key={t.id}
            className={cn(
              'pointer-events-auto flex w-full max-w-sm animate-slide-up items-center gap-3 rounded-2xl border-l-4 bg-white px-4 py-3 shadow-card',
              border
            )}
          >
            {icon}
            <p className="flex-1 text-sm font-medium text-slate-800">{t.message}</p>
            <button onClick={() => dismiss(t.id)} className="shrink-0 p-0.5 text-slate-400 hover:text-slate-600">
              <X size={14} />
            </button>
          </div>
        )
      })}
    </div>
  )
}
