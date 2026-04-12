export function LoadingOverlay() {
  return (
    <div className="fixed inset-0 bg-white z-[100] flex items-center justify-center">
      <div className="flex flex-col items-center gap-3">
        <div className="w-10 h-10 border-3 border-primary border-t-transparent rounded-full animate-spin" />
        <p className="text-sm text-gray-500">กำลังโหลด...</p>
      </div>
    </div>
  )
}
