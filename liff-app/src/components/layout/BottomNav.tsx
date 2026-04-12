import { useLocation, useNavigate } from 'react-router-dom'
import { Home, ShoppingBag, ClipboardList, CreditCard, User } from 'lucide-react'
import { clsx } from 'clsx'

const tabs = [
  { path: '/', icon: Home, label: 'หน้าแรก' },
  { path: '/shop', icon: ShoppingBag, label: 'ร้านค้า' },
  { path: '/orders', icon: ClipboardList, label: 'ออเดอร์' },
  { path: '/member', icon: CreditCard, label: 'สมาชิก' },
  { path: '/profile', icon: User, label: 'โปรไฟล์' },
]

export function BottomNav() {
  const location = useLocation()
  const navigate = useNavigate()

  return (
    <nav className="fixed bottom-0 left-0 right-0 bg-white border-t border-gray-100 safe-bottom z-50">
      <div className="flex items-center justify-around max-w-lg mx-auto">
        {tabs.map((tab) => {
          const active = tab.path === '/' ? location.pathname === '/' : location.pathname.startsWith(tab.path)
          return (
            <button
              key={tab.path}
              onClick={() => navigate(tab.path)}
              className={clsx('flex flex-col items-center py-2 px-3 min-w-0', active ? 'text-primary' : 'text-gray-400')}
            >
              <tab.icon className="w-5 h-5" />
              <span className="text-[10px] mt-0.5 font-medium">{tab.label}</span>
            </button>
          )
        })}
      </div>
    </nav>
  )
}
