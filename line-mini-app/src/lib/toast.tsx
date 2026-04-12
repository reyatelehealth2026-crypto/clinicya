'use client'

import { createContext, useCallback, useContext, useRef, useState, type ReactNode } from 'react'

export type ToastType = 'success' | 'error' | 'info' | 'warning'

export interface Toast {
  id: string
  message: string
  type: ToastType
}

interface ToastContextType {
  toasts: Toast[]
  toast: {
    success: (msg: string) => void
    error: (msg: string) => void
    info: (msg: string) => void
    warning: (msg: string) => void
  }
  dismiss: (id: string) => void
}

const ToastContext = createContext<ToastContextType | null>(null)

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([])
  const counter = useRef(0)

  const dismiss = useCallback((id: string) => {
    setToasts(prev => prev.filter(t => t.id !== id))
  }, [])

  const add = useCallback((message: string, type: ToastType, duration = 3500) => {
    const id = String(++counter.current)
    setToasts(prev => [...prev, { id, message, type }])
    setTimeout(() => dismiss(id), duration)
  }, [dismiss])

  const toast = {
    success: (msg: string) => add(msg, 'success'),
    error:   (msg: string) => add(msg, 'error', 4500),
    info:    (msg: string) => add(msg, 'info'),
    warning: (msg: string) => add(msg, 'warning'),
  }

  return (
    <ToastContext.Provider value={{ toasts, toast, dismiss }}>
      {children}
    </ToastContext.Provider>
  )
}

export function useToast() {
  const ctx = useContext(ToastContext)
  if (!ctx) throw new Error('useToast must be used within ToastProvider')
  return ctx
}
