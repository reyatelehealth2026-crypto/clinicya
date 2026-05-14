'use client'

import { useState } from 'react'
import Image from 'next/image'
import { Package2 } from 'lucide-react'
import { cn } from '@/lib/utils'

type ShopDetailGalleryProps = {
  images: string[]
  name: string
}

export function ShopDetailGallery({ images, name }: ShopDetailGalleryProps) {
  const [activeIndex, setActiveIndex] = useState(0)
  const hasImages = images.length > 0

  if (!hasImages) {
    return (
      <div className="retail-surface flex aspect-square items-center justify-center bg-slate-100 text-slate-300">
        <Package2 size={44} />
      </div>
    )
  }

  return (
    <div className="space-y-3">
      <div className="retail-surface relative aspect-square overflow-hidden">
        <Image
          src={images[activeIndex]}
          alt={name}
          fill
          className="object-cover"
          sizes="100vw"
          priority
        />
      </div>

      {images.length > 1 ? (
        <>
          <div className="flex justify-center gap-2">
            {images.map((_, index) => (
              <button
                key={`${index}`}
                type="button"
                onClick={() => setActiveIndex(index)}
                className={cn(
                  'h-2.5 rounded-full transition-all',
                  index === activeIndex ? 'w-6 bg-line' : 'w-2.5 bg-slate-200'
                )}
                aria-label={`รูปสินค้า ${index + 1}`}
              />
            ))}
          </div>

          <div className="hide-scrollbar flex gap-2 overflow-x-auto pb-1">
            {images.map((image, index) => (
              <button
                key={image + index}
                type="button"
                onClick={() => setActiveIndex(index)}
                className={cn(
                  'relative h-16 w-16 shrink-0 overflow-hidden rounded-2xl border transition',
                  index === activeIndex ? 'border-line shadow-soft' : 'border-slate-200'
                )}
              >
                <Image src={image} alt="" fill className="object-cover" sizes="64px" />
              </button>
            ))}
          </div>
        </>
      ) : null}
    </div>
  )
}
