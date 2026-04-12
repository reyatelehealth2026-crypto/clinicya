import { useNavigate } from 'react-router-dom'
import { Bell, Bot } from 'lucide-react'
import { MemberCard } from '@/components/shared/MemberCard'
import { ServiceGrid } from '@/components/shared/ServiceGrid'
import { PharmacistCard } from '@/components/shared/PharmacistCard'
import { CartSummaryBar } from '@/components/shared/CartSummaryBar'
import { useMember } from '@/hooks/useMember'
import { usePharmacists } from '@/hooks/usePharmacists'

export function HomePage() {
  const navigate = useNavigate()
  useMember()
  const { data: pharmacists } = usePharmacists()
  const onlinePharmacists = pharmacists?.filter((p) => p.is_online).slice(0, 3) || []

  return (
    <div className="pb-20">
      <div className="bg-white px-4 pt-3 pb-4 flex items-center justify-between safe-top">
        <h1 className="text-lg font-bold text-gray-900">Re-Ya Pharmacy</h1>
        <div className="flex items-center gap-2">
          <button onClick={() => navigate('/notifications')} className="p-2 rounded-full hover:bg-gray-100"><Bell className="w-5 h-5 text-gray-600" /></button>
        </div>
      </div>
      <div className="px-4 space-y-4 mt-2">
        <MemberCard />
        <ServiceGrid />
        <button onClick={() => navigate('/ai-chat')} className="w-full bg-gradient-to-r from-purple-500 to-indigo-500 text-white rounded-xl p-4 flex items-center gap-3 active:scale-[0.98] transition-transform">
          <Bot className="w-8 h-8" />
          <div className="text-left"><p className="font-semibold text-sm">ปรึกษาเภสัชกร AI</p><p className="text-xs opacity-80">ถามเรื่องยาได้ 24 ชม.</p></div>
        </button>
        {onlinePharmacists.length > 0 && (
          <div>
            <h2 className="text-sm font-semibold text-gray-900 mb-2">เภสัชกรออนไลน์</h2>
            <div className="space-y-2">
              {onlinePharmacists.map((p) => <PharmacistCard key={p.id} pharmacist={p} onBook={(id) => navigate(`/video-call/${id}`)} />)}
            </div>
          </div>
        )}
      </div>
      <CartSummaryBar />
    </div>
  )
}
