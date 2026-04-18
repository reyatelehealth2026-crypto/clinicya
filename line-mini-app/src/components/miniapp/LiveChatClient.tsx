'use client'

import Script from 'next/script'
import { appConfig } from '@/lib/config'
import { miniappChannelCopy } from '@/lib/miniapp-channel-copy'
import { openLineOfficialAccountChat } from '@/lib/open-line-oa-chat'
import { AppShell } from '@/components/miniapp/AppShell'

export function LiveChatClient() {
  const scriptSrc = appConfig.liveChatScriptSrc
  const showOaFooter = appConfig.isLineOaChatConfigured
  const copy = miniappChannelCopy

  return (
    <AppShell
      title={copy.liveChat.titleTh}
      subtitle={`${copy.liveChat.titleEn} · ${copy.liveChat.subtitleTh}`}
    >
      <div className="space-y-4">
        <div className="rounded-2xl bg-white p-4 shadow-soft">
          <p className="text-sm font-semibold text-slate-900">{copy.liveChat.subtitleTh}</p>
          <p className="mt-1 text-xs text-slate-500">{copy.liveChat.subtitleEn}</p>
        </div>

        {scriptSrc ? (
          <Script src={scriptSrc} strategy="afterInteractive" />
        ) : (
          <div className="rounded-2xl border border-amber-100 bg-amber-50 p-4 text-sm text-amber-900">
            <p className="font-semibold">{copy.liveChat.unavailableTh}</p>
            <p className="mt-1 text-xs text-amber-800">{copy.liveChat.unavailableEn}</p>
          </div>
        )}

        {showOaFooter ? (
          <div className="space-y-2 rounded-2xl border border-slate-100 bg-slate-50/80 p-4">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">
              {copy.lineOa.titleEn}
            </p>
            <p className="text-sm text-slate-700">{copy.lineOa.subtitleTh}</p>
            <button
              type="button"
              onClick={() => openLineOfficialAccountChat(appConfig.lineOaChatUrl)}
              className="mt-2 w-full rounded-xl bg-line py-2.5 text-sm font-semibold text-white shadow-soft transition-colors hover:bg-line/90"
            >
              {copy.lineOa.titleTh}
            </button>
          </div>
        ) : null}
      </div>
    </AppShell>
  )
}
