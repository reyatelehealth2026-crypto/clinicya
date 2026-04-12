'use client'

import { useLineContext } from '@/components/providers'
import type { OdooCustomerProfile } from '@/types/odoo-profile'

type MiniAppHeaderProps = {
  title?: string
  subtitle?: string
  showAvatar?: boolean
  odooProfile?: OdooCustomerProfile | null
}

export function MiniAppHeader({ title, subtitle, showAvatar = true, odooProfile }: MiniAppHeaderProps) {
  const line = useLineContext()
  const avatar = line.profile?.pictureUrl
  const lineName = line.profile?.displayName

  // Compact style when odooProfile is provided
  if (odooProfile) {
    return (
      <header className="shrink-0 safe-top bg-white border-b border-slate-100">
        <div className="mx-auto max-w-md px-4 py-3">
          <div className="flex items-center gap-3">
            {/* Logo */}
            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-line/10 shrink-0">
              <img src="/img/logo-system-fav.png" alt="logo" className="h-8 w-8 object-contain" />
            </div>
            {/* Name and Phone */}
            <div className="flex-1 min-w-0">
              <p className="text-sm font-semibold text-slate-900 truncate">
                {odooProfile.partner_name || lineName || 'สมาชิก'}
              </p>
              {odooProfile.phone && (
                <p className="text-xs text-slate-500">
                  โทร {odooProfile.phone}
                </p>
              )}
            </div>
          </div>
        </div>
      </header>
    )
  }

  // Default gradient style (fallback)
  return (
    <header className="shrink-0 safe-top gradient-card px-5 pb-7 text-white">
      <div className="mx-auto max-w-md">
        {showAvatar && (avatar || lineName) ? (
          <div className="mb-4 flex items-center gap-3">
            {avatar ? (
              <img src={avatar} alt="" className="h-9 w-9 rounded-full border-2 border-white/30 object-cover" />
            ) : (
              <div className="flex h-9 w-9 items-center justify-center rounded-full border-2 border-white/30 bg-white/20 text-sm font-bold">
                {lineName?.charAt(0) || 'U'}
              </div>
            )}
            <span className="text-sm font-medium text-white/90">{lineName || 'LINE User'}</span>
          </div>
        ) : null}
        {title && <h1 className="text-2xl font-bold tracking-tight">{title}</h1>}
        {subtitle ? <p className="mt-1.5 text-sm leading-relaxed text-white/75">{subtitle}</p> : null}
      </div>
    </header>
  )
}
