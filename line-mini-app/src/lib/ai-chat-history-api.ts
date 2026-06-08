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

import { apiUrl, appConfig } from '@/lib/config'
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
 * DELETE server-side conversation history for the given LINE user.
 * Returns true on success (even if zero rows), false on network/server failure.
 */
export async function clearAIChatHistory(
  lineUserId: string | null | undefined,
  accessToken?: string | null
): Promise<boolean> {
  if (!lineUserId) return false
  const url = apiUrl('/api/ai-chat-history.php?action=clear')
  try {
    const headers: Record<string, string> = { 'Content-Type': 'application/json' }
    if (accessToken) headers.Authorization = `Bearer ${accessToken}`
    const response = await fetch(url, {
      method: 'POST',
      headers,
      body: JSON.stringify({ line_user_id: lineUserId, line_account_id: appConfig.lineAccountId })
    })
    if (!response.ok) return false
    const data = (await response.json()) as { success?: boolean }
    return data.success === true
  } catch {
    return false
  }
}

/**
 * Load the last N history rows for the given LINE user.
 * Returns `[]` for anonymous sessions or when the request fails.
 */
export async function fetchAIChatHistory(
  lineUserId: string | null | undefined,
  limit = 20,
  accessToken?: string | null
): Promise<ChatMessage[]> {
  if (!lineUserId) return []

  const params = new URLSearchParams({
    line_user_id: lineUserId,
    line_account_id: String(appConfig.lineAccountId),
    limit: String(limit)
  })
  const url = `${apiUrl('/api/ai-chat-history.php')}?${params.toString()}`

  try {
    const headers: Record<string, string> = { Accept: 'application/json' }
    if (accessToken) headers.Authorization = `Bearer ${accessToken}`
    const response = await fetch(url, {
      method: 'GET',
      headers
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
