import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import type { Product } from '@/types/product'

export interface CartItem {
  product: Product
  quantity: number
}

interface CartState {
  items: CartItem[]
  addItem: (product: Product, qty?: number) => void
  removeItem: (productId: number) => void
  updateQuantity: (productId: number, qty: number) => void
  clearCart: () => void
  getSubtotal: () => number
  getItemCount: () => number
}

export const useCartStore = create<CartState>()(
  persist(
    (set, get) => ({
      items: [],
      addItem: (product, qty = 1) =>
        set((state) => {
          const existing = state.items.find((i) => i.product.id === product.id)
          if (existing) {
            return { items: state.items.map((i) => i.product.id === product.id ? { ...i, quantity: i.quantity + qty } : i) }
          }
          return { items: [...state.items, { product, quantity: qty }] }
        }),
      removeItem: (productId) => set((state) => ({ items: state.items.filter((i) => i.product.id !== productId) })),
      updateQuantity: (productId, qty) =>
        set((state) => ({
          items: qty <= 0
            ? state.items.filter((i) => i.product.id !== productId)
            : state.items.map((i) => i.product.id === productId ? { ...i, quantity: qty } : i),
        })),
      clearCart: () => set({ items: [] }),
      getSubtotal: () => get().items.reduce((sum, i) => sum + (i.product.sale_price || i.product.price) * i.quantity, 0),
      getItemCount: () => get().items.reduce((sum, i) => sum + i.quantity, 0),
    }),
    { name: 'reya-cart' },
  ),
)
