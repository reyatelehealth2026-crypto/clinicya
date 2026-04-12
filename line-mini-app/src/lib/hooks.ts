'use client'

import { useCallback, useEffect, useRef, useState, type RefObject } from 'react'

export function useHaptic() {
  return useCallback((type: 'light' | 'medium' | 'heavy' = 'light') => {
    if (typeof navigator === 'undefined' || !navigator.vibrate) return
    const ms = { light: 10, medium: 25, heavy: 50 }
    navigator.vibrate(ms[type])
  }, [])
}

export function usePullToRefresh(
  onRefresh: (() => Promise<void>) | undefined,
  scrollRef: RefObject<HTMLElement | null>
) {
  const [isRefreshing, setIsRefreshing] = useState(false)
  const [pullY, setPullY] = useState(0)
  const startTouchY = useRef(0)
  const pullYRef = useRef(0)
  const isRefreshingRef = useRef(false)
  const THRESHOLD = 70

  useEffect(() => {
    if (!onRefresh) return
    const el = scrollRef.current
    if (!el) return

    const onTouchStart = (e: TouchEvent) => {
      if (el.scrollTop === 0) {
        startTouchY.current = e.touches[0].clientY
      }
    }

    const onTouchMove = (e: TouchEvent) => {
      if (!startTouchY.current) return
      const diff = Math.max(0, e.touches[0].clientY - startTouchY.current)
      const clamped = Math.min(diff, THRESHOLD * 1.5)
      pullYRef.current = clamped
      setPullY(clamped)
    }

    const onTouchEnd = async () => {
      const dist = pullYRef.current
      pullYRef.current = 0
      startTouchY.current = 0
      setPullY(0)
      if (dist >= THRESHOLD && !isRefreshingRef.current) {
        isRefreshingRef.current = true
        setIsRefreshing(true)
        try {
          await onRefresh()
        } finally {
          isRefreshingRef.current = false
          setIsRefreshing(false)
        }
      }
    }

    el.addEventListener('touchstart', onTouchStart, { passive: true })
    el.addEventListener('touchmove', onTouchMove, { passive: true })
    el.addEventListener('touchend', onTouchEnd)

    return () => {
      el.removeEventListener('touchstart', onTouchStart)
      el.removeEventListener('touchmove', onTouchMove)
      el.removeEventListener('touchend', onTouchEnd)
    }
  }, [onRefresh, scrollRef])

  return { isRefreshing, pullY }
}
