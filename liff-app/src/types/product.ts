export interface Product {
  id: number
  name: string
  description: string | null
  price: number
  sale_price: number | null
  image_url: string | null
  category: string | null
  in_stock: boolean
  unit: string | null
}

export type OrderStatus = 'pending' | 'confirmed' | 'processing' | 'shipped' | 'delivered' | 'cancelled'

export interface OrderItem {
  product_id: number
  product_name: string
  image_url: string | null
  quantity: number
  unit_price: number
  total: number
}

export interface Order {
  id: number
  order_number: string
  status: OrderStatus
  items: OrderItem[]
  subtotal: number
  discount: number
  shipping: number
  total: number
  payment_method: string | null
  created_at: string
  updated_at: string
}
