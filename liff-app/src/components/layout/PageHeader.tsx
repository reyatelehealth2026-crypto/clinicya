import { useNavigate } from 'react-router-dom'
import { ChevronLeft } from 'lucide-react'
import type { ReactNode } from 'react'

interface PageHeaderProps {
  title: string
  showBack?: boolean
  rightAction?: ReactNode
}

export function PageHeader({ title, showBack = true, rightAction }: PageHeaderProps) {
  const navigate = useNavigate()

  return (
    <div className="bg-white px-4 py-3 flex items-center gap-3 border-b border-gray-100 sticky top-0 z-40 safe-top">
      {showBack && (
        <button onClick={() => navigate(-1)} className="p-1 -ml-1 rounded-full hover:bg-gray-100">
          <ChevronLeft className="w-5 h-5 text-gray-600" />
        </button>
      )}
      <h1 className="text-base font-semibold text-gray-900 flex-1 truncate">{title}</h1>
      {rightAction}
    </div>
  )
}
