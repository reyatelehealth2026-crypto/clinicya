import type { Metadata } from 'next';
import type { ReactNode } from 'react';

/**
 * Root layout — required by the App Router. Deliberately minimal: the real
 * chrome lives in (tenant)/layout.tsx and (platform)/layout.tsx; (public)
 * pages (e.g. /auth/login) render directly under this bare shell.
 */
export const metadata: Metadata = {
  title: 'Reya Admin',
};

export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="th">
      <body>{children}</body>
    </html>
  );
}
