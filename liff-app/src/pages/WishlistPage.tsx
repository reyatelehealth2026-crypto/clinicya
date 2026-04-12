import { PageHeader } from '@/components/layout/PageHeader'
import { Heart } from 'lucide-react'

export function WishlistPage() {
  return (
    <div className="pb-4">
      <PageHeader title="รายการโปรด" />
      <div className="flex flex-col items-center justify-center py-16 px-4 text-gray-400">
        <Heart className="w-12 h-12 mb-3" /><p className="text-sm font-medium">ยังไม่มีรายการโปรด</p><p className="text-xs text-gray-400 mt-1">กดหัวใจที่สินค้าเพื่อเพิ่มเข้ารายการ</p>
      </div>
    </div>
  )
}
