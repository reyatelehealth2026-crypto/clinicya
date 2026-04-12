import { clsx } from 'clsx'
import type { HTMLAttributes } from 'react'

interface CardProps extends HTMLAttributes<HTMLDivElement> {
  padding?: boolean
}

export function Card({ padding = true, className, children, ...props }: CardProps) {
  return (
    <div className={clsx('bg-white rounded-xl shadow-sm', padding && 'p-4', className)} {...props}>
      {children}
    </div>
  )
}
