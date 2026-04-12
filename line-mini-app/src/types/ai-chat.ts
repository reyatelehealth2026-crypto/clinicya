export interface ChatMessage {
  id: string
  role: 'user' | 'assistant'
  content: string
  timestamp: Date
}

export interface ChatHistoryItem {
  role: 'user' | 'assistant'
  content: string
}

export interface SSETokenEvent {
  token: string
}

export interface SSEErrorEvent {
  error: string
}

export type SSEEvent = SSETokenEvent | SSEErrorEvent | '[DONE]'

export interface AIChatStreamCallbacks {
  onToken: (token: string) => void
  onComplete: () => void
  onError: (error: string) => void
}
