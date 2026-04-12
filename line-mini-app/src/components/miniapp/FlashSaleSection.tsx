'use client'

import { useEffect, useState } from 'react'
import { ChevronRight, Clock } from 'lucide-react'
import type { HomeSection } from '@/types/miniapp-home'
import { ProductCard } from '@/components/miniapp/ProductCard'

interface FlashSaleSectionProps {
  section: HomeSection
}

function formatCountdown(totalSec: number) {
  const h = Math.floor(totalSec / 3600)
  const m = Math.floor((totalSec % 3600) / 60)
  const s = totalSec % 60
  return { h, m, s }
}

function CountdownTimer({ endsAt }: { endsAt: string }) {
  const [secondsLeft, setSecondsLeft] = useState(() =>
    Math.max(0, Math.floor((new Date(endsAt).getTime() - Date.now()) / 1000))
  )

  useEffect(() => {
    if (secondsLeft <= 0) return
    const id = setInterval(() => {
      setSecondsLeft(prev => Math.max(0, prev - 1))
    }, 1000)
    return () => clearInterval(id)
  }, [secondsLeft])

  if (secondsLeft <= 0) return <span className="flash-sale-expired">หมดเวลา</span>

  const { h, m, s } = formatCountdown(secondsLeft)

  return (
    <div className="flash-sale-countdown">
      <Clock size={12} />
      <span className="flash-sale-countdown-block">{String(h).padStart(2, '0')}h</span>
      <span className="flash-sale-countdown-block">{String(m).padStart(2, '0')}</span>
      <span className="flash-sale-countdown-block">{String(s).padStart(2, '0')}</span>
    </div>
  )
}

export function FlashSaleSection({ section }: FlashSaleSectionProps) {
  if (section.products.length === 0) return null

  return (
    <section
      className="flash-sale-section"
      style={{
        backgroundColor: section.bgColor || '#8B0000',
        color: section.textColor || '#FFFFFF',
      }}
    >
      {/* Header */}
      <div className="flash-sale-header">
        <div className="flash-sale-header-left">
          {section.iconUrl && (
            <img src={section.iconUrl} alt="" className="flash-sale-icon" />
          )}
          <div>
            <h3 className="flash-sale-title">{section.title}</h3>
            {section.subtitle && (
              <p className="flash-sale-subtitle">{section.subtitle}</p>
            )}
          </div>
        </div>
        <div className="flash-sale-header-right">
          {section.countdownEndsAt && (
            <CountdownTimer endsAt={section.countdownEndsAt} />
          )}
          <button type="button" className="flash-sale-see-all">
            ดูเพิ่มเติม <ChevronRight size={14} />
          </button>
        </div>
      </div>

      {/* Product scroll */}
      <div className="flash-sale-scroll">
        {section.products.map((product) => (
          <ProductCard key={product.id} product={product} variant="flash_sale" />
        ))}
      </div>
    </section>
  )
}
