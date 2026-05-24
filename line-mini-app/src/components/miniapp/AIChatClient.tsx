'use client'

import { useState, useRef, useEffect } from 'react'
import { useRouter } from 'next/navigation'
import { Send, Bot, User, AlertCircle } from 'lucide-react'
import { useLineContext } from '@/components/providers'
import { AppShell } from '@/components/miniapp/AppShell'
import { TriageOptions } from '@/components/miniapp/TriageOptions'
import { ProductCardList } from '@/components/miniapp/ProductCardList'
import { EscalationBanner } from '@/components/miniapp/EscalationBanner'
import { StateHeader } from '@/components/miniapp/StateHeader'
import { AllergyBanner } from '@/components/miniapp/AllergyBanner'
import { EmergencyModal } from '@/components/miniapp/EmergencyModal'
import { DrugInteractionWarning } from '@/components/miniapp/DrugInteractionWarning'
import { MIMSInfoCard } from '@/components/miniapp/MIMSInfoCard'
import { PharmacistConsultCTA } from '@/components/miniapp/PharmacistConsultCTA'
import { streamAIChat } from '@/lib/ai-chat-api'
import { fetchAIChatHistory } from '@/lib/ai-chat-history-api'
import { scanEmergency, type EmergencyPayload, type EmergencySeverity } from '@/lib/emergency-scan'
import { toTriageState, type TriageState } from '@/lib/state-labels'
import { useToast } from '@/lib/toast'
import type {
  ChatMessage,
  ChatHistoryItem,
  TriageOption,
  TriageProduct,
  TriageStructuredPayload
} from '@/types/ai-chat'

/* -------------------------------------------------------------------------- */
/* New structured payload shapes — mirror the SSE schema in the Option D spec */
/* -------------------------------------------------------------------------- */

interface UserContextPayload {
  name: string | null
  allergies: Array<{ drug_name: string; reaction_type?: string; severity?: string }>
  chronic_diseases: string | null
  current_medications: Array<{ medication_name: string; dosage?: string }>
  has_allergies: boolean
  has_medications: boolean
}

interface DrugInteractionItem {
  type: 'allergy' | 'interaction'
  severity: 'high' | 'medium' | 'low'
  product: string
  message: string
  reaction_type?: 'rash' | 'breathing' | 'swelling' | 'other'
  interacts_with?: string | null
}

interface DrugInteractionsPayload {
  warnings: DrugInteractionItem[]
}

interface MIMSPayload {
  disease: {
    name_th: string
    name_en?: string
    non_drug_advice?: string[]
    referral_criteria?: string[]
  }
  red_flags?: Array<{ flag: { message: string }; matched_keyword?: string }>
}

interface SuggestPharmacistPayload {
  reason: string
  video_call_url?: string
}

interface StatePayload {
  state: string
  label_th?: string
}

/* -------------------------------------------------------------------------- */
/* Helpers                                                                    */
/* -------------------------------------------------------------------------- */

function generateId(): string {
  return `${Date.now()}-${Math.random().toString(36).slice(2, 11)}`
}

const HISTORY_SEPARATOR = '--- ประวัติการสนทนาก่อนหน้า ---'
const DEFAULT_GREETING =
  'สวัสดีครับ ผม AI ผู้ช่วยเภสัชกร 💊\nมีอะไรให้ช่วยเหลือไหมครับ? เช่น สอบถามอาการป่วยเบื้องต้น หรือข้อมูลยา'

function defaultGreetingMessage(): ChatMessage {
  return {
    id: generateId(),
    role: 'assistant',
    content: DEFAULT_GREETING,
    timestamp: new Date()
  }
}

function buildHistorySeparator(): ChatMessage {
  return {
    id: generateId(),
    role: 'assistant',
    content: HISTORY_SEPARATOR,
    timestamp: new Date()
  }
}

function allergiesFromContext(context: UserContextPayload): string[] {
  return context.allergies
    .map((a) => a.drug_name)
    .filter((name): name is string => typeof name === 'string' && name.trim().length > 0)
}

/* -------------------------------------------------------------------------- */
/* Component                                                                  */
/* -------------------------------------------------------------------------- */

function LoadingDots() {
  return (
    <div className="flex gap-1 py-1">
      <div className="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style={{ animationDelay: '0ms' }} />
      <div className="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style={{ animationDelay: '150ms' }} />
      <div className="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style={{ animationDelay: '300ms' }} />
    </div>
  )
}

export function AIChatClient() {
  const lineCtx = useLineContext()
  const { toast } = useToast()
  const router = useRouter()

  const [messages, setMessages] = useState<ChatMessage[]>([defaultGreetingMessage()])
  const [input, setInput] = useState('')
  const [isStreaming, setIsStreaming] = useState(false)
  const [streamingContent, setStreamingContent] = useState('')
  const [currentOptions, setCurrentOptions] = useState<TriageOption[]>([])
  const [currentProducts, setCurrentProducts] = useState<TriageProduct[]>([])
  const [escalation, setEscalation] = useState<string | null>(null)
  const [historyLoaded, setHistoryLoaded] = useState(false)

  // New structured panels — Option D
  const [headerState, setHeaderState] = useState<TriageState>('greeting')
  const [allergies, setAllergies] = useState<string[] | null>(null)
  const [emergency, setEmergency] = useState<EmergencyPayload | null>(null)
  const [drugInteractions, setDrugInteractions] = useState<DrugInteractionItem[] | null>(null)
  const [mimsInfo, setMimsInfo] = useState<MIMSPayload | null>(null)
  const [suggestPharmacist, setSuggestPharmacist] = useState<SuggestPharmacistPayload | null>(null)

  const bottomRef = useRef<HTMLDivElement>(null)

  // Load cross-device chat history on mount (Requirement 9.1)
  useEffect(() => {
    let cancelled = false
    const userId = lineCtx.profile?.userId
    if (!userId) {
      setHistoryLoaded(true)
      return () => {
        cancelled = true
      }
    }
    fetchAIChatHistory(userId)
      .then((history) => {
        if (cancelled) return
        if (history.length === 0) {
          setHistoryLoaded(true)
          return
        }
        // Only seed history if the user hasn't started a conversation yet.
        // Using functional setState ensures we observe the latest message list
        // (avoids overwriting messages sent before the fetch resolved).
        setMessages((prev) => {
          const userInteracted = prev.some((m) => m.role === 'user')
          if (userInteracted) return prev
          // Preserve the default greeting (rendered after history) so the
          // restored transcript stays in chronological order.
          return [...history, buildHistorySeparator(), ...prev]
        })
        setHistoryLoaded(true)
      })
      .catch(() => {
        if (!cancelled) setHistoryLoaded(true)
      })
    return () => {
      cancelled = true
    }
  }, [lineCtx.profile?.userId])

  // Auto-scroll to bottom
  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: 'smooth' })
  }, [messages, streamingContent])

  const resetTurnPanels = () => {
    setCurrentOptions([])
    setCurrentProducts([])
    setEscalation(null)
    setDrugInteractions(null)
    setMimsInfo(null)
    setSuggestPharmacist(null)
  }

  const handleStructured = (payload: TriageStructuredPayload) => {
    // Existing types (preserve original behaviour)
    if (payload.type === 'question' && Array.isArray(payload.options)) {
      setCurrentOptions(payload.options)
      return
    }
    if (payload.type === 'products' && Array.isArray(payload.products)) {
      setCurrentProducts(payload.products)
      return
    }
    if (payload.type === 'escalate') {
      setEscalation(payload.message ?? 'พบสัญญาณที่ต้องพบเภสัชกร/แพทย์')
      return
    }

    // TODO(post-merge): replace `payload as unknown as ...` with discriminated union narrowing
    // once Phase 2's extended TriageStructuredPayload is on this branch
    const extra = payload as unknown as Record<string, unknown>
    const kind = typeof extra.type === 'string' ? extra.type : ''

    switch (kind) {
      case 'user_context': {
        const ctx = extra as unknown as UserContextPayload
        const names = allergiesFromContext(ctx)
        if (names.length > 0) setAllergies(names)
        return
      }
      case 'emergency': {
        const sev: EmergencySeverity = extra.severity === 'critical' ? 'critical' : 'warning'
        const symptomsRaw = Array.isArray(extra.symptoms) ? extra.symptoms : []
        const symptoms = symptomsRaw.filter((s): s is string => typeof s === 'string')
        const recommendation =
          typeof extra.recommendation === 'string' ? extra.recommendation : ''
        const serverPayload: EmergencyPayload = { severity: sev, symptoms, recommendation }
        // Prevent flicker: keep client-side `critical` modal when server confirms with
        // a lesser severity, and merge symptom lists (deduped) when severities match.
        setEmergency((prev) => {
          if (!prev) return serverPayload
          if (prev.severity === 'critical' && serverPayload.severity !== 'critical') return prev
          if (prev.severity === serverPayload.severity) {
            const merged = Array.from(new Set([...prev.symptoms, ...serverPayload.symptoms]))
            return { ...serverPayload, symptoms: merged }
          }
          return serverPayload
        })
        return
      }
      case 'drug_interactions': {
        const warnings = Array.isArray(extra.warnings) ? (extra.warnings as DrugInteractionItem[]) : []
        if (warnings.length > 0) setDrugInteractions(warnings)
        return
      }
      case 'mims_info': {
        const mims = extra as unknown as MIMSPayload
        if (mims.disease) setMimsInfo(mims)
        return
      }
      case 'suggest_pharmacist': {
        const reason = typeof extra.reason === 'string' ? extra.reason : ''
        const videoCallUrl =
          typeof extra.video_call_url === 'string' ? extra.video_call_url : undefined
        setSuggestPharmacist({ reason, video_call_url: videoCallUrl })
        return
      }
      case 'state': {
        const s = extra as unknown as StatePayload
        setHeaderState(toTriageState(s.state))
        return
      }
      default:
        // Unknown / future event types — ignore silently
        return
    }
  }

  const handleSend = async (textOverride?: string) => {
    const text = (textOverride ?? input).trim()
    if (!text || isStreaming) return

    // Clear per-turn panels
    resetTurnPanels()

    // Client-side emergency pre-scan — surface modal immediately (server will
    // confirm via its own `emergency` SSE event using RedFlagDetector).
    const localEmergency = scanEmergency(text)
    if (localEmergency && localEmergency.severity === 'critical') {
      setEmergency(localEmergency)
    }

    // History payload (last 10 turns, exclude the message we're about to send)
    const historyPayload: ChatHistoryItem[] = messages.slice(-10).map((m) => ({
      role: m.role,
      content: m.content
    }))

    const userMsg: ChatMessage = {
      id: generateId(),
      role: 'user',
      content: text,
      timestamp: new Date()
    }
    setMessages((prev) => [...prev, userMsg])
    setInput('')
    setIsStreaming(true)
    setStreamingContent('')

    let fullResponse = ''
    let lastStructured: TriageStructuredPayload | null = null

    try {
      await streamAIChat(
        text,
        historyPayload,
        {
          onToken: (token) => {
            fullResponse += token
            setStreamingContent(fullResponse)
          },
          onStructured: (payload) => {
            lastStructured = payload
            handleStructured(payload)
          },
          onComplete: () => {
            const finalText =
              fullResponse.trim() ||
              lastStructured?.message ||
              lastStructured?.question_th ||
              'ขออภัย ไม่สามารถให้คำตอบได้'
            const aiMsg: ChatMessage = {
              id: generateId(),
              role: 'assistant',
              content: finalText,
              timestamp: new Date()
            }
            setMessages((prev) => [...prev, aiMsg])
            setStreamingContent('')
            setIsStreaming(false)
          },
          onError: (error) => {
            const short = error.length > 220 ? `${error.slice(0, 220)}…` : error
            toast.error(short)

            const errorMsg: ChatMessage = {
              id: generateId(),
              role: 'assistant',
              content: `ขออภัย: ${short}`,
              timestamp: new Date()
            }
            setMessages((prev) => [...prev, errorMsg])
            setStreamingContent('')
            setIsStreaming(false)
          }
        },
        {
          line_user_id: lineCtx.profile?.userId
        }
      )
    } catch (error) {
      const msg = error instanceof Error ? error.message : 'ไม่สามารถเชื่อมต่อกับ AI ได้'
      toast.error(msg.length > 180 ? `${msg.slice(0, 180)}…` : msg)
      setStreamingContent('')
      setIsStreaming(false)
    }
  }

  const handleOptionClick = (value: string, label: string) => {
    if (isStreaming) return
    setCurrentOptions([])
    void handleSend(label || value)
  }

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault()
      void handleSend()
    }
  }

  const goToVideoConsult = (emergencyContext = false) => {
    router.push(emergencyContext ? '/miniapp/video?emergency=1' : '/miniapp/video')
  }

  // Show quick suggestions only when there's no history and no real conversation yet
  const showQuickSuggestions =
    !isStreaming && historyLoaded && messages.length <= 2 && messages.every((m) => m.role === 'assistant')

  return (
    <AppShell>
      <div className="flex flex-col h-[calc(100svh-8rem)]">
        {/* Header */}
        <div className="px-4 py-3 border-b border-gray-100 bg-white">
          <div className="flex items-center gap-2">
            <div className="w-10 h-10 rounded-full bg-gradient-to-br from-purple-500 to-indigo-600 flex items-center justify-center">
              <Bot className="w-5 h-5 text-white" />
            </div>
            <div className="flex-1">
              <h1 className="font-semibold text-gray-900">ปรึกษาเภสัชกร AI</h1>
              <p className="text-xs text-green-600 flex items-center gap-1">
                <span className="w-1.5 h-1.5 bg-green-500 rounded-full" />
                ออนไลน์
              </p>
            </div>
            <StateHeader state={headerState} />
          </div>
          <p className="mt-2 text-xs text-gray-500 flex items-start gap-1">
            <AlertCircle className="w-3 h-3 mt-0.5 shrink-0" />
            AI ให้คำแนะนำเบื้องต้นเท่านั้น ไม่ใช่การวินิจฉัยโรค
          </p>
        </div>

        {/* Allergy banner — sticky just below header */}
        {allergies && allergies.length > 0 ? (
          <AllergyBanner allergies={allergies} onClose={() => setAllergies(null)} />
        ) : null}

        {/* Messages */}
        <div className="flex-1 overflow-y-auto px-4 py-4 space-y-4 bg-gray-50">
          {messages.map((msg) => (
            <div
              key={msg.id}
              className={`flex gap-3 ${msg.role === 'user' ? 'flex-row-reverse' : ''}`}
            >
              <div
                className={`w-9 h-9 rounded-full flex items-center justify-center shrink-0 ${
                  msg.role === 'assistant' ? 'bg-purple-100' : 'bg-blue-100'
                }`}
              >
                {msg.role === 'assistant' ? (
                  <Bot className="w-4 h-4 text-purple-600" />
                ) : (
                  <User className="w-4 h-4 text-blue-600" />
                )}
              </div>
              <div
                className={`max-w-[80%] px-4 py-2.5 rounded-2xl text-sm whitespace-pre-line ${
                  msg.role === 'user'
                    ? 'bg-blue-600 text-white rounded-br-md'
                    : 'bg-white text-gray-800 shadow-sm rounded-bl-md border border-gray-100'
                }`}
              >
                {msg.content}
              </div>
            </div>
          ))}

          {/* Streaming message */}
          {isStreaming && streamingContent && (
            <div className="flex gap-3">
              <div className="w-9 h-9 rounded-full bg-purple-100 flex items-center justify-center shrink-0">
                <Bot className="w-4 h-4 text-purple-600" />
              </div>
              <div className="max-w-[80%] px-4 py-2.5 rounded-2xl text-sm bg-white text-gray-800 shadow-sm rounded-bl-md border border-gray-100 whitespace-pre-line">
                {streamingContent}
                <span className="inline-block w-1.5 h-4 bg-purple-400 ml-0.5 animate-pulse" />
              </div>
            </div>
          )}

          {/* Loading indicator (before first token) */}
          {isStreaming && !streamingContent && (
            <div className="flex gap-3">
              <div className="w-9 h-9 rounded-full bg-purple-100 flex items-center justify-center shrink-0">
                <Bot className="w-4 h-4 text-purple-600" />
              </div>
              <div className="bg-white shadow-sm rounded-2xl rounded-bl-md px-4 py-3 border border-gray-100">
                <LoadingDots />
              </div>
            </div>
          )}

          {/* Triage structured UI — รักษาพฤติกรรมเดิม */}
          {!isStreaming && escalation ? <EscalationBanner message={escalation} /> : null}

          {!isStreaming && currentOptions.length > 0 ? (
            <TriageOptions options={currentOptions} onSelect={handleOptionClick} />
          ) : null}

          {!isStreaming && currentProducts.length > 0 ? (
            <ProductCardList products={currentProducts} />
          ) : null}

          {/* New Option D panels */}
          {!isStreaming && drugInteractions && drugInteractions.length > 0 ? (
            <DrugInteractionWarning
              warnings={drugInteractions}
              onConsultPharmacist={() => goToVideoConsult(false)}
            />
          ) : null}

          {!isStreaming && mimsInfo ? (
            <MIMSInfoCard disease={mimsInfo.disease} redFlags={mimsInfo.red_flags ?? []} />
          ) : null}

          {!isStreaming && suggestPharmacist ? (
            <PharmacistConsultCTA
              reason={suggestPharmacist.reason}
              videoCallUrl={suggestPharmacist.video_call_url}
              onClick={() => goToVideoConsult(false)}
            />
          ) : null}

          <div ref={bottomRef} />
        </div>

        {/* Input */}
        <div className="bg-white border-t border-gray-100 px-4 py-3">
          <div className="flex items-center gap-2">
            {/* Phase 4 image-upload mount point — ImageUploadButton portals into this slot */}
            <div id="ai-chat-input-slot" data-slot="image-upload" />
            <input
              type="text"
              value={input}
              onChange={(e) => setInput(e.target.value)}
              onKeyDown={handleKeyDown}
              placeholder="พิมพ์ข้อความถาม AI..."
              disabled={isStreaming}
              className="flex-1 px-4 py-3 bg-gray-100 rounded-full text-sm focus:outline-none focus:ring-2 focus:ring-purple-500/30 disabled:opacity-50"
            />
            <button
              onClick={() => void handleSend()}
              disabled={!input.trim() || isStreaming}
              className="w-11 h-11 bg-purple-600 text-white rounded-full flex items-center justify-center disabled:opacity-40 active:scale-90 transition-transform"
            >
              <Send className="w-4 h-4" />
            </button>
          </div>

          {/* Quick suggestions — only when fresh session with no history */}
          {showQuickSuggestions && (
            <div className="mt-3 flex flex-wrap gap-2">
              {['ไข้หวัด', 'ปวดหัว', 'ท้องเสีย', 'แพ้อากาศ', 'ปรึกษาเภสัชกร'].map((suggestion) => (
                <button
                  key={suggestion}
                  onClick={() => handleSend(suggestion)}
                  className="px-3 py-1.5 bg-purple-50 text-purple-700 text-xs rounded-full border border-purple-100 hover:bg-purple-100 transition-colors"
                >
                  {suggestion}
                </button>
              ))}
            </div>
          )}
        </div>

        {/* Emergency modal — overlays everything */}
        {emergency ? (
          <EmergencyModal
            severity={emergency.severity}
            symptoms={emergency.symptoms}
            recommendation={emergency.recommendation}
            onClose={() => setEmergency(null)}
            onConsultPharmacist={() => {
              setEmergency(null)
              goToVideoConsult(true)
            }}
          />
        ) : null}
      </div>
    </AppShell>
  )
}
