'use client'

import { useCallback, useEffect, useRef, useState } from 'react'
import type { MiniAppBanner } from '@/types/miniapp-home'
import { UniversalLink } from '@/components/miniapp/UniversalLink'

interface BannerSliderProps {
  banners: MiniAppBanner[]
  autoPlayMs?: number
}

export function BannerSlider({ banners, autoPlayMs = 4000 }: BannerSliderProps) {
  const [current, setCurrent] = useState(0)
  const trackRef = useRef<HTMLDivElement>(null)
  const touchStartX = useRef(0)
  const touchDeltaX = useRef(0)
  const autoPlayRef = useRef<ReturnType<typeof setInterval> | undefined>(undefined)

  const count = banners.length

  const goTo = useCallback((index: number) => {
    if (count === 0) return
    setCurrent(((index % count) + count) % count)
  }, [count])

  const next = useCallback(() => goTo(current + 1), [current, goTo])

  // Auto-play
  useEffect(() => {
    if (count <= 1) return
    autoPlayRef.current = setInterval(next, autoPlayMs)
    return () => clearInterval(autoPlayRef.current)
  }, [next, autoPlayMs, count])

  // Pause auto-play on touch
  const pauseAutoPlay = useCallback(() => {
    clearInterval(autoPlayRef.current)
  }, [])

  const resumeAutoPlay = useCallback(() => {
    if (count <= 1) return
    autoPlayRef.current = setInterval(next, autoPlayMs)
  }, [next, autoPlayMs, count])

  // Touch handlers for swipe
  const onTouchStart = useCallback((e: React.TouchEvent) => {
    pauseAutoPlay()
    touchStartX.current = e.touches[0].clientX
    touchDeltaX.current = 0
  }, [pauseAutoPlay])

  const onTouchMove = useCallback((e: React.TouchEvent) => {
    touchDeltaX.current = e.touches[0].clientX - touchStartX.current
  }, [])

  const onTouchEnd = useCallback(() => {
    const threshold = 50
    if (touchDeltaX.current > threshold) {
      goTo(current - 1)
    } else if (touchDeltaX.current < -threshold) {
      goTo(current + 1)
    }
    touchDeltaX.current = 0
    resumeAutoPlay()
  }, [current, goTo, resumeAutoPlay])

  if (count === 0) return null

  return (
    <div className="banner-slider">
      <div
        ref={trackRef}
        className="banner-slider-track"
        style={{ transform: `translateX(-${current * 100}%)` }}
        onTouchStart={onTouchStart}
        onTouchMove={onTouchMove}
        onTouchEnd={onTouchEnd}
      >
        {banners.map((banner) => (
          <div key={banner.id} className="banner-slider-slide">
            <UniversalLink link={banner.link} className="block w-full">
              <div
                className="relative w-full overflow-hidden rounded-2xl"
                style={{ backgroundColor: banner.bgColor || undefined }}
              >
                <img
                  src={banner.imageMobileUrl || banner.imageUrl}
                  alt={banner.title || ''}
                  className="w-full object-cover"
                  style={{ aspectRatio: '16/7' }}
                  loading="lazy"
                />
                {(banner.title || banner.subtitle) && (
                  <div className="absolute inset-0 flex flex-col justify-end bg-gradient-to-t from-black/40 to-transparent p-4">
                    {banner.title && (
                      <p className="text-sm font-bold text-white drop-shadow-sm">{banner.title}</p>
                    )}
                    {banner.subtitle && (
                      <p className="mt-0.5 text-xs text-white/80">{banner.subtitle}</p>
                    )}
                  </div>
                )}
              </div>
            </UniversalLink>
          </div>
        ))}
      </div>

      {/* Dots */}
      {count > 1 && (
        <div className="banner-slider-dots">
          {banners.map((_, i) => (
            <button
              key={i}
              type="button"
              onClick={() => { pauseAutoPlay(); goTo(i); resumeAutoPlay() }}
              className={`banner-slider-dot ${i === current ? 'active' : ''}`}
              aria-label={`Slide ${i + 1}`}
            />
          ))}
        </div>
      )}
    </div>
  )
}

export function BannerSliderSkeleton() {
  return (
    <div className="w-full animate-pulse rounded-2xl bg-slate-100" style={{ aspectRatio: '16/7' }} />
  )
}
