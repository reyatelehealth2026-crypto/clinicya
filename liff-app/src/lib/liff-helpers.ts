import liff from '@line/liff'

export function closeLiff() {
  if (liff.isInClient()) liff.closeWindow()
}

export function shareLiff(text: string) {
  if (!liff.isInClient()) return
  liff.shareTargetPicker([{ type: 'text', text }]).catch(console.error)
}

export function openExternal(url: string) {
  if (liff.isInClient()) {
    liff.openWindow({ url, external: true })
  } else {
    window.open(url, '_blank')
  }
}
