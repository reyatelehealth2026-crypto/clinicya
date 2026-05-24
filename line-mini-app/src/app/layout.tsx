import type { Metadata } from 'next'
import { Inter, Noto_Sans_Thai } from 'next/font/google'
import './globals.css'
import { Providers } from '@/components/providers'
import { appConfig } from '@/lib/config'

const inter = Inter({ subsets: ['latin'], variable: '--font-inter' })
const notoSansThai = Noto_Sans_Thai({ subsets: ['thai'], variable: '--font-noto-sans-thai' })

export const metadata: Metadata = {
  title: appConfig.miniAppName,
  description: 'LINE Mini App for member profile and rewards'
}

export const viewport = {
  width: 'device-width',
  initialScale: 1,
  maximumScale: 1,
  userScalable: false,
  themeColor: '#06C755',
  viewportFit: 'cover'
}

// Plausible Analytics — using standard CDN (no proxy). Some ad-blockers will
// block this, but most Thai users don't have aggressive ad-blockers. If/when
// we need 100% capture we'll proxy through Cloudflare Worker at /_pa/*.
const PLAUSIBLE_SCRIPT = 'https://plausible.io/js/script.outbound-links.tagged-events.js'

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="th">
      <head>
        <script defer data-domain="re-ya.com" src={PLAUSIBLE_SCRIPT} />
      </head>
      <body className={`${inter.variable} ${notoSansThai.variable}`}>
        <Providers>{children}</Providers>
      </body>
    </html>
  )
}
