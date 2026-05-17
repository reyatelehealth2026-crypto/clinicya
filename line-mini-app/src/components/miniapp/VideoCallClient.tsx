'use client'

import { useCallback, useEffect, useRef, useState } from 'react'
import Link from 'next/link'
import { useRouter } from 'next/navigation'
import { ArrowLeft, CalendarClock, Mic, MicOff, Video, VideoOff, PhoneOff, Phone } from 'lucide-react'
import { useLineContext } from '@/components/providers'
import {
  checkPharmacistOnline,
  createCall,
  endCall,
  getCallStatus,
  pollSignals,
  sendSignal,
  type OnlinePharmacist,
  type PolledSignal
} from '@/lib/video-call-api'

type PresenceState =
  | { status: 'loading' }
  | { status: 'unknown' } // endpoint missing / network error
  | { status: 'online'; pharmacists: OnlinePharmacist[] }
  | { status: 'offline' }

type CallState = 'idle' | 'requesting-media' | 'creating' | 'ringing' | 'connecting' | 'active' | 'ended' | 'error'

const ICE_SERVERS: RTCIceServer[] = [
  { urls: 'stun:stun.l.google.com:19302' },
  { urls: 'stun:stun1.l.google.com:19302' }
]

const POLL_INTERVAL_MS = 1500
const STATUS_POLL_INTERVAL_MS = 2000

function formatDuration(seconds: number): string {
  const m = Math.floor(seconds / 60)
  const s = seconds % 60
  return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`
}

export function VideoCallClient() {
  const line = useLineContext()
  const router = useRouter()
  const lineUserId = line.profile?.userId
  const displayName = line.profile?.displayName ?? 'ลูกค้า'
  const pictureUrl = line.profile?.pictureUrl

  const [state, setState] = useState<CallState>('idle')
  const [presence, setPresence] = useState<PresenceState>({ status: 'loading' })
  const [errorMsg, setErrorMsg] = useState<string>('')
  const [callId, setCallId] = useState<number | null>(null)
  const [duration, setDuration] = useState(0)
  const [micEnabled, setMicEnabled] = useState(true)
  const [camEnabled, setCamEnabled] = useState(true)
  const [overlayMessages, setOverlayMessages] = useState<Array<{ id: string; text: string }>>([])

  const localVideoRef = useRef<HTMLVideoElement | null>(null)
  const remoteVideoRef = useRef<HTMLVideoElement | null>(null)
  const pcRef = useRef<RTCPeerConnection | null>(null)
  const localStreamRef = useRef<MediaStream | null>(null)
  const remoteStreamRef = useRef<MediaStream | null>(null)
  const signalPollRef = useRef<ReturnType<typeof setInterval> | null>(null)
  const statusPollRef = useRef<ReturnType<typeof setInterval> | null>(null)
  const durationTimerRef = useRef<ReturnType<typeof setInterval> | null>(null)
  const callStartTimeRef = useRef<number>(0)
  const offerSentRef = useRef(false)
  const processedSignalIdsRef = useRef<Set<number>>(new Set())

  const cleanup = useCallback(() => {
    if (signalPollRef.current) { clearInterval(signalPollRef.current); signalPollRef.current = null }
    if (statusPollRef.current) { clearInterval(statusPollRef.current); statusPollRef.current = null }
    if (durationTimerRef.current) { clearInterval(durationTimerRef.current); durationTimerRef.current = null }
    if (localStreamRef.current) {
      localStreamRef.current.getTracks().forEach((t) => t.stop())
      localStreamRef.current = null
    }
    if (pcRef.current) {
      pcRef.current.close()
      pcRef.current = null
    }
    remoteStreamRef.current = null
    offerSentRef.current = false
    processedSignalIdsRef.current = new Set()
  }, [])

  const handleIncomingSignal = useCallback(async (sig: PolledSignal) => {
    const pc = pcRef.current
    if (!pc) return
    try {
      if (sig.signal_type === 'answer' && pc.signalingState !== 'stable') {
        await pc.setRemoteDescription(new RTCSessionDescription(sig.signal_data as RTCSessionDescriptionInit))
      } else if (sig.signal_type === 'ice-candidate') {
        await pc.addIceCandidate(new RTCIceCandidate(sig.signal_data as RTCIceCandidateInit))
      } else if (sig.signal_type === 'hangup') {
        await handleEnd()
      } else if (sig.signal_type === 'message') {
        // เภสัชกรส่งข้อความ overlay มา → แสดงบนจอ ~6s แล้วเฟดออก
        const data = sig.signal_data as { text?: string } | string
        const text = typeof data === 'string' ? data : (data?.text ?? '')
        if (text) {
          const id = String(sig.id)
          setOverlayMessages((prev) => [...prev, { id, text }])
          setTimeout(() => {
            setOverlayMessages((prev) => prev.filter((m) => m.id !== id))
          }, 6000)
        }
      }
    } catch (e) {
      if (typeof window !== 'undefined') {
        console.warn('signal handling error', e)
      }
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const handleEnd = useCallback(async () => {
    const id = callId
    const dur = duration
    cleanup()
    setState('ended')
    if (id) {
      try {
        await sendSignal(id, 'hangup', { reason: 'customer-end' })
        await endCall(id, dur)
      } catch {}
    }
  }, [callId, duration, cleanup])

  const startCall = useCallback(async () => {
    if (!lineUserId) {
      setErrorMsg('กรุณาเข้าสู่ระบบ LINE ก่อน')
      setState('error')
      return
    }
    try {
      setErrorMsg('')
      setState('requesting-media')

      // 1) request mic + camera
      const stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true })
      localStreamRef.current = stream
      if (localVideoRef.current) {
        localVideoRef.current.srcObject = stream
      }

      // 2) create call record on server (status='ringing')
      setState('creating')
      const created = await createCall({ userId: lineUserId, displayName, pictureUrl })
      if (!created.success || !created.call_id) {
        throw new Error(created.error ?? 'สร้างสายไม่สำเร็จ')
      }
      const newCallId = created.call_id
      setCallId(newCallId)
      setState('ringing')

      // 3) setup RTCPeerConnection
      const pc = new RTCPeerConnection({ iceServers: ICE_SERVERS })
      pcRef.current = pc

      stream.getTracks().forEach((track) => pc.addTrack(track, stream))

      pc.ontrack = (e) => {
        const remote = remoteStreamRef.current ?? new MediaStream()
        e.streams[0]?.getTracks().forEach((t) => {
          if (!remote.getTracks().includes(t)) remote.addTrack(t)
        })
        remoteStreamRef.current = remote
        if (remoteVideoRef.current && remoteVideoRef.current.srcObject !== remote) {
          remoteVideoRef.current.srcObject = remote
        }
      }

      pc.onicecandidate = (e) => {
        if (e.candidate) {
          sendSignal(newCallId, 'ice-candidate', e.candidate.toJSON()).catch(() => {})
        }
      }

      pc.onconnectionstatechange = () => {
        const cs = pc.connectionState
        if (cs === 'connected') {
          setState('active')
          if (callStartTimeRef.current === 0) {
            callStartTimeRef.current = Date.now()
            durationTimerRef.current = setInterval(() => {
              setDuration(Math.floor((Date.now() - callStartTimeRef.current) / 1000))
            }, 1000)
          }
        } else if (cs === 'failed' || cs === 'disconnected' || cs === 'closed') {
          handleEnd().catch(() => {})
        }
      }

      // 4) poll for status (wait admin to answer)
      statusPollRef.current = setInterval(async () => {
        try {
          const s = await getCallStatus(newCallId)
          if (!s.success) return
          if (s.status === 'active' && !offerSentRef.current) {
            offerSentRef.current = true
            setState('connecting')
            const offer = await pc.createOffer()
            await pc.setLocalDescription(offer)
            await sendSignal(newCallId, 'offer', offer)
          } else if (s.status === 'rejected' || s.status === 'completed' || s.status === 'missed') {
            handleEnd().catch(() => {})
          }
        } catch {}
      }, STATUS_POLL_INTERVAL_MS)

      // 5) poll incoming signals (answer + ICE from admin)
      signalPollRef.current = setInterval(async () => {
        try {
          const r = await pollSignals(newCallId)
          if (!r.success || !r.signals) return
          for (const sig of r.signals) {
            if (processedSignalIdsRef.current.has(sig.id) && sig.signal_type !== 'answer') continue
            processedSignalIdsRef.current.add(sig.id)
            await handleIncomingSignal(sig)
          }
        } catch {}
      }, POLL_INTERVAL_MS)
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'เกิดข้อผิดพลาด'
      setErrorMsg(msg)
      setState('error')
      cleanup()
    }
  }, [lineUserId, displayName, pictureUrl, cleanup, handleEnd, handleIncomingSignal])

  // Cleanup on unmount
  useEffect(() => {
    return () => {
      cleanup()
    }
  }, [cleanup])

  // Presence check on mount — show real pharmacist availability so users
  // don't waste time calling into an empty queue.
  useEffect(() => {
    let cancelled = false
    checkPharmacistOnline()
      .then((r) => {
        if (cancelled) return
        if (!r.success) {
          setPresence({ status: 'unknown' })
          return
        }
        const list = r.pharmacists ?? []
        if (r.online && list.length > 0) {
          setPresence({ status: 'online', pharmacists: list })
        } else if (r.online) {
          setPresence({ status: 'online', pharmacists: [] })
        } else {
          setPresence({ status: 'offline' })
        }
      })
      .catch(() => {
        if (!cancelled) setPresence({ status: 'unknown' })
      })
    return () => { cancelled = true }
  }, [])

  const toggleMic = useCallback(() => {
    const stream = localStreamRef.current
    if (!stream) return
    stream.getAudioTracks().forEach((t) => { t.enabled = !t.enabled })
    setMicEnabled((v) => !v)
  }, [])

  const toggleCam = useCallback(() => {
    const stream = localStreamRef.current
    if (!stream) return
    stream.getVideoTracks().forEach((t) => { t.enabled = !t.enabled })
    setCamEnabled((v) => !v)
  }, [])

  const statusLabel: Record<CallState, string> = {
    idle: 'พร้อมโทร',
    'requesting-media': 'กำลังขอสิทธิ์กล้อง/ไมค์...',
    creating: 'กำลังเชื่อมต่อ...',
    ringing: 'กำลังเรียก... รอเภสัชกรรับสาย',
    connecting: 'กำลังเชื่อมต่อภาพ/เสียง...',
    active: `กำลังสนทนา · ${formatDuration(duration)}`,
    ended: 'วางสายแล้ว',
    error: errorMsg || 'เกิดข้อผิดพลาด'
  }

  const inCall = state !== 'idle' && state !== 'ended' && state !== 'error'

  return (
    <div className="fixed inset-0 flex flex-col bg-slate-950 text-white">
      <header className="shrink-0 px-4 pt-safe-top pb-3 bg-gradient-to-b from-black/60 to-transparent">
        <div className="mx-auto flex max-w-md items-center">
          {state === 'idle' ? (
            <button
              type="button"
              onClick={() => router.back()}
              aria-label="ย้อนกลับ"
              className="flex h-9 w-9 items-center justify-center rounded-full bg-white/10 text-white active:scale-95"
            >
              <ArrowLeft size={18} />
            </button>
          ) : (
            <span className="h-9 w-9" aria-hidden />
          )}
          <div className="flex-1 text-center">
            <p className="text-sm font-semibold">ปรึกษาเภสัชกร</p>
            <p className="mt-1 text-xs text-slate-300">{statusLabel[state]}</p>
          </div>
          <span className="h-9 w-9" aria-hidden />
        </div>

        {state === 'idle' && presence.status === 'online' && presence.pharmacists.length > 0 ? (
          <div className="mx-auto mt-3 flex max-w-md flex-wrap items-center justify-center gap-2">
            {presence.pharmacists.slice(0, 3).map((p, i) => (
              <span
                key={p.id ?? i}
                className="inline-flex items-center gap-2 rounded-full bg-emerald-500/15 px-3 py-1 text-xs text-emerald-200"
              >
                {/* eslint-disable-next-line @next/next/no-img-element -- external avatar */}
                {p.avatar_url ? <img src={p.avatar_url} alt="" className="h-5 w-5 rounded-full object-cover" /> : null}
                <span className="font-medium">{p.name ?? 'เภสัชกร'}</span>
                <span className="h-1.5 w-1.5 rounded-full bg-emerald-400" aria-hidden />
                <span>ออนไลน์</span>
              </span>
            ))}
          </div>
        ) : null}

        {state === 'idle' && presence.status === 'unknown' ? (
          <div className="mx-auto mt-3 max-w-md rounded-xl bg-amber-500/20 px-3 py-2 text-center text-xs text-amber-100">
            ระบบไม่ทราบสถานะเภสัชกรในขณะนี้ — หากไม่มีผู้รับสาย ลองนัดหมายแทน
          </div>
        ) : null}
      </header>

      <div className="relative flex-1 overflow-hidden">
        <video
          ref={remoteVideoRef}
          autoPlay
          playsInline
          className="absolute inset-0 h-full w-full bg-slate-900 object-cover"
        />

        {state !== 'active' && (
          <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-slate-900/95 text-center">
            <div className="flex h-20 w-20 items-center justify-center rounded-full bg-emerald-500/20">
              {state === 'ringing' || state === 'connecting' ? (
                <div className="h-12 w-12 animate-pulse rounded-full bg-emerald-500/40" />
              ) : state === 'error' ? (
                <PhoneOff className="h-9 w-9 text-rose-400" />
              ) : (
                <Video className="h-9 w-9 text-emerald-400" />
              )}
            </div>
            <p className="text-sm text-slate-200">{statusLabel[state]}</p>
            {state === 'error' && (
              <button
                type="button"
                onClick={() => { setState('idle'); setErrorMsg('') }}
                className="mt-2 rounded-full bg-white/10 px-5 py-2 text-sm font-medium text-white"
              >
                ลองอีกครั้ง
              </button>
            )}
          </div>
        )}

        {inCall && (
          <div className="absolute right-3 top-3 h-32 w-24 overflow-hidden rounded-xl border border-white/20 bg-slate-800 shadow-lg">
            <video ref={localVideoRef} autoPlay playsInline muted className="h-full w-full object-cover" />
          </div>
        )}

        {/* ข้อความจากเภสัชกร (signal type='message' overlay) */}
        {overlayMessages.length > 0 && (
          <div className="pointer-events-none absolute inset-x-0 bottom-32 z-10 flex flex-col items-center gap-2 px-4">
            {overlayMessages.map((m) => (
              <div
                key={m.id}
                className="max-w-md rounded-2xl bg-emerald-600/95 px-4 py-2.5 text-center text-sm font-medium text-white shadow-xl backdrop-blur"
                style={{ animation: 'fadeInUp 0.4s ease' }}
              >
                💊 {m.text}
              </div>
            ))}
          </div>
        )}
      </div>

      <footer className="shrink-0 px-4 pb-safe-bottom pt-3 bg-gradient-to-t from-black/80 to-transparent">
        <div className="mx-auto flex max-w-md items-center justify-center gap-5">
          {state === 'idle' || state === 'ended' ? (
            presence.status === 'offline' ? (
              <div className="flex flex-col items-center gap-2">
                <p className="text-xs text-slate-300">ไม่มีเภสัชกรออนไลน์ขณะนี้</p>
                <Link
                  href="/appointments"
                  className="flex items-center gap-2 rounded-full bg-emerald-500 px-6 py-3 text-sm font-semibold text-white shadow-lg active:scale-95"
                >
                  <CalendarClock size={18} />
                  นัดหมายล่วงหน้า
                </Link>
              </div>
            ) : (
              <div className="flex flex-col items-center gap-2">
                <button
                  type="button"
                  onClick={startCall}
                  disabled={presence.status === 'loading'}
                  className="flex items-center gap-2 rounded-full bg-emerald-500 px-6 py-3 text-sm font-semibold text-white shadow-lg active:scale-95 disabled:opacity-60"
                >
                  <Phone size={18} />
                  {state === 'ended' ? 'โทรอีกครั้ง' : 'เริ่มโทรปรึกษา'}
                </button>
                {presence.status === 'unknown' ? (
                  <Link
                    href="/appointments"
                    className="text-xs text-slate-300 underline decoration-slate-500"
                  >
                    นัดหมายแทน
                  </Link>
                ) : null}
              </div>
            )
          ) : state === 'error' ? null : (
            <>
              <button
                type="button"
                onClick={toggleMic}
                aria-label={micEnabled ? 'ปิดไมค์' : 'เปิดไมค์'}
                className={`flex h-12 w-12 items-center justify-center rounded-full ${
                  micEnabled ? 'bg-white/15' : 'bg-rose-500/80'
                } text-white active:scale-95`}
              >
                {micEnabled ? <Mic size={20} /> : <MicOff size={20} />}
              </button>
              <button
                type="button"
                onClick={handleEnd}
                aria-label="วางสาย"
                className="flex h-14 w-14 items-center justify-center rounded-full bg-rose-500 text-white shadow-lg active:scale-95"
              >
                <PhoneOff size={22} />
              </button>
              <button
                type="button"
                onClick={toggleCam}
                aria-label={camEnabled ? 'ปิดกล้อง' : 'เปิดกล้อง'}
                className={`flex h-12 w-12 items-center justify-center rounded-full ${
                  camEnabled ? 'bg-white/15' : 'bg-rose-500/80'
                } text-white active:scale-95`}
              >
                {camEnabled ? <Video size={20} /> : <VideoOff size={20} />}
              </button>
            </>
          )}
        </div>
      </footer>
    </div>
  )
}
