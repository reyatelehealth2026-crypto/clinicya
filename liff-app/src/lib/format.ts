export function formatNumber(n: number): string {
  return n.toLocaleString('th-TH')
}

export function formatCurrency(amount: number): string {
  return `฿${formatNumber(amount)}`
}

export function formatDate(dateStr: string | null | undefined): string {
  if (!dateStr) return '-'
  try {
    const date = new Date(dateStr)
    return date.toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: '2-digit' })
  } catch {
    return dateStr
  }
}

export function formatDateTime(dateStr: string | null | undefined): string {
  if (!dateStr) return '-'
  try {
    const date = new Date(dateStr)
    return date.toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: '2-digit', hour: '2-digit', minute: '2-digit' })
  } catch {
    return dateStr
  }
}

export function formatMemberId(id: string | number | null | undefined): string {
  if (!id || id === '-') return '-'
  const str = String(id).replace(/\D/g, '')
  if (str.length <= 4) return str
  return str.match(/.{1,4}/g)?.join(' ') ?? str
}

export function truncate(text: string, maxLength: number): string {
  if (text.length <= maxLength) return text
  return text.slice(0, maxLength) + '...'
}
