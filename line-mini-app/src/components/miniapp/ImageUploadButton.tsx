'use client'

/**
 * ImageUploadButton — file-picker button for the AI chat input bar.
 *
 * Captures an image from the user (camera or gallery), uploads it to
 * `/api/ai-chat-vision.php`, and hands the Thai description back to the parent
 * via `onImageDescribed`. The parent (AIChatClient) is then responsible for
 * inserting a user message and continuing the chat flow.
 *
 * Standalone by design: no awareness of AIChatClient internals. Mount it into
 * the slot Phase 3 reserves (`<div id="ai-chat-input-slot" />`).
 */

import { useRef, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Camera, Loader2 } from 'lucide-react'
import { apiUrl } from '@/lib/config'
import { useToast } from '@/lib/toast'

interface VisionResponse {
  success: boolean
  description?: string
  image_url?: string
  error?: string
}

interface UploadArgs {
  file: File
  lineUserId: string
  lineAccountId?: number
}

export interface ImageUploadButtonProps {
  /** LINE user id of the current customer — required for backend auth. */
  lineUserId: string
  /** Optional LINE OA scope passed through to the vision endpoint. */
  lineAccountId?: number
  /**
   * Called with the AI description on a successful upload. The parent should
   * insert a user message (e.g. `[รูปภาพ] {description}`) and trigger sending.
   */
  onImageDescribed: (description: string, imageUrl?: string) => void
  /** Disable the button (e.g. while the chat stream is in flight). */
  disabled?: boolean
  /** Optional aria label override. */
  ariaLabel?: string
}

async function uploadImage({ file, lineUserId, lineAccountId }: UploadArgs): Promise<VisionResponse> {
  const formData = new FormData()
  formData.append('image', file)
  formData.append('line_user_id', lineUserId)
  if (typeof lineAccountId === 'number' && Number.isFinite(lineAccountId)) {
    formData.append('line_account_id', String(lineAccountId))
  }
  const res = await fetch(apiUrl('/api/ai-chat-vision.php'), {
    method: 'POST',
    body: formData,
  })
  let json: VisionResponse | null = null
  try {
    json = (await res.json()) as VisionResponse
  } catch {
    json = null
  }
  if (!res.ok || !json || !json.success) {
    const message = json?.error || `อัพโหลดรูปไม่สำเร็จ (HTTP ${res.status})`
    throw new Error(message)
  }
  return json
}

export function ImageUploadButton({
  lineUserId,
  lineAccountId,
  onImageDescribed,
  disabled,
  ariaLabel = 'อัพโหลดรูปภาพ',
}: ImageUploadButtonProps) {
  const inputRef = useRef<HTMLInputElement | null>(null)
  const [isProcessing, setIsProcessing] = useState(false)
  const { toast } = useToast()

  const mutation = useMutation({
    mutationFn: uploadImage,
    onSuccess: (data) => {
      setIsProcessing(false)
      const description = (data.description ?? '').trim()
      if (description === '') {
        toast.warning('AI อ่านรูปไม่ออก ลองถ่ายใหม่ในแสงที่ดีกว่าค่ะ')
        return
      }
      toast.success('AI อ่านรูปเสร็จแล้ว')
      onImageDescribed(description, data.image_url)
    },
    onError: (err: unknown) => {
      setIsProcessing(false)
      const message = err instanceof Error ? err.message : 'อัพโหลดรูปไม่สำเร็จ'
      toast.error(message)
    },
  })

  function handleClick() {
    if (disabled || isProcessing || !lineUserId) {
      if (!lineUserId) {
        toast.error('ต้อง login LINE ก่อนถึงจะส่งรูปได้')
      }
      return
    }
    inputRef.current?.click()
  }

  function handleFileChange(event: React.ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0]
    // Reset value so picking the same file twice still triggers change.
    if (event.target) {
      event.target.value = ''
    }
    if (!file) {
      return
    }

    const allowedTypes = ['image/jpeg', 'image/png', 'image/webp']
    if (!allowedTypes.includes(file.type)) {
      toast.error('รองรับเฉพาะไฟล์ JPG / PNG / WebP')
      return
    }
    const maxBytes = 5 * 1024 * 1024
    if (file.size > maxBytes) {
      toast.error('ไฟล์ใหญ่เกิน 5MB')
      return
    }

    setIsProcessing(true)
    mutation.mutate({ file, lineUserId, lineAccountId })
  }

  const buttonDisabled = disabled || isProcessing
  const Icon = isProcessing ? Loader2 : Camera

  return (
    <>
      <input
        ref={inputRef}
        type="file"
        accept="image/jpeg,image/png,image/webp"
        capture="environment"
        className="hidden"
        onChange={handleFileChange}
        aria-hidden="true"
        tabIndex={-1}
      />
      <button
        type="button"
        onClick={handleClick}
        disabled={buttonDisabled}
        aria-label={ariaLabel}
        title={ariaLabel}
        className={[
          'inline-flex h-10 w-10 items-center justify-center rounded-full',
          'border border-emerald-200 bg-white text-emerald-600 shadow-sm',
          'transition-colors hover:bg-emerald-50 active:scale-95',
          'disabled:cursor-not-allowed disabled:opacity-50',
          'font-noto-sans-thai',
        ].join(' ')}
      >
        <Icon className={isProcessing ? 'h-5 w-5 animate-spin' : 'h-5 w-5'} aria-hidden="true" />
      </button>
    </>
  )
}

export default ImageUploadButton
