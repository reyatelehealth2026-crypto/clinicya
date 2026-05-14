import Link from 'next/link';

export const dynamic = 'force-dynamic';

export default function Home() {
  const isDemoDashboardEnabled = process.env.ENABLE_DEMO_DASHBOARD === 'true';

  return (
    <main className="min-h-screen bg-secondary-50 px-6 py-12">
      <section className="mx-auto max-w-3xl rounded-2xl border border-secondary-200 bg-white p-8 shadow-sm">
        <p className="mb-3 text-sm font-semibold uppercase tracking-wide text-primary-600">
          CLINICYA Admin
        </p>
        <h1 className="text-3xl font-bold text-secondary-900">
          ศูนย์จัดการคำสั่งซื้อและข้อมูลลูกค้า
        </h1>
        <p className="mt-4 text-secondary-600">
          พื้นที่นี้สำหรับทีมงาน CLINICYA ใช้ตรวจคำสั่งซื้อ การชำระเงิน
          ลูกค้า และสถานะระบบ LINE/Odoo เท่านั้น
        </p>
        <div className="mt-8 flex flex-col gap-3 sm:flex-row">
          {isDemoDashboardEnabled ? (
            <Link
              href="/dashboard"
              className="inline-flex min-h-12 items-center justify-center rounded-xl bg-primary-600 px-5 py-3 font-semibold text-white transition-colors hover:bg-primary-700"
            >
              เข้าสู่แดชบอร์ดตัวอย่าง
            </Link>
          ) : (
            <span className="inline-flex min-h-12 items-center justify-center rounded-xl bg-secondary-200 px-5 py-3 font-semibold text-secondary-600">
              แดชบอร์ดยังไม่เปิดใช้งาน
            </span>
          )}
          <a
            href="https://clinicya.re-ya.com/"
            className="inline-flex min-h-12 items-center justify-center rounded-xl border border-secondary-300 px-5 py-3 font-semibold text-secondary-700 transition-colors hover:bg-secondary-50"
          >
            กลับหน้าเว็บไซต์
          </a>
          <Link
            href="/reya"
            prefetch={false}
            className="inline-flex min-h-12 items-center justify-center rounded-xl border border-emerald-300 bg-emerald-50 px-5 py-3 font-semibold text-emerald-800 transition-colors hover:bg-emerald-100"
          >
            ดูตัวอย่าง REYA Dashboard
          </Link>
        </div>
        <p className="mt-5 text-xs text-secondary-500">
          หมายเหตุ: ตั้งค่า `ENABLE_DEMO_DASHBOARD=true` เฉพาะสภาพแวดล้อมทดสอบเท่านั้น
          จนกว่าจะเชื่อมต่อระบบยืนยันตัวตนจริง
        </p>
      </section>
    </main>
  );
}
