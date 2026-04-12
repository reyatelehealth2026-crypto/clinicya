import { useState } from 'react'
import { clsx } from 'clsx'

interface LazyImageProps {
  src: string
  alt: string
  className?: string
  fallback?: string
}

export function LazyImage({ src, alt, className, fallback }: LazyImageProps) {
  const [error, setError] = useState(false)
  const [loaded, setLoaded] = useState(false)

  if (error || !src) {
    return (
      <div className={clsx('bg-gray-100 flex items-center justify-center', className)}>
        {fallback ? <img src={fallback} alt={alt} className="w-full h-full object-cover" /> : <span className="text-gray-300 text-xs">No img</span>}
      </div>
    )
  }

  return (
    <div className={clsx('relative overflow-hidden', className)}>
      {!loaded && <div className="absolute inset-0 bg-gray-200 animate-pulse" />}
      <img
        src={src}
        alt={alt}
        loading="lazy"
        onLoad={() => setLoaded(true)}
        onError={() => setError(true)}
        className={clsx('w-full h-full object-cover transition-opacity', loaded ? 'opacity-100' : 'opacity-0')}
      />
    </div>
  )
}
