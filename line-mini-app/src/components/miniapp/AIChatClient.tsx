'use client'

import { useState, useRef, useEffect, useCallback } from 'react'
import { Send, Bot, User, AlertCircle } from 'lucide-react'
import { useLineContext } from '@/components/providers'
import { AppShell } from '@/components/miniapp/AppShell'
import { streamAIChat } from '@/lib/ai-chat-api'
import { useToast } from '@/lib/toast'
import type { ChatMessage, ChatHistoryItem } from '@/types/ai-chat'

function generateId(): string {
  return `${Date.now()}-${Math.random().toString(36).slice(2, 11)}`
}

function LoadingDots() {
  return (
    <div className="flex gap-1 py-1">
      <div className="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style={{ animationDelay: '0ms' }} />
      <div className="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style={{ animationDelay: '150ms' }} />
      <div className="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style={{ animationDelay: '300ms' }} />
    </div>
  )
}

export function AIChatClient() {
  const line = useLineContext()
  const { toast } = useToast()
  const lineUserId = line.profile?.userId || ''
  
  const [messages, setMessages] = useState<ChatMessage[]>([
    {
      id: generateId(),
      role: 'assistant',
      content: 'สวัสดีครับ ผม AI ผู้ช่วยเภสัชกร 💊\nมีอะไรให้ช่วยเหลือไหมครับ? เช่น สอบถามอาการป่วยเบื้องต้น หรือข้อมูลยา',
      timestamp: new Date()
    }
  ])
  const [input, setInput] = useState('')
  const [isStreaming, setIsStreaming] = useState(false)
  const [streamingContent, setStreamingContent] = useState('')
  const bottomRef = useRef<HTMLDivElement>(null)
  const abortControllerRef = useRef<AbortController | null>(null)

  // Auto-scroll to bottom
  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: 'smooth' })
  }, [messages, streamingContent])

  const getHistory = useCallback((): ChatHistoryItem[] => {
    return messages.slice(-10).map(m => ({
      role: m.role,
      content: m.content
    }))
  }, [messages])

  const handleSend = async () => {
    const text = input.trim()
    if (!text || isStreaming) return

    // Add user message
    const userMsg: ChatMessage = {
      id: generateId(),
      role: 'user',
      content: text,
      timestamp: new Date()
    }
    setMessages(prev => [...prev, userMsg])
    setInput('')
    setIsStreaming(true)
    setStreamingContent('')

    const history = getHistory()
    let fullResponse = ''

    try {
      await streamAIChat(text, history, {
        onToken: (token) => {
          fullResponse += token
          setStreamingContent(fullResponse)
        },
        onComplete: () => {
          const aiMsg: ChatMessage = {
            id: generateId(),
            role: 'assistant',
            content: fullResponse || 'ขออภัย ไม่สามารถให้คำตอบได้',
            timestamp: new Date()
          }
          setMessages(prev => [...prev, aiMsg])
          setStreamingContent('')
          setIsStreaming(false)
        },
        onError: (error) => {
          console.error('AI Chat error:', error)
          toast.error('เกิดข้อผิดพลาด กรุณาลองใหม่')
          
          // Add error message
          const errorMsg: ChatMessage = {
            id: generateId(),
            role: 'assistant',
            content: 'ขออภัย ระบบ AI มีปัญหาชั่วคราว กรุณาลองใหม่หรือติดต่อเภสัชกร',
            timestamp: new Date()
          }
          setMessages(prev => [...prev, errorMsg])
          setStreamingContent('')
          setIsStreaming(false)
        }
      })
    } catch (error) {
      console.error('Failed to stream:', error)
      toast.error('ไม่สามารถเชื่อมต่อกับ AI ได้')
      setStreamingContent('')
      setIsStreaming(false)
    }
  }

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault()
      handleSend()
    }
  }

  return (
    <AppShell>
      <div className="flex flex-col h-[calc(100dvh-8rem)]">
        {/* Header */}
        <div className="px-4 py-3 border-b border-gray-100 bg-white">
          <div className="flex items-center gap-2">
            <div className="w-10 h-10 rounded-full bg-gradient-to-br from-purple-500 to-indigo-600 flex items-center justify-center">
              <Bot className="w-5 h-5 text-white" />
            </div>
            <div>
              <h1 className="font-semibold text-gray-900">ปรึกษาเภสัชกร AI</h1>
              <p className="text-xs text-green-600 flex items-center gap-1">
                <span className="w-1.5 h-1.5 bg-green-500 rounded-full" />
                ออนไลน์
              </p>
            </div>
          </div>
          <p className="mt-2 text-xs text-gray-500 flex items-start gap-1">
            <AlertCircle className="w-3 h-3 mt-0.5 shrink-0" />
            AI ให้คำแนะนำเบื้องต้นเท่านั้น ไม่ใช่การวินิจฉัยโรค
          </p>
        </div>

        {/* Messages */}
        <div className="flex-1 overflow-y-auto px-4 py-4 space-y-4 bg-gray-50">
          {messages.map((msg) => (
            <div
              key={msg.id}
              className={`flex gap-3 ${msg.role === 'user' ? 'flex-row-reverse' : ''}`}
            >
              <div
                className={`w-9 h-9 rounded-full flex items-center justify-center shrink-0 ${
                  msg.role === 'assistant'
                    ? 'bg-purple-100'
                    : 'bg-blue-100'
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

          <div ref={bottomRef} />
        </div>

        {/* Input */}
        <div className="bg-white border-t border-gray-100 px-4 py-3">
          <div className="flex items-center gap-2">
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
              onClick={handleSend}
              disabled={!input.trim() || isStreaming}
              className="w-11 h-11 bg-purple-600 text-white rounded-full flex items-center justify-center disabled:opacity-40 active:scale-90 transition-transform"
            >
              <Send className="w-4 h-4" />
            </button>
          </div>
          
          {/* Quick suggestions */}
          {!isStreaming && messages.length <= 2 && (
            <div className="mt-3 flex flex-wrap gap-2">
              {['ไข้หวัด', 'ปวดหัว', 'ท้องเสีย', 'แพ้อากาศ', 'ปรึกษาเภสัชกร'].map((suggestion) => (
                <button
                  key={suggestion}
                  onClick={() => {
                    setInput(suggestion)
                    setTimeout(() => handleSend(), 0)
                  }}
                  className="px-3 py-1.5 bg-purple-50 text-purple-700 text-xs rounded-full border border-purple-100 hover:bg-purple-100 transition-colors"
                >
                  {suggestion}
                </button>
              ))}
            </div>
          )}
        </div>
      </div>
    </AppShell>
  )
}
