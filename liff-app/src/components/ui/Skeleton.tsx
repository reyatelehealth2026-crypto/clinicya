import { clsx } from 'clsx'

interface SkeletonProps {
  className?: string
  height?: string
  rounded?: boolean
}

export function Skeleton({ className, height, rounded }: SkeletonProps) {
  return (
    <div
      className={clsx('bg-gray-200 animate-pulse', rounded ? 'rounded-full' : 'rounded-lg', className)}
      style={height ? { height } : undefined}
    />
  )
}
