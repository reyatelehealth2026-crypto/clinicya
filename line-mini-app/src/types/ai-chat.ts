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

// ---------------------------------------------------------------------------
// Existing structured event payloads (already shipped — keep schema stable)
// ---------------------------------------------------------------------------

/** Y/N or multi-choice triage question rendered as buttons */
export interface QuestionPayload {
  type: 'question'
  session_id?: number | null
  question_id?: number
  question_th?: string
  options?: TriageOption[]
  message?: string
}

/** Product recommendations rendered as cards after triage */
export interface ProductsPayload {
  type: 'products'
  session_id?: number | null
  products: TriageProduct[]
  message?: string
}

/** Escalation banner — AI hands off to a pharmacist */
export interface EscalatePayload {
  type: 'escalate'
  session_id?: number | null
  message: string
}

/** Internal marker emitted by old TriageRouter — usually a no-op for UI */
export interface ContinuePayload {
  type: 'continue'
  session_id?: number | null
  message?: string
}

// ---------------------------------------------------------------------------
// New structured event payloads (Option D — Phase 2)
// ---------------------------------------------------------------------------

/** Single allergy entry from `user_drug_allergies` */
export interface UserAllergyEntry {
  drug_name: string
  reaction_type?: string
  severity?: 'high' | 'medium' | 'low'
}

/** Single current-medication entry from `user_current_medications` */
export interface UserMedicationEntry {
  medication_name: string
  dosage?: string
}

/**
 * Emitted ONCE at the very start of the stream (before any tokens) when the
 * resolved user has any allergy / chronic disease / medication on file. Allows
 * the client to surface an AllergyBanner + remember context for later turns.
 */
export interface UserContextPayload {
  type: 'user_context'
  name: string | null
  allergies: UserAllergyEntry[]
  chronic_diseases: string | null
  current_medications: UserMedicationEntry[]
  has_allergies: boolean
  has_medications: boolean
}

/**
 * Emitted BEFORE tokens when the RedFlagDetector flags a critical / warning
 * symptom. The client should open EmergencyModal immediately and (for
 * `critical`) effectively abort the rest of the stream from a UX standpoint.
 */
export interface EmergencyPayload {
  type: 'emergency'
  severity: 'critical' | 'warning'
  symptoms: string[]
  recommendation: string
}

/** One warning row inside DrugInteractionsPayload.warnings[] */
export interface DrugInteractionWarning {
  type: 'allergy' | 'interaction'
  severity: 'high' | 'medium' | 'low'
  product: string
  message: string
  reaction_type?: 'rash' | 'breathing' | 'swelling' | 'other' | string
  interacts_with?: string | null
  /** Some backends include this — true when the warning comes from the user's allergy list */
  allergy?: boolean
}

/**
 * Emitted AFTER products (or alongside) when the AI / DrugInteractionChecker
 * detects an allergy match or known interaction against the recommended set.
 */
export interface DrugInteractionsPayload {
  type: 'drug_interactions'
  warnings: DrugInteractionWarning[]
}

/** Disease entry inside an MIMS knowledge card */
export interface MimsDisease {
  name_th: string
  name_en?: string
  non_drug_advice?: string[]
  referral_criteria?: string[]
}

/** Red-flag tag inside an MIMS knowledge card */
export interface MimsRedFlag {
  flag: { message: string }
  matched_keyword?: string
}

/** Emitted AFTER tokens — renders an educational MIMS info card */
export interface MimsInfoPayload {
  type: 'mims_info'
  disease: MimsDisease
  red_flags?: MimsRedFlag[]
}

/**
 * Emitted AFTER tokens when AI explicitly decides the user would benefit from
 * a human pharmacist. Includes a deep link to start a video call.
 */
export interface SuggestPharmacistPayload {
  type: 'suggest_pharmacist'
  reason: string
  video_call_url: string
}

/** Triage state machine codes — mirror modules/AIChat/Services/TriageEngine.php */
export type TriageState =
  | 'greeting'
  | 'symptom'
  | 'duration'
  | 'severity'
  | 'associated'
  | 'allergy'
  | 'medical_history'
  | 'current_meds'
  | 'recommend'
  | 'confirm'
  | 'complete'
  | 'escalate'

/** Emitted before tokens — current `triage_sessions.current_state` */
export interface StatePayload {
  type: 'state'
  state: TriageState
  label_th: string
}

/**
 * Discriminated union of every `structured` SSE payload `/api/ai-chat.php`
 * can emit. Use the `type` field to narrow.
 */
export type TriageStructuredPayload =
  | QuestionPayload
  | ProductsPayload
  | EscalatePayload
  | ContinuePayload
  | UserContextPayload
  | EmergencyPayload
  | DrugInteractionsPayload
  | MimsInfoPayload
  | SuggestPharmacistPayload
  | StatePayload

/** Convenience alias for the discriminator string */
export type TriageStructuredType = TriageStructuredPayload['type']

export interface SSEStructuredEvent {
  structured: TriageStructuredPayload
}

export type SSEEvent = SSETokenEvent | SSEErrorEvent | SSEStructuredEvent | '[DONE]'

export interface AIChatStreamCallbacks {
  onToken: (token: string) => void
  onComplete: () => void
  onError: (error: string) => void
  /** Fires for EVERY structured event (backward compat). Specialized callbacks below fire in addition. */
  onStructured?: (payload: TriageStructuredPayload) => void
  onUserContext?: (payload: UserContextPayload) => void
  onEmergency?: (payload: EmergencyPayload) => void
  onDrugInteractions?: (payload: DrugInteractionsPayload) => void
  onMimsInfo?: (payload: MimsInfoPayload) => void
  onSuggestPharmacist?: (payload: SuggestPharmacistPayload) => void
  onState?: (payload: StatePayload) => void
}
