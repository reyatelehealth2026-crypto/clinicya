import { useNavigate } from 'react-router-dom'
import { useLiff } from '@/providers/LiffProvider'
import { useAuthStore } from '@/stores/useAuthStore'
import { useMember } from '@/hooks/useMember'
import { formatNumber } from '@/lib/format'
import { Card } from '@/components/ui/Card'
import { ChevronRight, Heart, Bell, FileText, Shield, LogOut } from 'lucide-react'

const menuItems = [
  { icon: Heart, label: 'รายการโปรด', path: '/wishlist' },
  { icon: Bell, label: 'การแจ้งเตือน', path: '/notifications' },
  { icon: FileText, label: 'ข้อมูลสุขภาพ', path: '/health-profile' },
  { icon: Shield, label: 'นโยบายความเป็นส่วนตัว', path: '' },
]

export function ProfilePage() {
  const navigate = useNavigate()
  const { profile, logout } = useLiff()
  const member = useAuthStore((s) => s.member)
  useMember()

  return (
    <div className="pb-4">
      <div className="bg-white px-4 pt-3 pb-5 safe-top">
        <h1 className="text-base font-semibold text-gray-900 mb-4">โปรไฟล์</h1>
        <div className="flex items-center gap-4">
          <div className="w-16 h-16 rounded-full bg-gray-200 overflow-hidden">
            {profile?.pictureUrl ? <img src={profile.pictureUrl} alt="" className="w-full h-full object-cover" /> : <div className="w-full h-full flex items-center justify-center text-gray-400 text-2xl font-bold">{profile?.displayName?.charAt(0) || '?'}</div>}
          </div>
          <div>
            <h2 className="text-base font-semibold text-gray-900">{profile?.displayName || 'Guest'}</h2>
            {member && <p className="text-xs text-gray-500 mt-0.5">{member.phone || member.email || ''}</p>}
          </div>
        </div>
        {member && (
          <div className="grid grid-cols-3 gap-3 mt-4">
            <div className="text-center bg-gray-50 rounded-xl py-2.5"><p className="text-lg font-bold text-primary">{formatNumber(member.points)}</p><p className="text-[10px] text-gray-500">คะแนน</p></div>
            <div className="text-center bg-gray-50 rounded-xl py-2.5"><p className="text-lg font-bold text-gray-900">{member.tier}</p><p className="text-[10px] text-gray-500">ระดับ</p></div>
            <div className="text-center bg-gray-50 rounded-xl py-2.5 cursor-pointer" onClick={() => navigate('/orders')}><p className="text-lg font-bold text-gray-900">ดู</p><p className="text-[10px] text-gray-500">ออเดอร์</p></div>
          </div>
        )}
      </div>
      <div className="p-4 space-y-2">
        {menuItems.map((item) => (
          <Card key={item.label} className="flex items-center gap-3 cursor-pointer active:scale-[0.99] transition-transform" onClick={() => item.path && navigate(item.path)}>
            <item.icon className="w-5 h-5 text-gray-400" />
            <span className="flex-1 text-sm text-gray-700">{item.label}</span>
            <ChevronRight className="w-4 h-4 text-gray-300" />
          </Card>
        ))}
        <Card className="flex items-center gap-3 cursor-pointer text-red-500" onClick={logout}>
          <LogOut className="w-5 h-5" />
          <span className="flex-1 text-sm font-medium">ออกจากระบบ</span>
        </Card>
      </div>
    </div>
  )
}
