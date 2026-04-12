import { env } from '@/config/env'
import type { ApiResponse } from '@/types/api'

const BASE_URL = env.API_BASE_URL

interface FetchOptions extends RequestInit {
  retries?: number
}

export async function apiClient<T = unknown>(
  path: string,
  options: FetchOptions = {},
): Promise<ApiResponse<T>> {
  const { retries = 3, ...fetchOptions } = options
  const url = path.startsWith('http') ? path : `${BASE_URL}${path}`

  for (let attempt = 0; attempt < retries; attempt++) {
    try {
      const response = await fetch(url, {
        headers: {
          'Content-Type': 'application/json',
          ...fetchOptions.headers,
        },
        ...fetchOptions,
      })

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`)
      }

      const data = await response.json() as ApiResponse<T>
      return data
    } catch (error) {
      if (attempt === retries - 1) {
        console.error(`API call failed after ${retries} attempts:`, path, error)
        return {
          success: false,
          error: error instanceof Error ? error.message : 'Unknown error',
        }
      }
      await new Promise(r => setTimeout(r, Math.pow(2, attempt) * 1000))
    }
  }

  return { success: false, error: 'Request failed' }
}

export function buildUrl(path: string, params: Record<string, string | number | undefined>): string {
  const url = new URL(path, BASE_URL)
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined) {
      url.searchParams.set(key, String(value))
    }
  }
  return url.toString()
}
