'use client'

import Link from 'next/link'
import { usePathname } from 'next/navigation'
import { Home, Package, ShoppingCart, Store, UserRound } from 'lucide-react'
import { useQuery } from '@tanstack/react-query'
import { cn } from '@/lib/utils'
import { useLineContext } from '@/components/providers'
import { fetchCart } from '@/lib/shop-api'

const items = [
  { href: '/', label: 'หน้าหลัก', icon: Home },
  { href: '/shop', label: 'ร้านค้า', icon: Store },
  { href: '/cart', label: 'ตะกร้า', icon: ShoppingCart },
  { href: '/orders', label: 'ออเดอร์', icon: Package },
  { href: '/profile', label: 'โปรไฟล์', icon: UserRound }
]

export function BottomNav() {
  const pathname = usePathname()
  const line = useLineContext()
  const lineUserId = line.profile?.userId || ''

  const cartQuery = useQuery({
    queryKey: ['shop-cart', lineUserId],
    queryFn: () => fetchCart(lineUserId),
    enabled: Boolean(lineUserId),
    staleTime: 30_000
  })
  const cartCount = cartQuery.data?.item_count ?? cartQuery.data?.items?.length ?? 0

  return (
    <nav className="shrink-0 border-t border-slate-100 bg-white/95 backdrop-blur-xl safe-bottom">
      <div className="mx-auto flex max-w-md items-center justify-around px-1 pt-1">
        {items.map(({ href, label, icon: Icon }) => {
          const active =
            href === '/'
              ? pathname === '/' || pathname === ''
              : pathname === href || pathname.startsWith(`${href}/`)

          return (
            <Link
              key={href}
              href={href}
              className={cn(
                'relative flex min-w-0 flex-1 flex-col items-center gap-0.5 rounded-2xl px-1 py-2 text-[10px] font-medium transition-all duration-200 sm:text-[11px]',
                active ? 'text-line' : 'text-slate-400 hover:text-slate-600'
              )}
            >
              <div
                className={cn(
                  'flex h-8 w-8 items-center justify-center rounded-xl transition-all duration-200',
                  active ? 'bg-line-soft scale-110' : ''
                )}
              >
                {href === '/cart' ? (
                  <div className="relative">
                    <Icon size={18} strokeWidth={active ? 2.5 : 2} />
                    {cartCount > 0 ? (
                      <span className="absolute -right-1.5 -top-1.5 flex h-4 w-4 items-center justify-center rounded-full bg-rose-500 text-[9px] font-bold text-white leading-none">
                        {cartCount > 9 ? '9+' : cartCount}
                      </span>
                    ) : null}
                  </div>
                ) : (
                  <Icon size={18} strokeWidth={active ? 2.5 : 2} />
                )}
              </div>
              <span className={cn('truncate', active && 'font-semibold')}>{label}</span>
            </Link>
          )
        })}
      </div>
    </nav>
  )
}
