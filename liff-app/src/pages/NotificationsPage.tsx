import { useState } from 'react'
import { PageHeader } from '@/components/layout/PageHeader'
import { Card } from '@/components/ui/Card'
import { Bell, Package, Gift, Megaphone, Trash2 } from 'lucide-react'

interface Notification { id: number; type: 'order' | 'promo' | 'points' | 'system'; title: string; message: string; time: string; read: boolean }
const iconMap = { order: Package, promo: Megaphone, points: Gift, system: Bell }
const colorMap = { order: 'bg-blue-100 text-blue-600', promo: 'bg-amber-100 text-amber-600', points: 'bg-green-100 text-green-600', system: 'bg-gray-100 text-gray-600' }

export function NotificationsPage() {
  const [notifications, setNotifications] = useState<Notification[]>([])
  const markAllRead = () => setNotifications((prev) => prev.map((n) => ({ ...n, read: true })))
  const remove = (id: number) => setNotifications((prev) => prev.filter((n) => n.id !== id))

  return (
    <div className="pb-4">
      <PageHeader title="การแจ้งเตือน" rightAction={notifications.some((n) => !n.read) ? <button onClick={markAllRead} className="text-xs text-primary font-medium">อ่านทั้งหมด</button> : undefined} />
      <div className="p-4">
        {notifications.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-16 text-gray-400"><Bell className="w-12 h-12 mb-3" /><p className="text-sm font-medium">ไม่มีการแจ้งเตือน</p></div>
        ) : (
          <div className="space-y-2">{notifications.map((n) => { const Icon = iconMap[n.type]; return (
            <Card key={n.id} className={`flex gap-3 ${!n.read ? 'border-l-2 border-l-primary' : ''}`}>
              <div className={`w-10 h-10 rounded-xl flex items-center justify-center shrink-0 ${colorMap[n.type]}`}><Icon className="w-5 h-5" /></div>
              <div className="flex-1 min-w-0"><div className="flex items-start justify-between gap-2"><h4 className={`text-sm ${!n.read ? 'font-semibold text-gray-900' : 'font-medium text-gray-700'}`}>{n.title}</h4><button onClick={() => remove(n.id)} className="shrink-0 text-gray-300 hover:text-gray-500"><Trash2 className="w-3.5 h-3.5" /></button></div><p className="text-xs text-gray-500 mt-0.5 line-clamp-2">{n.message}</p><p className="text-[10px] text-gray-400 mt-1">{n.time}</p></div>
            </Card>
          ) })}</div>
        )}
      </div>
    </div>
  )
}
