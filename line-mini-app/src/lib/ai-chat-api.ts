import { apiUrl } from '@/lib/config'
import type {
  ChatHistoryItem,
  AIChatStreamCallbacks,
  TriageStructuredPayload
} from '@/types/ai-chat'

/** ดึง payload หลัง prefix SSE `data:` — ไม่ใช้ JSON.parse กับทั้งบรรทัดที่มีคำว่า data: */
function parseSseDataLine(trimmed: string): { data: string } | null {
  const clean = trimmed.replace(/^\uFEFF/, '').trim()
  if (!clean.startsWith('data:')) return null
  const data = clean.replace(/^data:\s*/, '').trim()
  return { data }
}

/** ประมวลผลบรรทัด SSE หนึ่งบรรทัด (หลังตัด prefix แล้วค่อย JSON.parse) */
function handleSsePayload(
  data: string,
  callbacks: AIChatStreamCallbacks,
  safeComplete: () => void
): 'error' | 'done' | 'continue' {
  if (data === '[DONE]') {
    safeComplete()
    return 'done'
  }
  try {
    const parsed = JSON.parse(data) as {
      token?: string
      error?: string
      structured?: TriageStructuredPayload
    }
    if (parsed.error) {
      callbacks.onError(parsed.error)
      return 'error'
    }
    if (parsed.structured && callbacks.onStructured) {
      callbacks.onStructured(parsed.structured)
    }
    if (parsed.token) {
      callbacks.onToken(parsed.token)
    }
  } catch {
    /* ไม่ใช่ JSON — ข้าม */
  }
  return 'continue'
}

/** แปลงข้อความทั้งก้อนที่เป็น SSE (กรณีไม่มี getReader หรือดึงทั้ง response มาแล้ว) */
function processSseTextBlock(text: string, callbacks: AIChatStreamCallbacks, safeComplete: () => void): void {
  const lines = text.split(/\r?\n/)
  for (const line of lines) {
    const trimmed = line.trim()
    if (!trimmed) continue
    const parsedLine = parseSseDataLine(trimmed)
    if (!parsedLine) continue
    const r = handleSsePayload(parsedLine.data, callbacks, safeComplete)
    if (r === 'done' || r === 'error') return
  }
}

export interface AIChatMeta {
  line_user_id?: string
  line_account_id?: number
}

export async function streamAIChat(
  message: string,
  history: ChatHistoryItem[],
  callbacks: AIChatStreamCallbacks,
  meta?: AIChatMeta
): Promise<void> {
  const url = apiUrl('/api/ai-chat.php')
  // mode=consult บังคับใช้ persona เภสัชกรผู้ช่วย — ห้าม backend สลับไป B2B/admin
  const body: Record<string, unknown> = { message, history, mode: 'consult' }
  if (meta?.line_user_id) body.line_user_id = meta.line_user_id
  if (typeof meta?.line_account_id === 'number') body.line_account_id = meta.line_account_id

  const response = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'text/event-stream'
    },
    body: JSON.stringify(body)
  })

  let completed = false
  const safeComplete = () => {
    if (completed) return
    completed = true
    callbacks.onComplete()
  }

  if (!response.ok) {
    let detail = ''
    try {
      detail = await response.text()
      const dataLine = detail
        .split(/\n/)
        .map((l) => l.trim())
        .find((l) => l.startsWith('data:') && l.includes('error'))
      if (dataLine) {
        const parsedLine = parseSseDataLine(dataLine)
        if (parsedLine) {
          try {
            const j = JSON.parse(parsedLine.data) as { error?: string }
            if (j.error) {
              callbacks.onError(j.error)
              return
            }
          } catch {
            /* ignore */
          }
        }
      }
    } catch {
      /* ignore */
    }
    const preview = detail.replace(/\s+/g, ' ').slice(0, 200)
    callbacks.onError(
      preview
        ? `HTTP ${response.status}: ${preview}`
        : `HTTP ${response.status} — ตั้งค่า NEXT_PUBLIC_PHP_API_BASE_URL ให้ชี้ PHP ที่มี /api/ai-chat.php (เช่น https://clinicya.re-ya.com)`
    )
    return
  }

  /**
   * ใช้ ReadableStream เสมอเมื่อมี — อย่าพึ่ง Content-Type:
   * บางพร็อกซีส่งเป็น application/octet-stream แต่ body จริงเป็น SSE → เดิมไปกิ่ง response.json() แล้วพังที่ "data:"
   */
  const reader = response.body?.getReader()

  if (reader) {
    const decoder = new TextDecoder()
    let buffer = ''

    try {
      while (true) {
        const { done, value } = await reader.read()
        if (done) break

        buffer += decoder.decode(value, { stream: true })
        buffer = buffer.replace(/\r\n/g, '\n').replace(/\r/g, '\n')

        const lines = buffer.split(/\n/)
        buffer = lines.pop() ?? ''

        for (const line of lines) {
          const trimmed = line.trim()
          if (!trimmed) continue

          const parsedLine = parseSseDataLine(trimmed)
          if (!parsedLine) continue

          const r = handleSsePayload(parsedLine.data, callbacks, safeComplete)
          if (r === 'done' || r === 'error') return
        }
      }

      if (buffer.trim()) {
        const trimmed = buffer.trim()
        const parsedLine = parseSseDataLine(trimmed)
        if (parsedLine) {
          const r = handleSsePayload(parsedLine.data, callbacks, safeComplete)
          if (r === 'done' || r === 'error') return
        }
      }

      safeComplete()
    } catch (error) {
      callbacks.onError(error instanceof Error ? error.message : 'Stream error')
    } finally {
      reader.releaseLock()
    }
    return
  }

  /** ไม่มี stream body — อ่านเป็นข้อความ */
  try {
    const text = await response.text()
    const first = text.trimStart()
    if (first.startsWith('data:')) {
      processSseTextBlock(text, callbacks, safeComplete)
      if (!completed) safeComplete()
      return
    }
    if (first.startsWith('{')) {
      const data = JSON.parse(text) as { error?: string; response?: string }
      if (data.error) {
        callbacks.onError(data.error)
        return
      }
      if (data.response) {
        callbacks.onToken(data.response)
      }
      safeComplete()
      return
    }
    callbacks.onError('รูปแบบคำตอบจากเซิร์ฟเวอร์ไม่ถูกต้อง')
  } catch {
    callbacks.onError('รูปแบบคำตอบจากเซิร์ฟเวอร์ไม่ถูกต้อง')
  }
}
