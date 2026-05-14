'use client'

import { Minus, Plus } from 'lucide-react'
import { cn } from '@/lib/utils'

type QuantityStepperProps = {
  value: number
  min?: number
  max?: number
  onChange: (nextValue: number) => void
  disabled?: boolean
  size?: 'sm' | 'md' | 'lg'
}

const sizeMap = {
  sm: {
    button: 'h-9 w-9',
    value: 'min-w-[2rem] text-base',
  },
  md: {
    button: 'h-11 w-11',
    value: 'min-w-[2.5rem] text-lg',
  },
  lg: {
    button: 'h-12 w-12',
    value: 'min-w-[3rem] text-[1.75rem]',
  },
} as const

export function QuantityStepper({
  value,
  min = 1,
  max,
  onChange,
  disabled = false,
  size = 'md',
}: QuantityStepperProps) {
  const ui = sizeMap[size]

  return (
    <div className="inline-flex items-center gap-3">
      <button
        type="button"
        onClick={() => onChange(Math.max(min, value - 1))}
        disabled={disabled || value <= min}
        className={cn(
          'flex items-center justify-center rounded-full border border-slate-200 bg-white text-slate-500 shadow-soft transition hover:border-slate-300 hover:text-slate-700 disabled:cursor-not-allowed disabled:opacity-40',
          ui.button
        )}
        aria-label="ลดจำนวน"
      >
        <Minus size={size === 'lg' ? 22 : 18} />
      </button>

      <span className={cn('text-center font-semibold tabular-nums text-slate-800', ui.value)}>
        {value}
      </span>

      <button
        type="button"
        onClick={() => onChange(max != null ? Math.min(max, value + 1) : value + 1)}
        disabled={disabled || (max != null && value >= max)}
        className={cn(
          'flex items-center justify-center rounded-full bg-line text-white shadow-glow transition hover:bg-line-dark disabled:cursor-not-allowed disabled:opacity-40',
          ui.button
        )}
        aria-label="เพิ่มจำนวน"
      >
        <Plus size={size === 'lg' ? 22 : 18} />
      </button>
    </div>
  )
}
