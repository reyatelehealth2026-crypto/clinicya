'use client'

import { useState } from 'react'
import { AlertTriangle, Crown, QrCode, RotateCcw, ShieldCheck, Sparkles } from 'lucide-react'
import type { MemberProfile, TierInfo } from '@/types/member'
import type { HealthProfile } from '@/lib/health-api'
import { MEDICAL_CONDITIONS } from '@/lib/health-api'

interface FlipMemberCardProps {
  member: MemberProfile
  tier: TierInfo
  health?: HealthProfile | null
}

const BLOOD_LABEL: Record<string, string> = {
  A: 'A', B: 'B', AB: 'AB', O: 'O', unknown: '—'
}

const GENDER_LABEL: Record<string, string> = {
  male: 'ชาย',
  female: 'หญิง',
  other: 'อื่นๆ',
  ชาย: 'ชาย',
  หญิง: 'หญิง'
}

function formatNum(n: number | null | undefined): string {
  if (n == null) return '—'
  return Number.isInteger(n) ? String(n) : String(Math.round(n * 10) / 10)
}

function conditionLabel(key: string): string {
  return MEDICAL_CONDITIONS.find(c => c.key === key)?.label || key
}

function buildQrUrl(memberId: string): string {
  const payload = `REYA:MEMBER:${memberId}`
  return `https://api.qrserver.com/v1/create-qr-code/?size=320x320&margin=8&data=${encodeURIComponent(payload)}`
}

/**
 * Compact member pass with flip-to-QR.
 * The front groups personal fields into one ID-card style information box.
 */
export function FlipMemberCard({ member, tier, health }: FlipMemberCardProps) {
  const [flipped, setFlipped] = useState(false)

  const displayName =
    member.display_name ||
    [member.first_name, member.last_name].filter(Boolean).join(' ') ||
    'LINE User'

  const idCardName = health?.personal_info?.name || displayName
  const age = health?.personal_info?.age ?? null
  const gender = health?.personal_info?.gender || member.gender || ''
  const bloodType = health?.personal_info?.blood_type ?? 'unknown'
  const weight = health?.personal_info?.weight ?? member.weight ?? null
  const height = health?.personal_info?.height ?? member.height ?? null
  const conditions = health?.medical_conditions ?? []
  const allergies = health?.allergies ?? []
  const progress = Math.min(Math.max(tier.progress_percent || 0, 0), 100)
  const nextTier = tier.next_tier_name || 'ระดับสูงสุด'
  const fallbackInitial = displayName.trim().charAt(0) || 'R'

  return (
    <div className="w-full [perspective:1200px]">
      <button
        type="button"
        onClick={() => setFlipped(v => !v)}
        aria-label={flipped ? 'แตะเพื่อกลับสู่บัตรสมาชิก' : 'แตะเพื่อดู QR สะสมแต้ม'}
        className="relative block w-full text-left [transform-style:preserve-3d] transition-transform duration-500 ease-out active:scale-[0.99]"
        style={{ transform: flipped ? 'rotateY(180deg)' : 'rotateY(0deg)', minHeight: 244 }}
      >
        <div className="relative min-h-[244px] overflow-hidden rounded-[1.45rem] bg-gradient-to-br from-[#0b5f50] via-[#187162] to-[#082d28] p-3.5 text-white shadow-card ring-1 ring-white/10 [backface-visibility:hidden]">
          <div className="absolute -right-12 -top-14 h-32 w-32 rounded-full bg-white/12 blur-sm" aria-hidden />
          <div className="absolute -bottom-12 left-8 h-24 w-24 rounded-full bg-line-muted/20 blur-md" aria-hidden />
          <div className="absolute inset-x-0 top-0 h-16 bg-gradient-to-b from-white/10 to-transparent" aria-hidden />

          <div className="relative flex items-start justify-between gap-3">
            <div>
              <p className="text-[10px] font-bold uppercase tracking-[0.22em] text-white/62">REYA Member Pass</p>
              <p className="mt-1 font-mono text-[10px] tracking-wider text-white/48">{member.member_id}</p>
            </div>
            <span className="inline-flex items-center gap-1.5 rounded-full bg-white/16 px-2.5 py-1 text-[10px] font-bold text-white backdrop-blur-sm ring-1 ring-white/15">
              <Crown size={12} aria-hidden />
              {tier.tier_name || 'Bronze'}
            </span>
          </div>

          <div className="relative mt-3 flex items-center gap-2.5">
            {member.picture_url ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img
                src={member.picture_url}
                alt={displayName}
                className="h-12 w-12 rounded-2xl border border-white/30 object-cover shadow-md"
              />
            ) : (
              <div className="flex h-12 w-12 items-center justify-center rounded-2xl border border-white/25 bg-white/16 text-lg font-black shadow-md">
                {fallbackInitial}
              </div>
            )}
            <div className="min-w-0 flex-1">
              <p className="truncate text-lg font-black leading-tight">{displayName}</p>
              <div className="mt-1 inline-flex items-center gap-1.5 rounded-full bg-white/12 px-2 py-0.5 text-[9px] font-semibold text-white/72 ring-1 ring-white/10">
                <ShieldCheck size={11} aria-hidden />
                ยืนยันสมาชิกแล้ว
              </div>
            </div>
          </div>

          <div className="relative mt-3 rounded-2xl bg-white/14 p-3 ring-1 ring-white/15">
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <p className="text-[9px] font-semibold text-white/55">ชื่อ-นามสกุล</p>
                <p className="mt-0.5 truncate text-sm font-extrabold text-white">{idCardName}</p>
              </div>
              <div className="shrink-0 text-right">
                <p className="text-[9px] font-semibold text-white/55">หมู่เลือด</p>
                <p className="mt-0.5 text-sm font-black text-white">{BLOOD_LABEL[bloodType || 'unknown'] || '—'}</p>
              </div>
            </div>

            <div className="mt-2 grid grid-cols-4 gap-x-2 gap-y-1 text-[10px]">
              <PersonalField label="อายุ" value={formatNum(age)} unit={age != null ? 'ปี' : ''} />
              <PersonalField label="เพศ" value={GENDER_LABEL[gender] || '—'} />
              <PersonalField label="น้ำหนัก" value={formatNum(weight)} unit={weight != null ? 'กก.' : ''} />
              <PersonalField label="ส่วนสูง" value={formatNum(height)} unit={height != null ? 'ซม.' : ''} />
            </div>
          </div>

          <div className="relative mt-2 flex items-center gap-2 rounded-2xl bg-white/10 px-3 py-2 ring-1 ring-white/12">
            <div className="min-w-0 flex-1">
              <p className="text-[9px] font-semibold text-white/55">แต้มสะสม</p>
              <p className="text-lg font-black leading-none tabular-nums">{member.points.toLocaleString()}</p>
            </div>
            <div className="h-8 w-px bg-white/15" aria-hidden />
            <div className="min-w-0 flex-1">
              <p className="text-[9px] font-semibold text-white/55">ออเดอร์</p>
              <p className="text-lg font-black leading-none tabular-nums">{(member.total_orders ?? 0).toLocaleString()}</p>
            </div>
            <div className="h-8 w-px bg-white/15" aria-hidden />
            <div className="min-w-0 flex-[1.25]">
              <div className="flex items-center justify-between gap-2">
                <p className="truncate text-[9px] font-semibold text-white/60">
                  {progress >= 100 ? 'ระดับสูงสุดแล้ว' : `ไปยัง ${nextTier}`}
                </p>
                <p className="text-[9px] font-black text-white">{Math.round(progress)}%</p>
              </div>
              <div className="mt-1 h-1 overflow-hidden rounded-full bg-white/18">
                <div className="h-full rounded-full bg-white transition-all duration-700 ease-out" style={{ width: `${progress}%` }} />
              </div>
            </div>
          </div>

          {allergies.length > 0 && (
            <div className="relative mt-2 flex items-center gap-1.5 rounded-xl bg-rose-500/20 px-2.5 py-1.5 ring-1 ring-rose-300/30">
              <AlertTriangle size={11} className="shrink-0 text-rose-200" />
              <span className="text-[9px] font-bold uppercase tracking-wider text-rose-100">แพ้ยา</span>
              <span className="min-w-0 flex-1 truncate text-[11px] font-semibold text-white">
                {allergies.slice(0, 3).map(a => a.drug_name).join(', ')}
                {allergies.length > 3 ? ` +${allergies.length - 3}` : ''}
              </span>
            </div>
          )}

          {conditions.length > 0 && (
            <div className="relative mt-1.5 flex flex-nowrap items-center gap-1 overflow-hidden">
              <span className="text-[9px] font-semibold uppercase tracking-wider text-white/55">โรค</span>
              {conditions.slice(0, 3).map(c => (
                <span
                  key={c}
                  className="truncate rounded-full bg-white/12 px-2 py-0.5 text-[10px] font-semibold text-white backdrop-blur-sm ring-1 ring-white/10"
                >
                  {conditionLabel(c)}
                </span>
              ))}
              {conditions.length > 3 && (
                <span className="shrink-0 text-[9px] font-medium text-white/55">+{conditions.length - 3}</span>
              )}
            </div>
          )}

          <div className="relative mt-2 flex items-center justify-center gap-1.5 text-white/58">
            <QrCode size={11} />
            <span className="text-[10px] font-semibold">แตะเพื่อดู QR สะสมแต้ม</span>
          </div>
        </div>

        <div
          className="absolute inset-0 overflow-hidden rounded-[1.45rem] bg-white p-3.5 shadow-card ring-1 ring-slate-100 [backface-visibility:hidden]"
          style={{ transform: 'rotateY(180deg)' }}
        >
          <div className="flex h-full flex-col">
            <div className="flex items-center justify-between">
              <p className="text-[10px] font-bold uppercase tracking-[0.18em] text-slate-400">REYA Rewards</p>
              <span className="inline-flex items-center gap-1 rounded-full bg-line-soft px-2.5 py-1 text-[10px] font-bold text-line">
                <Sparkles size={11} aria-hidden />
                {tier.tier_name || 'Bronze'}
              </span>
            </div>

            <div className="mt-3 flex flex-1 flex-col items-center justify-center rounded-3xl bg-slate-50 px-4 py-4">
              <div className="rounded-2xl border border-slate-100 bg-white p-2.5 shadow-sm">
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img
                  src={buildQrUrl(member.member_id)}
                  alt={`QR member ${member.member_id}`}
                  className="block h-28 w-28"
                  loading="lazy"
                />
              </div>
              <p className="mt-3 max-w-full truncate text-sm font-extrabold text-slate-800">{displayName}</p>
              <p className="mt-0.5 font-mono text-[10px] tracking-wider text-slate-400">{member.member_id}</p>
              <p className="mt-2 rounded-full bg-white px-3 py-1 text-[11px] font-semibold text-slate-500 shadow-sm">
                แต้มสะสม <span className="font-black tabular-nums text-line">{member.points.toLocaleString()}</span>
              </p>
            </div>

            <div className="mt-3 flex items-center justify-center gap-1.5 text-slate-400">
              <RotateCcw size={11} />
              <span className="text-[10px] font-semibold">แตะเพื่อกลับ</span>
            </div>
          </div>
        </div>
      </button>
    </div>
  )
}

function PersonalField({ label, value, unit }: { label: string; value: string; unit?: string }) {
  return (
    <div className="min-w-0">
      <span className="block text-[9px] font-medium leading-tight text-white/55">{label}</span>
      <span className="mt-0.5 block truncate text-[11px] font-bold leading-tight tabular-nums text-white">
        {value}
        {unit && <span className="ml-0.5 text-[8px] font-medium text-white/55">{unit}</span>}
      </span>
    </div>
  )
}
