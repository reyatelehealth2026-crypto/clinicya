import { useNavigate } from 'react-router-dom'
import { ShoppingBag, ShoppingCart, ClipboardList, Bot, Calendar, Gift } from 'lucide-react'

const services = [
  { path: '/shop', icon: ShoppingBag, label: 'ร้านค้า', color: 'bg-blue-100 text-blue-600' },
  { path: '/cart', icon: ShoppingCart, label: 'ตะกร้า', color: 'bg-green-100 text-green-600' },
  { path: '/orders', icon: ClipboardList, label: 'ออเดอร์', color: 'bg-orange-100 text-orange-600' },
  { path: '/ai-chat', icon: Bot, label: 'AI ช่วย', color: 'bg-purple-100 text-purple-600' },
  { path: '/appointments', icon: Calendar, label: 'นัดหมาย', color: 'bg-pink-100 text-pink-600' },
  { path: '/redeem', icon: Gift, label: 'แลกแต้ม', color: 'bg-amber-100 text-amber-600' },
]

export function ServiceGrid() {
  const navigate = useNavigate()
  return (
    <div className="grid grid-cols-3 gap-3">
      {services.map((s) => (
        <button key={s.path} onClick={() => navigate(s.path)} className="flex flex-col items-center gap-2 py-3 bg-white rounded-xl shadow-sm active:scale-95 transition-transform">
          <div className={`w-10 h-10 rounded-xl flex items-center justify-center ${s.color}`}>
            <s.icon className="w-5 h-5" />
          </div>
          <span className="text-xs font-medium text-gray-700">{s.label}</span>
        </button>
      ))}
    </div>
  )
}
