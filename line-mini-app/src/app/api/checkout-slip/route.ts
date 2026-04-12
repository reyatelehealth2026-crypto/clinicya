import { NextRequest, NextResponse } from 'next/server'

const PHP_API_BASE = (process.env.NEXT_PUBLIC_PHP_API_BASE_URL || 'https://cny.re-ya.com').replace(/\/$/, '')

/** Multipart proxy for `action=upload_slip` on PHP `api/checkout.php`. */
export async function POST(request: NextRequest) {
  try {
    const formData = await request.formData()
    const res = await fetch(`${PHP_API_BASE}/api/checkout.php`, {
      method: 'POST',
      body: formData,
      cache: 'no-store'
    })
    const text = await res.text()
    let data: unknown
    try {
      data = JSON.parse(text)
    } catch {
      data = { success: false, message: text.slice(0, 200) || 'Invalid response from server' }
    }
    return NextResponse.json(data, { status: res.status })
  } catch (error) {
    return NextResponse.json(
      { success: false, message: error instanceof Error ? error.message : 'Proxy error' },
      { status: 500 }
    )
  }
}
