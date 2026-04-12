import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { PageHeader } from '@/components/layout/PageHeader'
import { Card } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Skeleton } from '@/components/ui/Skeleton'
import { useAppointments } from '@/hooks/useAppointments'
import { CalendarCheck, Clock, Video, Plus } from 'lucide-react'

type AppointmentStatus = 'upcoming' | 'completed' | 'cancelled'
const statusBadge: Record<AppointmentStatus, { variant: 'info' | 'success' | 'danger'; label: string }> = { upcoming: { variant: 'info', label: 'กำลังจะมาถึง' }, completed: { variant: 'success', label: 'เสร็จสิ้น' }, cancelled: { variant: 'danger', label: 'ยกเลิก' } }

export function AppointmentsPage() {
  const navigate = useNavigate()
  const [filter, setFilter] = useState<AppointmentStatus | 'all'>('all')
  const { data: appointments = [], isLoading } = useAppointments()
  const filtered = appointments.filter((a) => filter === 'all' || a.status === filter)

  return (
    <div className="pb-4">
      <PageHeader title="นัดหมาย" rightAction={<button onClick={() => navigate('/video-call')} className="p-1 rounded-full hover:bg-gray-100"><Plus className="w-5 h-5 text-primary" /></button>} />
      <div className="bg-white px-4 py-3 border-b border-gray-100"><div className="flex gap-2">
        {[{ key: 'all' as const, label: 'ทั้งหมด' }, { key: 'upcoming' as const, label: 'กำลังจะถึง' }, { key: 'completed' as const, label: 'เสร็จสิ้น' }].map((f) => (
          <button key={f.key} onClick={() => setFilter(f.key)} className={`px-3 py-1.5 rounded-full text-xs font-medium transition-colors ${filter === f.key ? 'bg-primary text-white' : 'bg-gray-100 text-gray-600'}`}>{f.label}</button>
        ))}
      </div></div>
      <div className="p-4">
        {isLoading ? <div className="space-y-3">{[1, 2].map((i) => <Card key={i}><Skeleton height="48px" /></Card>)}</div>
        : filtered.length === 0 ? <div className="flex flex-col items-center justify-center py-16 text-gray-400"><CalendarCheck className="w-12 h-12 mb-3" /><p className="text-sm font-medium">ไม่มีนัดหมาย</p><Button variant="outline" size="sm" className="mt-3" onClick={() => navigate('/video-call')}>นัดหมายเภสัชกร</Button></div>
        : <div className="space-y-3">{filtered.map((apt) => (
          <Card key={apt.id} className="flex items-center gap-3"><div className="w-10 h-10 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">{apt.type === 'video' ? <Video className="w-5 h-5 text-primary" /> : <Clock className="w-5 h-5 text-primary" />}</div><div className="flex-1 min-w-0"><div className="flex items-center justify-between gap-2"><h4 className="text-sm font-medium text-gray-900 truncate">{apt.pharmacist_name}</h4><Badge variant={statusBadge[apt.status].variant}>{statusBadge[apt.status].label}</Badge></div><p className="text-xs text-gray-500 mt-0.5">{apt.date} · {apt.time}</p></div></Card>
        ))}</div>}
      </div>
    </div>
  )
}
