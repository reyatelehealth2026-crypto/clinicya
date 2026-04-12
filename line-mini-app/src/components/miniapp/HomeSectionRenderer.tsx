'use client'

import { ChevronRight } from 'lucide-react'
import type { HomeSection } from '@/types/miniapp-home'
import { FlashSaleSection } from '@/components/miniapp/FlashSaleSection'
import { ProductCard } from '@/components/miniapp/ProductCard'

interface HomeSectionRendererProps {
  section: HomeSection
}

function SectionHeader({ section }: { section: HomeSection }) {
  return (
    <div className="home-section-header">
      <div className="home-section-header-left">
        {section.iconUrl && (
          <img src={section.iconUrl} alt="" className="home-section-icon" />
        )}
        <div>
          <h3 className="home-section-title">{section.title}</h3>
          {section.subtitle && (
            <p className="home-section-subtitle">{section.subtitle}</p>
          )}
        </div>
      </div>
      <button className="home-section-see-all" type="button">
        ดูเพิ่มเติม <ChevronRight size={14} />
      </button>
    </div>
  )
}

export function HomeSectionRenderer({ section }: HomeSectionRendererProps) {
  if (section.products.length === 0) return null

  switch (section.style) {
    case 'flash_sale':
      return (
        <div className="mx-3">
          <FlashSaleSection section={section} />
        </div>
      )

    case 'horizontal_scroll':
      return (
        <section className="home-section home-section-card">
          <SectionHeader section={section} />
          <div className="home-section-scroll">
            {section.products.map((product) => (
              <ProductCard key={product.id} product={product} />
            ))}
          </div>
        </section>
      )

    case 'grid':
      return (
        <section className="home-section home-section-card">
          <SectionHeader section={section} />
          <div className="home-section-grid">
            {section.products.map((product) => (
              <ProductCard key={product.id} product={product} />
            ))}
          </div>
        </section>
      )

    case 'banner_list':
      return (
        <section className="home-section home-section-card">
          <SectionHeader section={section} />
          <div className="space-y-2">
            {section.products.map((product) => (
              <ProductCard key={product.id} product={product} />
            ))}
          </div>
        </section>
      )

    default:
      return null
  }
}
