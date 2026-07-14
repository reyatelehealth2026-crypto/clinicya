'use server';

import { sql, type Kysely } from 'kysely';
import { redirect } from 'next/navigation';
import nodemailer from 'nodemailer';
import type { TenantDB } from '@reya/db';
import { requireTenantPageContext } from '../../users/_lib/session';
import { getEmailSettings } from './email-queries';

/**
 * email-actions.ts — Server Actions for settings.php's `action ===
 * 'save_email'` (lines 659-683) and `action === 'test_email'` (lines
 * 684-698) handlers, reached via email.php's two `<form method="POST">`s.
 *
 *   save_email:
 *     $data = [smtp_host, (int) smtp_port ?? 587, smtp_user, smtp_pass,
 *              smtp_secure ?? 'tls', from_email, from_name ?? 'Notification'];
 *     INSERT INTO email_settings (id, ...) VALUES (1, ?, ?, ?, ?, ?, ?, ?)
 *       ON DUPLICATE KEY UPDATE ...;
 *     $success = 'บันทึกการตั้งค่า Email สำเร็จ';
 *
 *   test_email:
 *     if ($testEmail && filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
 *         $emailService = new EmailService($db);
 *         if ($emailService->sendTest($testEmail)) { $success = 'ส่ง Email ทดสอบสำเร็จไปยัง ' . $testEmail; }
 *         else { $error = 'ส่ง Email ไม่สำเร็จ - ตรวจสอบการตั้งค่า SMTP'; }
 *     } else {
 *         $error = 'กรุณาระบุ Email ที่ถูกต้อง';
 *     }
 *
 * Both redirect back to `/settings?tab=email` with a `?message=`/`?error=`
 * search param carrying PHP's exact Thai banner text, same convention as
 * ./welcome-actions.ts and (tenant)/line-groups/actions.ts. `redirect()` is
 * called OUTSIDE any try/catch for the same reason documented in those
 * files (it throws a Next-internal control-flow error that must not be
 * caught as a real failure).
 *
 * `email_settings` IS present on the committed tenant schema (see
 * ./email-queries.ts's module doc) — no missing-table degrade path here,
 * unlike welcome-actions.ts.
 *
 * NEW DEPENDENCY: `nodemailer` (+ `@types/nodemailer` devDependency), added
 * to apps/admin/package.json this batch. No email-sending capability
 * existed in any Next/TypeScript package before this — `classes/
 * EmailService.php`'s hand-rolled raw-socket SMTP client (STARTTLS/AUTH
 * LOGIN over `fsockopen`) has no TypeScript equivalent to reuse, and
 * hand-rolling SMTP-over-sockets in Node instead of using the ecosystem's
 * standard mailer would be exactly the kind of "hand-roll a UI/infra
 * primitive that already exists" this migration's conventions warn against
 * (packages/ui's own guidance, applied here to the mail-sending primitive
 * instead of a UI one). `pnpm-lock.yaml` only gained the two new entries for
 * `nodemailer`/`@types/nodemailer` — verified additive-only via `git diff`.
 *
 * Intentional, flagged simplification: real `EmailService::loadSettings()`
 * falls back to PHP's `mail()` function when `smtp_host` is empty
 * (`useSmtp = false`). There is no equivalent "hand the message to a local
 * MTA" primitive available in this Node/Next runtime (no `sendmail` binary
 * assumed present, no PHP `mail()` equivalent) — when `smtp_host` is unset,
 * `sendTestEmailViaSmtp()` below treats the test send as a failure rather
 * than attempting a fallback delivery path. This only affects the
 * PHP-vs-Next *equivalence* of the "no SMTP configured" edge case — the
 * `settings:email` parity entry is documented as a one-sided assertion
 * anyway (PHP's own `?tab=email` doesn't even reach this partial through
 * the live tab whitelist, see page.tsx's module doc), so this gap does not
 * regress any two-sided parity check.
 *
 * Intentional gap (flagged, not silently dropped): the PHP `save_email`
 * handler writes no ActivityLogger row of its own (confirmed by reading the
 * full handler) — nothing to omit here. `test_email` likewise has no audit
 * write in PHP.
 */

const SAVE_SUCCESS_MESSAGE = 'บันทึกการตั้งค่า Email สำเร็จ';
const TEST_INVALID_EMAIL_MESSAGE = 'กรุณาระบุ Email ที่ถูกต้อง';
const TEST_SEND_FAILED_MESSAGE = 'ส่ง Email ไม่สำเร็จ - ตรวจสอบการตั้งค่า SMTP';

/** PHP `(int) $value` semantics: leading optional sign + digits, else 0. */
function phpIntCast(value: string): number {
  const match = value.trim().match(/^[+-]?\d+/);
  return match ? Number.parseInt(match[0], 10) : 0;
}

/**
 * Approximates PHP's `filter_var($value, FILTER_VALIDATE_EMAIL)`. PHP's real
 * validator is a large hand-rolled RFC-822-ish state machine that can't be
 * literally executed here — this regex covers the same practical shape
 * (`local@domain.tld`, no whitespace) that every value this form's tests
 * exercise cares about.
 */
function isValidEmailLikePhpFilterVar(value: string): boolean {
  if (!value) return false;
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
}

export async function saveEmailSettingsAction(formData: FormData): Promise<void> {
  const { db } = await requireTenantPageContext();

  const smtpHost = String(formData.get('smtp_host') ?? '');
  const smtpPortField = formData.get('smtp_port');
  const smtpPort = smtpPortField === null ? 587 : phpIntCast(String(smtpPortField));
  const smtpUser = String(formData.get('smtp_user') ?? '');
  const smtpPass = String(formData.get('smtp_pass') ?? '');
  const smtpSecure = String(formData.get('smtp_secure') ?? 'tls');
  const fromEmail = String(formData.get('from_email') ?? '');
  const fromName = String(formData.get('from_name') ?? 'Notification');

  let errorMessage: string | null = null;
  try {
    await sql`
      INSERT INTO email_settings (id, smtp_host, smtp_port, smtp_user, smtp_pass, smtp_secure, from_email, from_name)
      VALUES (1, ${smtpHost}, ${smtpPort}, ${smtpUser}, ${smtpPass}, ${smtpSecure}, ${fromEmail}, ${fromName})
      ON DUPLICATE KEY UPDATE
      smtp_host = VALUES(smtp_host), smtp_port = VALUES(smtp_port),
      smtp_user = VALUES(smtp_user), smtp_pass = VALUES(smtp_pass),
      smtp_secure = VALUES(smtp_secure), from_email = VALUES(from_email),
      from_name = VALUES(from_name)
    `.execute(db);
  } catch (err) {
    errorMessage = err instanceof Error ? err.message : String(err);
  }

  if (errorMessage !== null) {
    redirect(`/settings?tab=email&error=${encodeURIComponent(`เกิดข้อผิดพลาด: ${errorMessage}`)}`);
  }

  redirect(`/settings?tab=email&message=${encodeURIComponent(SAVE_SUCCESS_MESSAGE)}`);
}

export async function sendTestEmailAction(formData: FormData): Promise<void> {
  const { db } = await requireTenantPageContext();
  const testEmail = String(formData.get('test_email') ?? '');

  if (!testEmail || !isValidEmailLikePhpFilterVar(testEmail)) {
    redirect(`/settings?tab=email&error=${encodeURIComponent(TEST_INVALID_EMAIL_MESSAGE)}`);
  }

  let sendSucceeded = false;
  try {
    sendSucceeded = await sendTestEmailViaSmtp(db, testEmail);
  } catch {
    sendSucceeded = false;
  }

  if (sendSucceeded) {
    redirect(`/settings?tab=email&message=${encodeURIComponent(`ส่ง Email ทดสอบสำเร็จไปยัง ${testEmail}`)}`);
  }

  redirect(`/settings?tab=email&error=${encodeURIComponent(TEST_SEND_FAILED_MESSAGE)}`);
}

/** Ported from classes/EmailService.php's sendTest()/buildTestEmailBody(), routed through nodemailer instead of a hand-rolled SMTP socket client (see module doc). */
async function sendTestEmailViaSmtp(db: Kysely<TenantDB>, to: string): Promise<boolean> {
  const settings = await getEmailSettings(db);

  if (!settings.smtpHost) {
    // No SMTP configured — see module doc's "Intentional, flagged simplification".
    return false;
  }

  const transporter = nodemailer.createTransport({
    host: settings.smtpHost,
    port: settings.smtpPort,
    secure: settings.smtpSecure === 'ssl',
    ...(settings.smtpSecure === 'none' ? { ignoreTLS: true } : {}),
    auth: settings.smtpUser ? { user: settings.smtpUser, pass: settings.smtpPass } : undefined,
  });

  const fromAddress = settings.fromEmail || settings.smtpUser;
  const info = await transporter.sendMail({
    to,
    from: settings.fromName ? `"${settings.fromName}" <${fromAddress}>` : fromAddress,
    subject: '🔔 ทดสอบการแจ้งเตือน Email',
    html: buildTestEmailBody(),
  });

  const rejected = Array.isArray((info as { rejected?: unknown[] })?.rejected) ? (info as { rejected: unknown[] }).rejected : [];
  return rejected.length === 0;
}

/**
 * PHP's `date('Y-m-d H:i:s')` reads the process-wide default timezone set by
 * `date_default_timezone_set(TIMEZONE)` in config/config.php, where
 * `TIMEZONE` is the hardcoded constant `'Asia/Bangkok'` (config/config.php
 * line 30) — so the stamp below must be Bangkok wall-clock time, not the
 * Node process's own (typically UTC-in-Docker) local time. Same fixed
 * +07:00-shift-via-UTC-getters pattern as packages/auth/src/sessionStore.ts's
 * `toMySqlDateTime()` (not imported — that helper is module-private and
 * MySQL-DATETIME-literal-specific; packages/auth is outside this batch's
 * allowed paths regardless), replicated locally since this repo has no
 * shared `packages/core/dates` package yet (confirmed absent).
 */
const BANGKOK_OFFSET_MS = 7 * 60 * 60 * 1000;

function formatBangkokDateTime(instant: Date): string {
  const bangkok = new Date(instant.getTime() + BANGKOK_OFFSET_MS);
  const pad = (n: number): string => String(n).padStart(2, '0');
  return (
    `${bangkok.getUTCFullYear()}-${pad(bangkok.getUTCMonth() + 1)}-${pad(bangkok.getUTCDate())} ` +
    `${pad(bangkok.getUTCHours())}:${pad(bangkok.getUTCMinutes())}:${pad(bangkok.getUTCSeconds())}`
  );
}

/** Verbatim (Thai text + structure) port of EmailService::buildTestEmailBody(). */
function buildTestEmailBody(): string {
  const stamp = formatBangkokDateTime(new Date());
  return `
<!DOCTYPE html>
<html>
<head><meta charset='UTF-8'></head>
<body style='font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5;'>
    <div style='max-width: 500px; margin: 0 auto; background: #ffffff; padding: 30px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);'>
        <div style='text-align: center; margin-bottom: 20px;'>
            <div style='width: 60px; height: 60px; background: linear-gradient(135deg, #10b981, #059669); border-radius: 50%; margin: 0 auto; display: flex; align-items: center; justify-content: center;'>
                <span style='font-size: 30px;'>✅</span>
            </div>
        </div>
        <h2 style='color: #059669; margin-bottom: 20px; text-align: center;'>ทดสอบการแจ้งเตือน</h2>
        <p style='text-align: center; color: #374151;'>ระบบแจ้งเตือน Email ทำงานปกติ</p>
        <p style='text-align: center; color: #6b7280; font-size: 14px;'>📅 ${stamp}</p>
        <hr style='border: none; border-top: 1px solid #e5e7eb; margin: 20px 0;'>
        <p style='text-align: center; color: #9ca3af; font-size: 12px;'>ข้อความนี้ส่งจากระบบอัตโนมัติ</p>
    </div>
</body>
</html>`;
}
