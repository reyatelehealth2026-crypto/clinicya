import { REYA_DOCUMENT_TYPES } from './genDocNumber';

/**
 * docLabels.ts — TypeScript port of `includes/document-helpers.php`'s
 * `docTypeLabel()` (lines 175-179), `docStatusLabel()` (lines 181-189), and
 * `docStatusBadge()` (lines 194-204).
 *
 * Ported for fidelity even though this round's JSON API never calls
 * `docStatusBadge()` (it renders server-side HTML — an admin-UI concern out
 * of scope for the Route Handlers in this batch); `docTypeLabel()`/
 * `docStatusLabel()` ARE used by the `list`/`get` route ports (`doc_type_label`/
 * `status_label` response fields).
 */

export type DocStatus = 'pending_approval' | 'approved' | 'cancelled';

/**
 * Port of `docTypeLabel(string $docType): string`.
 * ```php
 * function docTypeLabel(string $docType): string
 * {
 *     $docType = strtoupper(trim($docType));
 *     return REYA_DOCUMENT_TYPES[$docType]['label'] ?? $docType;
 * }
 * ```
 */
export function docTypeLabel(docType: string): string {
  const normalized = docType.trim().toUpperCase();
  const meta = (REYA_DOCUMENT_TYPES as Record<string, { label: string } | undefined>)[normalized];
  return meta?.label ?? normalized;
}

const STATUS_LABELS: Readonly<Record<string, string>> = {
  pending_approval: 'รออนุมัติ',
  approved: 'อนุมัติ',
  cancelled: 'ยกเลิก',
};

/**
 * Port of `docStatusLabel(string $status): string`. Unlike `docTypeLabel`,
 * PHP does NOT uppercase/trim the input here — `$map[$status] ?? $status`
 * is a direct, case-sensitive lookup. Preserved as-is.
 */
export function docStatusLabel(status: string): string {
  return STATUS_LABELS[status] ?? status;
}

const STATUS_BADGE_CLASSES: Readonly<Record<string, string>> = {
  pending_approval: 'bg-amber-100 text-amber-800 border-amber-200',
  approved: 'bg-emerald-100 text-emerald-800 border-emerald-200',
  cancelled: 'bg-rose-100 text-rose-800 border-rose-200',
};

/** Port of PHP's `htmlspecialchars($str, ENT_QUOTES, 'UTF-8')`. */
function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

/**
 * Port of `docStatusBadge(string $status): string` — renders a Tailwind-styled
 * `<span>` badge, byte-for-byte matching the PHP source's markup/classes.
 */
export function docStatusBadge(status: string): string {
  const cls = STATUS_BADGE_CLASSES[status] ?? 'bg-slate-100 text-slate-700 border-slate-200';
  const label = escapeHtml(docStatusLabel(status));
  return `<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium border ${cls}">${label}</span>`;
}
