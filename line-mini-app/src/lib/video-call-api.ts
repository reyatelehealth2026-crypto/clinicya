import { appConfig, apiUrl } from '@/lib/config'

export type SignalType = 'offer' | 'answer' | 'ice-candidate' | 'message' | 'hangup'

export interface CreateCallInput {
  userId: string
  displayName: string
  pictureUrl?: string
}

export interface CreateCallResponse {
  success: boolean
  call_id?: number
  room_id?: string
  error?: string
}

export interface StatusResponse {
  success: boolean
  status?: 'pending' | 'ringing' | 'active' | 'completed' | 'rejected' | 'missed'
  error?: string
}

export interface PolledSignal {
  id: number
  signal_type: SignalType
  signal_data: unknown
}

export interface SignalsResponse {
  success: boolean
  signals?: PolledSignal[]
  error?: string
}

const API = '/api/video-call.php'

async function postJson<T>(body: Record<string, unknown>): Promise<T> {
  const res = await fetch(apiUrl(API), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    // line_account_id lets the server route to the tenant DB (split-brain fix).
    body: JSON.stringify({ line_account_id: appConfig.lineAccountId, ...body })
  })
  return res.json() as Promise<T>
}

async function getJson<T>(params: Record<string, string | number>): Promise<T> {
  // line_account_id lets the server route to the tenant DB (split-brain fix).
  const qs = new URLSearchParams({ line_account_id: String(appConfig.lineAccountId), ...params } as Record<string, string>).toString()
  const res = await fetch(`${apiUrl(API)}?${qs}`, { cache: 'no-store' })
  return res.json() as Promise<T>
}

export function createCall(input: CreateCallInput): Promise<CreateCallResponse> {
  return postJson<CreateCallResponse>({
    action: 'create',
    user_id: input.userId,
    display_name: input.displayName,
    picture_url: input.pictureUrl ?? '',
    account_id: appConfig.lineAccountId
  })
}

export function getCallStatus(callId: number | string): Promise<StatusResponse> {
  return getJson<StatusResponse>({ action: 'get_status', call_id: String(callId) })
}

export function pollSignals(callId: number | string): Promise<SignalsResponse> {
  return getJson<SignalsResponse>({ action: 'get_signals', call_id: String(callId), for: 'customer' })
}

export function sendSignal(callId: number | string, signalType: SignalType, signalData: unknown): Promise<{ success: boolean }> {
  return postJson({
    action: 'signal',
    call_id: callId,
    signal_type: signalType,
    signal_data: signalData,
    from: 'customer'
  })
}

export function endCall(callId: number | string, duration: number): Promise<{ success: boolean }> {
  return postJson({ action: 'end', call_id: callId, duration })
}

export interface OnlinePharmacist {
  id?: number | string
  name?: string
  avatar_url?: string
}

export interface CheckOnlineResponse {
  success: boolean
  online?: boolean
  pharmacists?: OnlinePharmacist[]
  error?: string
}

/**
 * Presence check — returns `{ online, pharmacists[] }` so the UI can show
 * who's available. Endpoint may not yet exist server-side; caller should
 * tolerate a non-success response as "unknown" and offer the booking fallback.
 */
export function checkPharmacistOnline(): Promise<CheckOnlineResponse> {
  return getJson<CheckOnlineResponse>({
    action: 'check_online',
    account_id: String(appConfig.lineAccountId)
  })
}
