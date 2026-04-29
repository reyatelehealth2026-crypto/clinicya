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

export interface TriageOption {
  value: string
  label: string
}

export interface TriageProduct {
  id: number
  name: string
  description?: string
  price: number
  sale_price?: number | null
  image_url?: string
  drug_type?: string
  active_ingredient?: string
  strength?: string
  dosage_form?: string
  usage_instructions?: string
  reason?: string
  is_first_line?: boolean
  matched_symptoms?: string[]
}

export type TriageStructuredType = 'question' | 'products' | 'escalate' | 'continue'

export interface TriageStructuredPayload {
  type: TriageStructuredType
  session_id?: number | null
  question_id?: number
  question_th?: string
  options?: TriageOption[]
  products?: TriageProduct[]
  message?: string
}

export interface SSEStructuredEvent {
  structured: TriageStructuredPayload
}

export type SSEEvent = SSETokenEvent | SSEErrorEvent | SSEStructuredEvent | '[DONE]'

export interface AIChatStreamCallbacks {
  onToken: (token: string) => void
  onComplete: () => void
  onError: (error: string) => void
  onStructured?: (payload: TriageStructuredPayload) => void
}
