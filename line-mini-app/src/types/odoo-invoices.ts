export interface OdooInvoice {
  id: number
  name: string
  date: string
  invoice_date_due?: string | null
  state: 'draft' | 'posted' | 'overdue' | 'cancel' | string
  payment_state: 'paid' | 'partial' | 'not_paid' | 'unpaid' | string
  amount_total: number
  amount_residual?: number | null
  currency_id?: [number, string] | null
  partner_name?: string | null
}

export interface OdooInvoicesResponse {
  success: boolean
  data?: OdooInvoice[] | { invoices: OdooInvoice[] }
  error?: string
}

export interface OdooCreditStatus {
  credit_limit: number
  credit_used: number
  overdue_amount: number
  total_due?: number
  partner_name?: string
}

export interface OdooCreditStatusResponse {
  success: boolean
  data?: OdooCreditStatus
  error?: string
}

export interface OdooSlipUploadResult {
  matched?: boolean
  status?: string
  order_name?: string
  amount?: number
}

export interface OdooSlipUploadResponse {
  success: boolean
  data?: OdooSlipUploadResult
  error?: string
}
