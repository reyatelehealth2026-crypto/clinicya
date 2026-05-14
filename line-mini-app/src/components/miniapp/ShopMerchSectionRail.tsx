'use client'

import type { HomeSection } from '@/types/miniapp-home'
import { ShopMerchProductCard } from '@/components/miniapp/ShopMerchProductCard'

type ShopMerchSectionRailProps = {
  section: HomeSection
}

export function ShopMerchSectionRail({ section }: ShopMerchSectionRailProps) {
  if (section.products.length === 0) return null

  return (
    <section className="retail-surface overflow-hidden p-4">
      <div className="mb-4 flex items-start justify-between gap-3">
        <div className="min-w-0">
          <div className="flex items-center gap-2">
            {section.iconUrl ? (
              <img src={section.iconUrl} alt="" className="h-8 w-8 rounded-xl object-cover" />
            ) : null}
            <h2 className="text-base font-bold text-slate-900">{section.title}</h2>
          </div>
          {section.subtitle ? (
            <p className="mt-1 text-sm text-slate-500">{section.subtitle}</p>
          ) : null}
        </div>
        <span className="rounded-full bg-line-soft px-3 py-1 text-[11px] font-semibold text-line">
          {section.style === 'flash_sale' ? 'โปรโมชัน' : 'คอลเลกชัน'}
        </span>
      </div>

      <div className="hide-scrollbar flex gap-3 overflow-x-auto pb-1">
        {section.products.map((product) => (
          <ShopMerchProductCard key={product.id} product={product} />
        ))}
      </div>
    </section>
  )
}
