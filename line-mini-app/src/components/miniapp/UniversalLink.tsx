'use client'

import { useCallback, type ReactNode } from 'react'
import Link from 'next/link'
import { useRouter } from 'next/navigation'
import type { UniversalLink as ULink } from '@/types/miniapp-home'

interface UniversalLinkProps {
  link: Omit<ULink, 'label'> & { label?: string }
  children: ReactNode
  className?: string
  style?: React.CSSProperties
}

export function UniversalLink({ link, children, className, style }: UniversalLinkProps) {
  const router = useRouter()

  const handleExternalClick = useCallback(() => {
    const liff = (window as unknown as { liff?: { openWindow?: (opts: { url: string; external?: boolean }) => void } }).liff

    switch (link.type) {
      case 'url':
        if (liff?.openWindow) {
          liff.openWindow({ url: link.value, external: false })
        } else {
          window.open(link.value, '_blank', 'noopener')
        }
        break

      case 'liff':
        if (liff?.openWindow) {
          liff.openWindow({ url: `https://liff.line.me/${link.value}`, external: false })
        } else {
          window.open(`https://liff.line.me/${link.value}`, '_blank')
        }
        break

      case 'line_chat': {
        const url = link.value || 'https://line.me/R/oaMessage/@reya'
        if (liff?.openWindow) {
          liff.openWindow({ url, external: true })
        } else {
          window.location.href = url
        }
        break
      }

      case 'deep_link':
        window.location.href = link.value
        break

      default:
        break
    }
  }, [link])

  if (link.type === 'none' || !link.value) {
    return <div className={className} style={style}>{children}</div>
  }

  if (link.type === 'miniapp') {
    return (
      <Link href={link.value} className={className} style={style}>
        {children}
      </Link>
    )
  }

  return (
    <button
      type="button"
      onClick={handleExternalClick}
      className={className}
      style={{ ...style, cursor: 'pointer', background: 'none', border: 'none', padding: 0, textAlign: 'inherit', width: '100%' }}
    >
      {children}
    </button>
  )
}
