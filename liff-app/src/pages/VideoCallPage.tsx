import { useNavigate, useParams } from 'react-router-dom'
import { PageHeader } from '@/components/layout/PageHeader'
import { Card } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { usePharmacists } from '@/hooks/usePharmacists'
import { PharmacistCard } from '@/components/shared/PharmacistCard'
import { Skeleton } from '@/components/ui/Skeleton'
import { Video, Phone } from 'lucide-react'

export function VideoCallPage() {
  const navigate = useNavigate()
  const { id } = useParams<{ id: string }>()
  const { data: pharmacists, isLoading } = usePharmacists()

  if (id) {
    const pharm = pharmacists?.find((p) => p.id === Number(id))
    return (<div className="pb-4"><PageHeader title="นัดหมายเภสัชกร" /><div className="p-4 space-y-4">{pharm ? <><PharmacistCard pharmacist={pharm} /><Card className="text-center py-8"><Video className="w-12 h-12 text-primary mx-auto mb-3" /><h3 className="text-base font-semibold text-gray-900">Video Call</h3><p className="text-sm text-gray-500 mt-1 mb-4">เริ่มปรึกษาเภสัชกรผ่านวิดีโอคอล</p><div className="flex gap-3 justify-center"><Button icon={<Phone className="w-4 h-4" />} onClick={() => {}}>โทร</Button><Button variant="secondary" icon={<Video className="w-4 h-4" />} onClick={() => {}}>วิดีโอ</Button></div></Card></> : <Card className="text-center py-8 text-gray-400"><p className="text-sm">ไม่พบเภสัชกร</p></Card>}</div></div>)
  }

  return (
    <div className="pb-4"><PageHeader title="ปรึกษาเภสัชกร" /><div className="p-4 space-y-3"><p className="text-sm text-gray-600 mb-2">เภสัชกรที่พร้อมให้บริการ</p>
      {isLoading ? Array.from({ length: 3 }).map((_, i) => <div key={i} className="bg-white rounded-xl p-3 flex items-center gap-3"><Skeleton className="w-12 h-12" rounded /><div className="flex-1 space-y-2"><Skeleton height="14px" className="w-32" /><Skeleton height="12px" className="w-20" /></div></div>)
      : pharmacists && pharmacists.length > 0 ? pharmacists.map((p) => <PharmacistCard key={p.id} pharmacist={p} onBook={(pid) => navigate(`/video-call/${pid}`)} />)
      : <Card className="text-center py-12"><Video className="w-10 h-10 text-gray-300 mx-auto mb-2" /><p className="text-sm text-gray-400">ไม่มีเภสัชกรออนไลน์</p></Card>}
    </div></div>
  )
}
