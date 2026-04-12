import { NextRequest, NextResponse } from 'next/server'

const PHP_API_BASE = (process.env.NEXT_PUBLIC_PHP_API_BASE_URL || 'https://cny.re-ya.com').replace(/\/$/, '')

/** Proxy to PHP `api/checkout.php` (products, cart, my_orders, create_order, …) */
export async function GET(request: NextRequest) {
  try {
    const action = request.nextUrl.searchParams.get('action')
    const url = new URL(`${PHP_API_BASE}/api/checkout.php`)
    request.nextUrl.searchParams.forEach((v, k) => {
      url.searchParams.set(k, v)
    })
    const res = await fetch(url.toString(), { method: 'GET', cache: 'no-store' })

    // `promptpay_qr` returns PNG bytes, not JSON
    if (action === 'promptpay_qr') {
      const body = await res.arrayBuffer()
      const ct = res.headers.get('content-type') || 'image/png'
      return new NextResponse(body, {
        status: res.status,
        headers: {
          'Content-Type': ct,
          'Cache-Control': res.headers.get('cache-control') || 'public, max-age=3600'
        }
      })
    }

    const data = await res.json()
    return NextResponse.json(data, { status: res.status })
  } catch (error) {
    return NextResponse.json(
      { success: false, message: error instanceof Error ? error.message : 'Proxy error' },
      { status: 500 }
    )
  }
}

export async function POST(request: NextRequest) {
  try {
    const body = await request.json()
    const res = await fetch(`${PHP_API_BASE}/api/checkout.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
      cache: 'no-store'
    })
    const data = await res.json()
    return NextResponse.json(data, { status: res.status })
  } catch (error) {
    return NextResponse.json(
      { success: false, message: error instanceof Error ? error.message : 'Proxy error' },
      { status: 500 }
    )
  }
}
