import { useState, useRef, useEffect, useCallback } from 'react'
import { PageHeader } from '@/components/layout/PageHeader'
import { env } from '@/config/env'
import { Send, Bot, User } from 'lucide-react'

interface Message { id: number; role: 'user' | 'assistant'; content: string }
let msgId = 0

export function AIChatPage() {
  const [messages, setMessages] = useState<Message[]>([{ id: ++msgId, role: 'assistant', content: 'สวัสดีครับ ผม AI ผู้ช่วยเภสัชกร 💊\nมีอะไรให้ช่วยเหลือไหมครับ?' }])
  const [input, setInput] = useState('')
  const [loading, setLoading] = useState(false)
  const bottomRef = useRef<HTMLDivElement>(null)
  const abortRef = useRef<AbortController | null>(null)

  useEffect(() => { bottomRef.current?.scrollIntoView({ behavior: 'smooth' }) }, [messages])

  const handleSend = useCallback(async () => {
    const text = input.trim()
    if (!text || loading) return
    setMessages((prev) => [...prev, { id: ++msgId, role: 'user', content: text }])
    setInput('')
    setLoading(true)
    const aiId = ++msgId
    setMessages((prev) => [...prev, { id: aiId, role: 'assistant', content: '' }])

    const history = messages.filter((m) => m.content).map((m) => ({ role: m.role, content: m.content }))
    history.push({ role: 'user', content: text })

    try {
      abortRef.current = new AbortController()
      const res = await fetch(`${env.API_BASE_URL}/api/ai-chat.php`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ message: text, history }), signal: abortRef.current.signal })
      const reader = res.body?.getReader()
      const decoder = new TextDecoder()
      let buffer = ''
      if (reader) {
        while (true) {
          const { done, value } = await reader.read()
          if (done) break
          buffer += decoder.decode(value, { stream: true })
          const lines = buffer.split('\n'); buffer = lines.pop() || ''
          for (const line of lines) {
            const t = line.trim()
            if (!t || t === 'data: [DONE]') continue
            if (t.startsWith('data: ')) { try { const j = JSON.parse(t.slice(6)); if (j.token) setMessages((prev) => prev.map((m) => m.id === aiId ? { ...m, content: m.content + j.token } : m)) } catch { /* skip */ } }
          }
        }
      }
    } catch (err: unknown) {
      if ((err as Error).name !== 'AbortError') setMessages((prev) => prev.map((m) => m.id === aiId ? { ...m, content: 'เกิดข้อผิดพลาด กรุณาลองใหม่' } : m))
    } finally { setLoading(false); abortRef.current = null }
  }, [input, loading, messages])

  return (
    <div className="flex flex-col h-[calc(100dvh-4rem)]">
      <PageHeader title="ปรึกษาเภสัชกร AI" />
      <div className="flex-1 overflow-y-auto px-4 py-3 space-y-3">
        {messages.map((msg) => (
          <div key={msg.id} className={`flex gap-2 ${msg.role === 'user' ? 'flex-row-reverse' : ''}`}>
            <div className={`w-8 h-8 rounded-full flex items-center justify-center shrink-0 ${msg.role === 'assistant' ? 'bg-purple-100' : 'bg-primary/10'}`}>{msg.role === 'assistant' ? <Bot className="w-4 h-4 text-purple-600" /> : <User className="w-4 h-4 text-primary" />}</div>
            <div className={`max-w-[75%] px-3.5 py-2.5 rounded-2xl text-sm whitespace-pre-line ${msg.role === 'user' ? 'bg-primary text-white rounded-br-md' : 'bg-white text-gray-800 shadow-sm rounded-bl-md'}`}>{msg.content || '...'}</div>
          </div>
        ))}
        {loading && <div className="flex gap-2"><div className="w-8 h-8 rounded-full bg-purple-100 flex items-center justify-center shrink-0"><Bot className="w-4 h-4 text-purple-600" /></div><div className="bg-white shadow-sm rounded-2xl rounded-bl-md px-4 py-3"><div className="flex gap-1"><div className="w-2 h-2 bg-gray-300 rounded-full animate-bounce" style={{ animationDelay: '0ms' }} /><div className="w-2 h-2 bg-gray-300 rounded-full animate-bounce" style={{ animationDelay: '150ms' }} /><div className="w-2 h-2 bg-gray-300 rounded-full animate-bounce" style={{ animationDelay: '300ms' }} /></div></div></div>}
        <div ref={bottomRef} />
      </div>
      <div className="bg-white border-t border-gray-100 px-4 py-3">
        <div className="flex items-center gap-2">
          <input type="text" value={input} onChange={(e) => setInput(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && handleSend()} placeholder="พิมพ์ข้อความ..." className="flex-1 px-4 py-2.5 bg-gray-100 rounded-full text-sm focus:outline-none focus:ring-2 focus:ring-primary/30" />
          <button onClick={handleSend} disabled={!input.trim() || loading} className="w-10 h-10 bg-primary text-white rounded-full flex items-center justify-center disabled:opacity-40 active:scale-90 transition-transform"><Send className="w-4 h-4" /></button>
        </div>
      </div>
    </div>
  )
}
