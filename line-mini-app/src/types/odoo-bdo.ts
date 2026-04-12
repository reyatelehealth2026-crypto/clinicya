export interface OdooBdo {
  id: number
  bdo_id?: number | null
  bdo_ref: string
  amount_total: number
  amount_paid?: number | null
  amount_residual?: number | null
  state: 'draft' | 'open' | 'paid' | 'cancel' | string
  customer_ref?: string | null
  partner_id?: number | null
  partner_name?: string | null
  line_user_id?: string | null
  due_date?: string | null
  created_at?: string | null
  updated_at?: string | null
  has_qr?: boolean
  invoice_ids?: number[]
}

export interface OdooBdoListResponse {
  success: boolean
  data?: OdooBdo[]
  total?: number
  error?: string
}

export interface OdooBdoDetailResponse {
  success: boolean
  data?: OdooBdo
  error?: string
}
