'use client'

import { useState, useRef, useEffect } from 'react'
import { useRouter } from 'next/navigation'
import { Send, Bot, User, AlertCircle, MoreVertical, Trash2, Thermometer, Wind, Stethoscope, Pill, Activity, X } from 'lucide-react'
import { useLineContext } from '@/components/providers'
import { AppShell } from '@/components/miniapp/AppShell'
import { TriageOptions } from '@/components/miniapp/TriageOptions'
import { ProductCardList } from '@/components/miniapp/ProductCardList'
import { EscalationBanner } from '@/components/miniapp/EscalationBanner'
import { StateHeader } from '@/components/miniapp/StateHeader'
import { AllergyBanner } from '@/components/miniapp/AllergyBanner'
import { EmergencyModal } from '@/components/miniapp/EmergencyModal'
import { ConsentModal } from '@/components/miniapp/ConsentModal'
import { DrugInteractionWarning } from '@/components/miniapp/DrugInteractionWarning'
import { MIMSInfoCard } from '@/components/miniapp/MIMSInfoCard'
import { PharmacistConsultCTA } from '@/components/miniapp/PharmacistConsultCTA'
import { streamAIChat } from '@/lib/ai-chat-api'
import { fetchAIChatHistory, clearAIChatHistory } from '@/lib/ai-chat-history-api'
import { saveHealthDataConsent } from '@/lib/consent-api'
import { apiUrl, appConfig } from '@/lib/config'
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

const HISTORY_SEPARATOR = '--- ประวัติการสนทนาก่อนหน้า --- '
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

// build: 2026-05-24-optd-v3 (cache-bust)
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

  // UI menu state
  const [menuOpen, setMenuOpen] = useState(false)
  // Pending order — last AI message that recommended specific drugs (has mg/dose pattern)
  const [pendingOrderMsg, setPendingOrderMsg] = useState<string | null>(null)
  const [orderSending, setOrderSending] = useState(false)
  const [orderConfirmed, setOrderConfirmed] = useState(false)

  // New structured panels — Option D
  const [headerState, setHeaderState] = useState<TriageState>('greeting')
  const [allergies, setAllergies] = useState<string[] | null>(null)
  const [emergency, setEmergency] = useState<EmergencyPayload | null>(null)
  const [drugInteractions, setDrugInteractions] = useState<DrugInteractionItem[] | null>(null)
  const [mimsInfo, setMimsInfo] = useState<MIMSPayload | null>(null)
  const [suggestPharmacist, setSuggestPharmacist] = useState<SuggestPharmacistPayload | null>(null)
  // PDPA health-data consent (issue #15): shown when the backend flags
  // `consent_required`; `consentHandled` stops re-prompting for the session.
  const [consentRequired, setConsentRequired] = useState(false)
  const [consentHandled, setConsentHandled] = useState(false)
  const [consentSubmitting, setConsentSubmitting] = useState(false)

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
    fetchAIChatHistory(userId, 20, lineCtx.accessToken)
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
        // If the last AI message in history looks like a drug recommendation,
        // show the "ส่งให้เภสัชกร" button so user can act on a previous chat.
        const lastAi = [...history].reverse().find((m) => m.role === 'assistant')
        if (lastAi && detectDrugRecommendation(lastAi.content)) {
          setPendingOrderMsg(lastAi.content)
          setOrderConfirmed(false)
        }
      })
      .catch(() => {
        if (!cancelled) setHistoryLoaded(true)
      })
    return () => {
      cancelled = true
    }
  }, [lineCtx.profile?.userId, lineCtx.accessToken])

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
    setPendingOrderMsg(null)
    setOrderConfirmed(false)
  }

  // Heuristic — does the AI message look like it recommended specific drugs?
  // Triggers when message has dose pattern (mg/มล./cc) AND usage word (ทาน/พ่น/ใช้).
  const detectDrugRecommendation = (text: string): boolean => {
    if (!text) return false
    const dose = /\d+\s*(mg|มก\.?|มล\.?|cc|กรัม|กรัม)/i.test(text)
    const usage = /(ทาน|รับประทาน|พ่น|หยอด|ใช้|จิบ|อม)/.test(text)
    return dose && usage
  }

  const handleSubmitOrder = async () => {
    if (!pendingOrderMsg) return
    setOrderSending(true)
    try {
      const userId = lineCtx.profile?.userId
      const approveHeaders: Record<string, string> = { 'Content-Type': 'application/json' }
      if (lineCtx.accessToken) approveHeaders.Authorization = `Bearer ${lineCtx.accessToken}`
      const resp = await fetch(apiUrl('/api/ai-chat-approve-order.php'), {
        method: 'POST',
        headers: approveHeaders,
        body: JSON.stringify({
          line_user_id: userId,
          line_account_id: appConfig.lineAccountId,
          last_ai_message: pendingOrderMsg,
          summary: pendingOrderMsg.split('\n').slice(0, 3).join(' ').slice(0, 500)
        })
      })
      const data = (await resp.json()) as { success?: boolean; message?: string }
      if (data.success) {
        setOrderConfirmed(true)
        setPendingOrderMsg(null)
        const ack: ChatMessage = {
          id: generateId(),
          role: 'assistant',
          content: '✅ ส่งรายการยาให้เภสัชกรเรียบร้อยแล้ว — เภสัชกรจะตรวจสอบและติดต่อกลับเร็วๆ นี้ครับ',
          timestamp: new Date()
        }
        setMessages((prev) => [...prev, ack])
        toast.success('ส่งให้เภสัชกรแล้ว')
      } else {
        toast.error(data.message || 'ส่งคำสั่งไม่สำเร็จ')
      }
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'ส่งคำสั่งไม่สำเร็จ')
    } finally {
      setOrderSending(false)
    }
  }

  const handleClearChat = async () => {
    setMenuOpen(false)
    if (typeof window !== 'undefined' && !window.confirm('ลบประวัติการสนทนาทั้งหมดและเริ่มใหม่?')) return
    const userId = lineCtx.profile?.userId
    // Delete server-side history first so it doesn't repopulate on refresh
    const ok = userId ? await clearAIChatHistory(userId, lineCtx.accessToken) : true
    setMessages([defaultGreetingMessage()])
    setInput('')
    setStreamingContent('')
    setIsStreaming(false)
    resetTurnPanels()
    setEmergency(null)
    setAllergies(null)
    setHeaderState('greeting')
    if (ok) {
      toast.success('ล้างประวัติการสนทนาแล้ว')
    } else {
      toast.error('ลบฝั่ง server ไม่สำเร็จ (ลบเฉพาะหน้าจอ)')
    }
  }

  const QUICK_SYMPTOMS: Array<{ icon: typeof Thermometer; label: string; query: string }> = [
    { icon: Activity, label: 'ปวดหัว', query: 'ปวดหัว' },
    { icon: Thermometer, label: 'ไข้หวัด', query: 'ไข้หวัด' },
    { icon: Wind, label: 'ไอ/เจ็บคอ', query: 'ไอ เจ็บคอ' },
    { icon: Stethoscope, label: 'ปวดท้อง', query: 'ปวดท้อง' },
    { icon: AlertCircle, label: 'แพ้อากาศ', query: 'แพ้อากาศ' },
    { icon: Pill, label: 'ปวดกล้ามเนื้อ', query: 'ปวดกล้ามเนื้อ' }
  ]

  const handleStructured = (payload: TriageStructuredPayload) => {
    // PDPA (issue #15): the backend attaches `consent_required` to any payload
    // when a real user lacks active health_data consent. Prompt once per session.
    const consentFlag = (payload as unknown as { consent_required?: boolean }).consent_required
    if (consentFlag === true && !consentHandled) {
      setConsentRequired(true)
    }

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
            // Narrow discriminated union — only some payload types carry message/question_th text
            const structuredText = ((): string | undefined => {
              const p = lastStructured
              if (!p) return undefined
              if (p.type === 'question') return p.question_th
              if (p.type === 'escalate' || p.type === 'products') return p.message
              return undefined
            })()
            const finalText =
              fullResponse.trim() ||
              structuredText ||
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
            // Detect drug recommendation → show "ส่งให้เภสัชกร" button
            if (detectDrugRecommendation(finalText)) {
              setPendingOrderMsg(finalText)
              setOrderConfirmed(false)
            }
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
    router.push(emergencyContext ? '/video?emergency=1' : '/video')
  }

  // Show quick suggestions only when there's no history and no real conversation yet
  const showQuickSuggestions =
    !isStreaming && historyLoaded && messages.length <= 2 && messages.every((m) => m.role === 'assistant')

  return (
    <AppShell header={<></>} contentClassName="!max-w-full !px-0 !py-0 !gap-0 !pb-0 !h-full">
      <div className="flex flex-col h-full min-h-0">
        {/* Header */}
        <div className="px-3 py-2 border-b border-gray-100 bg-white shadow-sm">
          <div className="flex items-center gap-2">
            <div className="w-8 h-8 rounded-full bg-gradient-to-br from-purple-500 to-indigo-600 flex items-center justify-center shrink-0">
              <Bot className="w-4 h-4 text-white" />
            </div>
            <div className="flex-1 min-w-0">
              <h1 className="font-semibold text-gray-900 text-sm leading-tight flex items-center gap-1.5">
                ปรึกษาเภสัชกร AI
                <span className="w-1.5 h-1.5 bg-green-500 rounded-full animate-pulse" title="ออนไลน์" />
              </h1>
            </div>
            <StateHeader state={headerState} />
            <div className="relative">
              <button
                type="button"
                onClick={() => setMenuOpen((v) => !v)}
                aria-label="เมนู"
                className="w-8 h-8 rounded-full flex items-center justify-center hover:bg-gray-100 active:scale-95 transition"
              >
                <MoreVertical className="w-4 h-4 text-gray-600" />
              </button>
              {menuOpen ? (
                <>
                  <div className="fixed inset-0 z-30" onClick={() => setMenuOpen(false)} />
                  <div className="absolute right-0 top-10 z-40 w-56 bg-white rounded-xl shadow-lg border border-gray-100 py-1">
                    <button
                      type="button"
                      onClick={handleClearChat}
                      className="w-full px-4 py-2.5 text-left text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2"
                    >
                      <Trash2 className="w-4 h-4 text-rose-500" />
                      ลบประวัติการสนทนา
                    </button>
                    <button
                      type="button"
                      onClick={() => {
                        setMenuOpen(false)
                        goToVideoConsult(false)
                      }}
                      className="w-full px-4 py-2.5 text-left text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2"
                    >
                      <Stethoscope className="w-4 h-4 text-emerald-600" />
                      ปรึกษาเภสัชกร (Video)
                    </button>
                    <button
                      type="button"
                      onClick={() => {
                        setMenuOpen(false)
                        router.push('/health')
                      }}
                      className="w-full px-4 py-2.5 text-left text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2"
                    >
                      <Pill className="w-4 h-4 text-blue-600" />
                      ข้อมูลสุขภาพของฉัน
                    </button>
                    <button
                      type="button"
                      onClick={() => setMenuOpen(false)}
                      className="w-full px-4 py-2.5 text-left text-sm text-gray-500 hover:bg-gray-50 flex items-center gap-2 border-t border-gray-100"
                    >
                      <X className="w-4 h-4" />
                      ปิดเมนู
                    </button>
                  </div>
                </>
              ) : null}
            </div>
          </div>
          <div className="mt-2 px-3 py-1.5 rounded-lg bg-amber-50 border border-amber-300 flex items-center gap-2">
            <AlertCircle className="w-4 h-4 text-amber-600 shrink-0 animate-pulse" />
            <p className="text-[11px] font-medium text-amber-800 leading-tight">
              ⚠️ AI ให้คำแนะนำเบื้องต้นเท่านั้น — <span className="font-bold">เภสัชกรเป็นผู้อนุมัติยา</span>
            </p>
          </div>
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

        {/* "ส่งให้เภสัชกร" CTA — only when AI just recommended drugs */}
        {pendingOrderMsg && !orderConfirmed && !isStreaming ? (
          <div className="bg-gradient-to-r from-emerald-50 to-green-50 border-t-2 border-emerald-300 px-3 py-2.5">
            <div className="flex items-center gap-2">
              <button
                type="button"
                onClick={handleSubmitOrder}
                disabled={orderSending}
                className="flex-1 px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 active:scale-95 disabled:opacity-60 text-white text-sm font-semibold rounded-xl flex items-center justify-center gap-2 shadow-sm transition"
              >
                <Stethoscope className="w-4 h-4" />
                {orderSending ? 'กำลังส่ง...' : 'ส่งรายการยาให้เภสัชกรอนุมัติ'}
              </button>
              <button
                type="button"
                onClick={() => setPendingOrderMsg(null)}
                disabled={orderSending}
                className="px-3 py-2.5 text-xs text-gray-500 hover:text-gray-700 disabled:opacity-50"
              >
                ไม่เอา
              </button>
            </div>
            <p className="text-[10px] text-emerald-700 mt-1 text-center">เภสัชกรจะตรวจสอบและยืนยันการจ่ายยาภายในไม่กี่นาที</p>
          </div>
        ) : null}

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

          {/* Quick symptoms — 6 presets (mirror liff/ai-chat.js line 35-42) */}
          {showQuickSuggestions && (
            <div className="mt-3">
              <p className="text-[11px] text-gray-500 mb-2 px-1">เลือกอาการที่ต้องการปรึกษา:</p>
              <div className="grid grid-cols-3 gap-2">
                {QUICK_SYMPTOMS.map(({ icon: Icon, label, query }) => (
                  <button
                    key={query}
                    onClick={() => handleSend(query)}
                    className="flex flex-col items-center gap-1 px-2 py-3 bg-purple-50 text-purple-700 text-xs rounded-xl border border-purple-100 hover:bg-purple-100 active:scale-95 transition"
                  >
                    <Icon className="w-5 h-5" />
                    <span className="font-medium">{label}</span>
                  </button>
                ))}
              </div>
              <button
                onClick={() => handleSend('ขอปรึกษาเภสัชกร')}
                className="mt-2 w-full px-3 py-2 bg-emerald-50 text-emerald-700 text-xs rounded-full border border-emerald-100 hover:bg-emerald-100 active:scale-95 transition flex items-center justify-center gap-1.5"
              >
                <Stethoscope className="w-4 h-4" />
                ปรึกษาเภสัชกรโดยตรง
              </button>
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

        {/* PDPA health-data consent prompt (issue #15) */}
        {consentRequired ? (
          <ConsentModal
            submitting={consentSubmitting}
            onAccept={async () => {
              setConsentSubmitting(true)
              await saveHealthDataConsent(lineCtx.profile?.userId, true, lineCtx.accessToken)
              setConsentSubmitting(false)
              setConsentHandled(true)
              setConsentRequired(false)
            }}
            onDecline={() => {
              // Record the decline (non-blocking) and stop nudging this session.
              void saveHealthDataConsent(lineCtx.profile?.userId, false, lineCtx.accessToken)
              setConsentHandled(true)
              setConsentRequired(false)
            }}
          />
        ) : null}
      </div>
    </AppShell>
  )
}
