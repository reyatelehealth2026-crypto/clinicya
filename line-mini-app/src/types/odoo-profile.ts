export interface OdooCustomerProfile {
  partner_id: number
  partner_name: string | null
  customer_code: string | null
  email: string | null
  phone: string | null
  linked_via: 'phone' | 'customer_code' | 'email' | string
  linked_at: string | null
  notification_enabled: boolean
  credit_limit: number | null
  credit_used: number | null
  total_due?: number | null
}

export interface OdooProfileResponse {
  success: boolean
  data?: OdooCustomerProfile
  error?: string
}
