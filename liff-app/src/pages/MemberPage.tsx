import { useNavigate } from 'react-router-dom'
import { PageHeader } from '@/components/layout/PageHeader'
import { MemberCard } from '@/components/shared/MemberCard'
import { Card } from '@/components/ui/Card'
import { useMember } from '@/hooks/useMember'
import { useAuthStore } from '@/stores/useAuthStore'
import { formatNumber, formatDate } from '@/lib/format'
import { Award, TrendingUp, Gift, History } from 'lucide-react'

export function MemberPage() {
  const navigate = useNavigate()
  useMember()
  const member = useAuthStore((s) => s.member)
  const tier = useAuthStore((s) => s.tier)

  return (
    <div className="pb-4">
      <PageHeader title="บัตรสมาชิก" />
      <div className="p-4 space-y-4">
        <MemberCard />
        {tier && (
          <Card>
            <div className="flex items-center gap-2 mb-3"><Award className="w-4 h-4 text-primary" /><h3 className="text-sm font-semibold text-gray-900">ระดับสมาชิก</h3></div>
            <div className="grid grid-cols-2 gap-3 text-center">
              <div className="bg-gray-50 rounded-xl p-3"><p className="text-xs text-gray-500">ระดับปัจจุบัน</p><p className="text-base font-bold text-primary mt-0.5">{tier.name}</p></div>
              <div className="bg-gray-50 rounded-xl p-3"><p className="text-xs text-gray-500">ระดับถัดไป</p><p className="text-base font-bold text-gray-700 mt-0.5">{tier.next_tier_name || '-'}</p></div>
              <div className="bg-gray-50 rounded-xl p-3"><p className="text-xs text-gray-500">คะแนนขั้นต่ำ</p><p className="text-base font-bold text-gray-700 mt-0.5">{formatNumber(tier.min_points)}</p></div>
              <div className="bg-gray-50 rounded-xl p-3"><p className="text-xs text-gray-500">อีก</p><p className="text-base font-bold text-gray-700 mt-0.5">{formatNumber(Math.max(0, (tier.next_tier_points || 0) - (member?.points || 0)))} pt</p></div>
            </div>
          </Card>
        )}
        <div className="grid grid-cols-2 gap-3">
          <button onClick={() => navigate('/points')} className="bg-white rounded-xl p-4 shadow-sm flex items-center gap-3 active:scale-95 transition-transform"><div className="w-10 h-10 rounded-xl bg-blue-100 flex items-center justify-center"><History className="w-5 h-5 text-blue-600" /></div><div className="text-left"><p className="text-sm font-semibold text-gray-900">ประวัติแต้ม</p><p className="text-xs text-gray-500">{formatNumber(member?.points || 0)} pt</p></div></button>
          <button onClick={() => navigate('/redeem')} className="bg-white rounded-xl p-4 shadow-sm flex items-center gap-3 active:scale-95 transition-transform"><div className="w-10 h-10 rounded-xl bg-amber-100 flex items-center justify-center"><Gift className="w-5 h-5 text-amber-600" /></div><div className="text-left"><p className="text-sm font-semibold text-gray-900">แลกแต้ม</p><p className="text-xs text-gray-500">ดูของรางวัล</p></div></button>
        </div>
        {member && (
          <Card>
            <div className="flex items-center gap-2 mb-3"><TrendingUp className="w-4 h-4 text-primary" /><h3 className="text-sm font-semibold text-gray-900">ข้อมูลสมาชิก</h3></div>
            <div className="space-y-2 text-sm">
              {member.phone && <div className="flex justify-between"><span className="text-gray-500">โทร</span><span>{member.phone}</span></div>}
              {member.email && <div className="flex justify-between"><span className="text-gray-500">อีเมล</span><span>{member.email}</span></div>}
              <div className="flex justify-between"><span className="text-gray-500">สมัครเมื่อ</span><span>{formatDate(member.created_at)}</span></div>
              {member.expiry_date && <div className="flex justify-between"><span className="text-gray-500">หมดอายุ</span><span>{formatDate(member.expiry_date)}</span></div>}
            </div>
          </Card>
        )}
      </div>
    </div>
  )
}
