import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { PageHeader } from '@/components/layout/PageHeader'
import { Card } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { showToast } from '@/components/ui/Toast'
import { useLiff } from '@/providers/LiffProvider'
import { useAppStore } from '@/stores/useAppStore'
import { apiClient } from '@/lib/api-client'
import { UserPlus } from 'lucide-react'

export function RegisterPage() {
  const navigate = useNavigate()
  const { profile } = useLiff()
  const accountId = useAppStore((s) => s.accountId)
  const [firstName, setFirstName] = useState('')
  const [lastName, setLastName] = useState('')
  const [phone, setPhone] = useState('')
  const [email, setEmail] = useState('')
  const [submitting, setSubmitting] = useState(false)

  const handleSubmit = async () => {
    if (!firstName || !phone) { showToast('กรุณากรอกชื่อและเบอร์โทร', 'warning'); return }
    setSubmitting(true)
    try {
      const res = await apiClient('/api/member.php', { method: 'POST', body: JSON.stringify({ action: 'register', line_user_id: profile?.userId, line_account_id: accountId, first_name: firstName, last_name: lastName, phone, email: email || undefined, display_name: profile?.displayName, picture_url: profile?.pictureUrl }) })
      if (!res.success) throw new Error(res.error || 'Registration failed')
      showToast('สมัครสมาชิกสำเร็จ!', 'success'); navigate('/')
    } catch (err: unknown) { showToast((err as Error).message || 'เกิดข้อผิดพลาด', 'error') } finally { setSubmitting(false) }
  }

  const ic = 'w-full px-3 py-2.5 bg-gray-50 rounded-xl text-sm border border-gray-200 focus:outline-none focus:ring-2 focus:ring-primary/30'
  return (
    <div className="pb-4">
      <PageHeader title="สมัครสมาชิก" />
      <div className="p-4 space-y-4">
        <div className="text-center py-4"><div className="w-16 h-16 rounded-full bg-primary/10 flex items-center justify-center mx-auto mb-3"><UserPlus className="w-8 h-8 text-primary" /></div><h2 className="text-lg font-bold text-gray-900">สมัครสมาชิก Re-Ya</h2><p className="text-sm text-gray-500 mt-1">สะสมแต้ม รับสิทธิพิเศษมากมาย</p></div>
        <Card>
          <div className="space-y-3">
            {profile && <div className="flex items-center gap-3 p-3 bg-green-50 rounded-xl mb-2">{profile.pictureUrl && <img src={profile.pictureUrl} alt="" className="w-10 h-10 rounded-full" />}<div><p className="text-sm font-medium text-gray-900">{profile.displayName}</p><p className="text-xs text-green-600">เชื่อมต่อ LINE แล้ว</p></div></div>}
            <div><label className="text-xs font-medium text-gray-500 mb-1 block">ชื่อ *</label><input type="text" value={firstName} onChange={(e) => setFirstName(e.target.value)} placeholder="ชื่อจริง" className={ic} /></div>
            <div><label className="text-xs font-medium text-gray-500 mb-1 block">นามสกุล</label><input type="text" value={lastName} onChange={(e) => setLastName(e.target.value)} placeholder="นามสกุล" className={ic} /></div>
            <div><label className="text-xs font-medium text-gray-500 mb-1 block">เบอร์โทร *</label><input type="tel" value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="0812345678" className={ic} /></div>
            <div><label className="text-xs font-medium text-gray-500 mb-1 block">อีเมล</label><input type="email" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="email@example.com" className={ic} /></div>
          </div>
        </Card>
        <Button className="w-full" size="lg" loading={submitting} onClick={handleSubmit}>สมัครสมาชิก</Button>
      </div>
    </div>
  )
}
