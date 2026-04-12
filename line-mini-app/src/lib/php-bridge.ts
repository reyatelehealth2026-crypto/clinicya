import { apiUrl } from '@/lib/config'

async function parseResponse<T>(response: Response): Promise<T> {
  const contentType = response.headers.get('content-type') || ''

  if (!contentType.includes('application/json')) {
    throw new Error('PHP API returned non-JSON response')
  }

  const data = (await response.json()) as T
  return data
}

export async function phpGet<T>(path: string, params?: Record<string, string | number | undefined>) {
  const url = new URL(apiUrl(path))

  if (params) {
    Object.entries(params).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') {
        url.searchParams.set(key, String(value))
      }
    })
  }

  const response = await fetch(url.toString(), {
    method: 'GET',
    cache: 'no-store'
  })

  return parseResponse<T>(response)
}

export async function phpPost<T>(path: string, body: Record<string, unknown>) {
  const response = await fetch(apiUrl(path), {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify(body)
  })

  return parseResponse<T>(response)
}
