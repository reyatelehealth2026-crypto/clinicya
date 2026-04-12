let toastContainer: HTMLDivElement | null = null

function getContainer() {
  if (!toastContainer) {
    toastContainer = document.createElement('div')
    toastContainer.className = 'fixed top-4 left-1/2 -translate-x-1/2 z-[9999] flex flex-col gap-2 pointer-events-none'
    document.body.appendChild(toastContainer)
  }
  return toastContainer
}

const colors: Record<string, string> = {
  success: 'bg-green-600',
  error: 'bg-red-600',
  warning: 'bg-amber-600',
  info: 'bg-blue-600',
}

export function showToast(message: string, type: 'success' | 'error' | 'warning' | 'info' = 'info') {
  const container = getContainer()
  const el = document.createElement('div')
  el.className = `${colors[type]} text-white text-sm px-4 py-2.5 rounded-xl shadow-lg pointer-events-auto animate-fade-in max-w-[90vw] text-center`
  el.textContent = message
  container.appendChild(el)
  setTimeout(() => {
    el.style.opacity = '0'
    el.style.transition = 'opacity 300ms'
    setTimeout(() => el.remove(), 300)
  }, 2500)
}
