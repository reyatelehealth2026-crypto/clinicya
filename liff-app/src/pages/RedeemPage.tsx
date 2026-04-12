import { useState } from 'react'
import { PageHeader } from '@/components/layout/PageHeader'
import { Card } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Modal } from '@/components/ui/Modal'
import { Skeleton } from '@/components/ui/Skeleton'
import { useAuthStore } from '@/stores/useAuthStore'
import { formatNumber } from '@/lib/format'
import { showToast } from '@/components/ui/Toast'
import { useRewards, useRedeemReward, type Reward } from '@/hooks/useRewards'
import { Gift } from 'lucide-react'

export function RedeemPage() {
  const myPoints = useAuthStore((s) => s.member)?.points || 0
  const [selected, setSelected] = useState<Reward | null>(null)
  const { data: rewards = [], isLoading } = useRewards()
  const redeemMutation = useRedeemReward()

  const handleRedeem = () => {
    if (!selected) return
    if (myPoints < selected.points_cost) { showToast('คะแนนไม่เพียงพอ', 'warning'); return }
    redeemMutation.mutate(selected.id, { onSuccess: () => { showToast(`แลก ${selected.name} สำเร็จ!`, 'success'); setSelected(null) }, onError: (err) => showToast(err.message, 'error') })
  }

  return (
    <div className="pb-4">
      <PageHeader title="แลกแต้ม" />
      <div className="bg-white px-4 py-4 text-center border-b border-gray-100"><p className="text-xs text-gray-500">คะแนนของคุณ</p><p className="text-2xl font-bold text-primary">{formatNumber(myPoints)} <span className="text-sm font-normal text-gray-400">pt</span></p></div>
      <div className="p-4">
        {isLoading ? <div className="grid grid-cols-2 gap-3">{[1, 2, 3, 4].map((i) => <Card key={i} padding={false}><Skeleton className="aspect-square w-full" /><div className="p-2.5 space-y-2"><Skeleton height="12px" /><Skeleton height="16px" className="w-1/2" /></div></Card>)}</div>
        : rewards.length === 0 ? <div className="flex flex-col items-center justify-center py-16 text-gray-400"><Gift className="w-12 h-12 mb-3" /><p className="text-sm font-medium">ยังไม่มีรางวัลในขณะนี้</p></div>
        : <div className="grid grid-cols-2 gap-3">{rewards.map((reward) => { const canRedeem = myPoints >= reward.points_cost; return (
          <Card key={reward.id} padding={false} className="overflow-hidden"><div className="aspect-square bg-gray-100 flex items-center justify-center">{reward.image_url ? <img src={reward.image_url} alt={reward.name} className="w-full h-full object-cover" /> : <Gift className="w-10 h-10 text-gray-300" />}</div><div className="p-2.5"><h3 className="text-xs font-medium text-gray-900 line-clamp-2">{reward.name}</h3><p className="text-sm font-bold text-primary mt-1">{formatNumber(reward.points_cost)} pt</p><button onClick={() => setSelected(reward)} disabled={!canRedeem} className={`mt-2 w-full text-xs py-1.5 rounded-lg font-medium ${canRedeem ? 'bg-primary text-white' : 'bg-gray-100 text-gray-400'}`}>{canRedeem ? 'แลกเลย' : 'แต้มไม่พอ'}</button></div></Card>
        ) })}</div>}
      </div>
      <Modal open={!!selected} onClose={() => setSelected(null)} title="ยืนยันการแลก">{selected && <div className="text-center"><div className="w-16 h-16 rounded-xl bg-primary/10 flex items-center justify-center mx-auto mb-3"><Gift className="w-8 h-8 text-primary" /></div><h3 className="text-base font-semibold text-gray-900">{selected.name}</h3><p className="text-sm text-gray-500 mt-1">{selected.description}</p><p className="text-lg font-bold text-primary mt-3">{formatNumber(selected.points_cost)} คะแนน</p><div className="flex gap-2 mt-4"><Button variant="outline" className="flex-1" onClick={() => setSelected(null)}>ยกเลิก</Button><Button className="flex-1" loading={redeemMutation.isPending} onClick={handleRedeem}>ยืนยัน</Button></div></div>}</Modal>
    </div>
  )
}
