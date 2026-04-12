import type { Pharmacist } from '@/types/pharmacist'
import { Star, Video } from 'lucide-react'

interface PharmacistCardProps {
  pharmacist: Pharmacist
  onBook?: (id: number) => void
}

export function PharmacistCard({ pharmacist, onBook }: PharmacistCardProps) {
  return (
    <div className="bg-white rounded-xl p-3 shadow-sm flex items-center gap-3">
      <div className="w-12 h-12 rounded-full bg-gray-100 overflow-hidden shrink-0">
        {pharmacist.image_url ? (
          <img src={pharmacist.image_url} alt={pharmacist.name} className="w-full h-full object-cover" />
        ) : (
          <div className="w-full h-full flex items-center justify-center text-gray-400 text-lg font-bold">
            {pharmacist.name.charAt(0)}
          </div>
        )}
      </div>
      <div className="flex-1 min-w-0">
        <p className="text-sm font-medium text-gray-900 truncate">{pharmacist.name}</p>
        <div className="flex items-center gap-2 mt-0.5">
          {pharmacist.is_online && <span className="w-2 h-2 bg-green-400 rounded-full" />}
          {pharmacist.rating && (
            <span className="flex items-center gap-0.5 text-[10px] text-amber-500">
              <Star className="w-3 h-3 fill-amber-400" /> {pharmacist.rating.toFixed(1)}
            </span>
          )}
        </div>
      </div>
      {onBook && (
        <button onClick={() => onBook(pharmacist.id)} className="bg-primary/10 text-primary p-2 rounded-xl">
          <Video className="w-4 h-4" />
        </button>
      )}
    </div>
  )
}
