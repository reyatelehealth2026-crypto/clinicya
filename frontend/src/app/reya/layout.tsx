import type { Metadata } from 'next';

export const metadata: Metadata = {
  title: 'REYA Dashboard — Interactive Prototype',
  description:
    'แดชบอร์ดทดลองสำหรับร้านยา/Telepharmacy ของ REYA — Inbox + AI Co-Pilot, ออเดอร์, ลูกค้า CRM, Analytics, Telepharmacy',
  robots: {
    follow: false,
    index: false,
  },
};

export default function ReyaLayout({ children }: { children: React.ReactNode }) {
  return children;
}
