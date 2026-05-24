/**
 * Fetch cross-device AI chat history for the Mini App.
 *
 * The server endpoint is owned by Phase 1 — `api/ai-chat-history.php`.
 * Response contract:
 * ```
 * { success: true, messages: [ { role, content, created_at } ] }
 * ```
 * If the request fails or the user is anonymous we resolve to an empty
 * array so the caller can fall back to the default greeting.
 */

import { apiUrl } from '@/lib/config'
import type { ChatMessage } from '@/types/ai-chat'

interface RawHistoryMessage {
  role?: string
  content?: string
  created_at?: string
}

interface HistoryResponse {
  success?: boolean
  messages?: RawHistoryMessage[]
}

function parseTimestamp(value: string | undefined): Date {
  if (!value) return new Date()
  const parsed = new Date(value.replace(' ', 'T'))
  return Number.isNaN(parsed.getTime()) ? new Date() : parsed
}

function normaliseRole(role: string | undefined): 'user' | 'assistant' | null {
  if (role === 'user') return 'user'
  if (role === 'assistant' || role === 'ai' || role === 'model') return 'assistant'
  return null
}

function generateHistoryId(index: number, createdAt: string | undefined): string {
  const base = createdAt ?? Date.now().toString()
  return `hist-${index}-${base}`
}

/**
 * Load the last N history rows for the given LINE user.
 * Returns `[]` for anonymous sessions or when the request fails.
 */
export async function fetchAIChatHistory(
  lineUserId: string | null | undefined,
  limit = 20
): Promise<ChatMessage[]> {
  if (!lineUserId) return []

  const params = new URLSearchParams({
    line_user_id: lineUserId,
    limit: String(limit)
  })
  const url = `${apiUrl('/api/ai-chat-history.php')}?${params.toString()}`

  try {
    const response = await fetch(url, {
      method: 'GET',
      headers: { Accept: 'application/json' }
    })
    if (!response.ok) return []

    const data = (await response.json()) as HistoryResponse
    if (!data.success || !Array.isArray(data.messages)) return []

    const messages: ChatMessage[] = []
    data.messages.forEach((raw, index) => {
      const role = normaliseRole(raw.role)
      const content = typeof raw.content === 'string' ? raw.content : ''
      if (!role || !content.trim()) return
      messages.push({
        id: generateHistoryId(index, raw.created_at),
        role,
        content,
        timestamp: parseTimestamp(raw.created_at)
      })
    })
    return messages
  } catch {
    return []
  }
}
