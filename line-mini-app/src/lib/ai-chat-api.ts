import { apiUrl } from '@/lib/config'
import type { ChatHistoryItem, AIChatStreamCallbacks } from '@/types/ai-chat'

export async function streamAIChat(
  message: string,
  history: ChatHistoryItem[],
  callbacks: AIChatStreamCallbacks
): Promise<void> {
  const response = await fetch(apiUrl('/api/ai-chat.php'), {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'text/event-stream'
    },
    body: JSON.stringify({ message, history })
  })

  if (!response.ok) {
    throw new Error(`HTTP error! status: ${response.status}`)
  }

  const contentType = response.headers.get('content-type') || ''
  
  // Handle SSE streaming
  if (contentType.includes('text/event-stream')) {
    const reader = response.body?.getReader()
    const decoder = new TextDecoder()
    
    if (!reader) {
      throw new Error('No response body available')
    }

    let buffer = ''

    try {
      while (true) {
        const { done, value } = await reader.read()
        
        if (done) break
        
        buffer += decoder.decode(value, { stream: true })
        
        // Process SSE lines
        const lines = buffer.split('\n')
        buffer = lines.pop() || '' // Keep incomplete line in buffer
        
        for (const line of lines) {
          const trimmed = line.trim()
          
          if (!trimmed.startsWith('data: ')) continue
          
          const data = trimmed.slice(6) // Remove 'data: ' prefix
          
          if (data === '[DONE]') {
            callbacks.onComplete()
            return
          }
          
          try {
            const parsed = JSON.parse(data) as { token?: string; error?: string }
            
            if (parsed.error) {
              callbacks.onError(parsed.error)
              return
            }
            
            if (parsed.token) {
              callbacks.onToken(parsed.token)
            }
          } catch {
            // Ignore parse errors for malformed lines
          }
        }
      }
      
      // Process any remaining data in buffer
      if (buffer.trim()) {
        const trimmed = buffer.trim()
        if (trimmed.startsWith('data: ')) {
          const data = trimmed.slice(6)
          if (data === '[DONE]') {
            callbacks.onComplete()
          } else {
            try {
              const parsed = JSON.parse(data) as { token?: string; error?: string }
              if (parsed.token) callbacks.onToken(parsed.token)
            } catch {
              // Ignore
            }
          }
        }
      }
      
      callbacks.onComplete()
    } catch (error) {
      callbacks.onError(error instanceof Error ? error.message : 'Stream error')
    } finally {
      reader.releaseLock()
    }
  } else {
    // Fallback for non-SSE response
    const data = await response.json()
    if (data.error) {
      throw new Error(data.error)
    }
    if (data.response) {
      callbacks.onToken(data.response)
    }
    callbacks.onComplete()
  }
}
