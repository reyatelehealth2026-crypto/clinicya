/** B2C shop order row (from `transactions` + normalized fields for UI). */
export interface ShopOrder {
  id: number
  order_number: string
  order_name: string
  order_id: number
  status: string | null
  payment_status: string | null
  grand_total: number | string | null
  amount_total: number | null
  date_order: string | null
  created_at?: string | null
  tracking_number?: string | null
  line_account_id?: number | null
  state: string | null
  items_count: number
  is_paid: boolean
  is_delivered: boolean
}

export interface ShopOrdersResponse {
  success: boolean
  orders: ShopOrder[]
  total: number
  source: string
  limit: number
  offset: number
}
