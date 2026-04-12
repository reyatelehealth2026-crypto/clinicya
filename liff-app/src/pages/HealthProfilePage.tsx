import { useState, useEffect } from 'react'
import { PageHeader } from '@/components/layout/PageHeader'
import { Card } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { showToast } from '@/components/ui/Toast'
import { useHealthProfile, useSaveHealthProfile } from '@/hooks/useHealthProfile'
import { AlertTriangle, Pill, HeartPulse, Save } from 'lucide-react'

export function HealthProfilePage() {
  const [allergies, setAllergies] = useState('')
  const [conditions, setConditions] = useState('')
  const [medications, setMedications] = useState('')
  const { data: existing } = useHealthProfile()
  const saveMutation = useSaveHealthProfile()

  useEffect(() => { if (existing) { if (existing.allergies) setAllergies(existing.allergies); if (existing.conditions) setConditions(existing.conditions); if (existing.medications) setMedications(existing.medications) } }, [existing])

  const handleSave = () => { saveMutation.mutate({ allergies, conditions, medications }, { onSuccess: () => showToast('บันทึกข้อมูลสุขภาพแล้ว', 'success'), onError: (err) => showToast(err.message, 'error') }) }
  const ta = 'w-full px-3 py-2.5 bg-gray-50 rounded-xl text-sm border border-gray-200 focus:outline-none focus:ring-2 focus:ring-primary/30 resize-none'

  return (
    <div className="pb-4"><PageHeader title="ข้อมูลสุขภาพ" />
      <div className="p-4 space-y-4">
        <div className="bg-amber-50 border border-amber-200 rounded-xl p-3 flex items-start gap-2"><AlertTriangle className="w-4 h-4 text-amber-500 shrink-0 mt-0.5" /><p className="text-xs text-amber-700">ข้อมูลนี้จะถูกแชร์กับเภสัชกรเมื่อคุณเข้ารับการปรึกษา เพื่อความปลอดภัยในการสั่งยา</p></div>
        <Card><div className="flex items-center gap-2 mb-3"><AlertTriangle className="w-4 h-4 text-red-500" /><h3 className="text-sm font-semibold text-gray-900">ประวัติแพ้ยา</h3></div><textarea value={allergies} onChange={(e) => setAllergies(e.target.value)} placeholder="เช่น แพ้เพนิซิลลิน, แพ้แอสไพริน" rows={3} className={ta} /></Card>
        <Card><div className="flex items-center gap-2 mb-3"><HeartPulse className="w-4 h-4 text-pink-500" /><h3 className="text-sm font-semibold text-gray-900">โรคประจำตัว</h3></div><textarea value={conditions} onChange={(e) => setConditions(e.target.value)} placeholder="เช่น เบาหวาน, ความดันโลหิตสูง" rows={3} className={ta} /></Card>
        <Card><div className="flex items-center gap-2 mb-3"><Pill className="w-4 h-4 text-blue-500" /><h3 className="text-sm font-semibold text-gray-900">ยาที่ทานประจำ</h3></div><textarea value={medications} onChange={(e) => setMedications(e.target.value)} placeholder="เช่น Metformin 500mg วันละ 2 ครั้ง" rows={3} className={ta} /></Card>
        <Button className="w-full" size="lg" loading={saveMutation.isPending} icon={<Save className="w-4 h-4" />} onClick={handleSave}>บันทึกข้อมูล</Button>
      </div>
    </div>
  )
}
