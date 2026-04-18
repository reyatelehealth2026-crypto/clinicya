/**
 * Open LINE Official Account chat URL.
 * Prefer LIFF openWindow so the OA room opens in the LINE client when available.
 */
export function openLineOfficialAccountChat(url: string): void {
  const target = url.trim()
  if (!target) return

  const liff = (
    window as unknown as {
      liff?: { openWindow?: (opts: { url: string; external?: boolean }) => void }
    }
  ).liff

  if (liff?.openWindow) {
    liff.openWindow({ url: target, external: true })
  } else {
    window.location.href = target
  }
}
