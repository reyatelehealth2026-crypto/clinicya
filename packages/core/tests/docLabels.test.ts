import { describe, expect, it } from 'vitest';
import { docStatusBadge, docStatusLabel, docTypeLabel } from '../src/docLabels';
import { REYA_DOCUMENT_TYPES, type DocType } from '../src/genDocNumber';

/**
 * docLabels.test.ts — DB-free tests for docTypeLabel/docStatusLabel/
 * docStatusBadge, ported for fidelity per the brief even though this
 * round's JSON API never calls docStatusBadge directly.
 */

const ALL_DOC_TYPES: DocType[] = ['QT', 'BL', 'INV', 'RE', 'TAX', 'DN', 'CN', 'PO', 'GR', 'DNP', 'CNP'];

describe('REYA_DOCUMENT_TYPES', () => {
  it('has exactly the 11 document types from the PHP source, correctly grouped', () => {
    expect(Object.keys(REYA_DOCUMENT_TYPES).sort()).toEqual([...ALL_DOC_TYPES].sort());
    expect(REYA_DOCUMENT_TYPES.QT).toEqual({ label: 'ใบเสนอราคา', labelEn: 'Quotation', prefix: 'QT', group: 'sales' });
    expect(REYA_DOCUMENT_TYPES.CNP).toEqual({ label: 'ใบลดหนี้ (ซื้อ)', labelEn: 'Credit Note (P)', prefix: 'CNP', group: 'purchase' });
    const salesTypes = ALL_DOC_TYPES.filter((t) => REYA_DOCUMENT_TYPES[t].group === 'sales');
    const purchaseTypes = ALL_DOC_TYPES.filter((t) => REYA_DOCUMENT_TYPES[t].group === 'purchase');
    expect(salesTypes).toEqual(['QT', 'BL', 'INV', 'RE', 'TAX', 'DN', 'CN']);
    expect(purchaseTypes).toEqual(['PO', 'GR', 'DNP', 'CNP']);
  });
});

describe('docTypeLabel', () => {
  it('returns the Thai label for every known doc_type', () => {
    for (const t of ALL_DOC_TYPES) {
      expect(docTypeLabel(t)).toBe(REYA_DOCUMENT_TYPES[t].label);
    }
  });

  it('trims + uppercases before lookup (lowercase/whitespace input still resolves)', () => {
    expect(docTypeLabel('  qt  ')).toBe('ใบเสนอราคา');
    expect(docTypeLabel('inv')).toBe('ใบแจ้งหนี้');
  });

  it('falls back to the normalized (trimmed+uppercased) input for unknown types', () => {
    expect(docTypeLabel('bogus')).toBe('BOGUS');
    expect(docTypeLabel('  xyz  ')).toBe('XYZ');
  });

  it('property: 200 random unknown-type strings always fall back to their own normalized form', () => {
    function mulberry32(seed: number): () => number {
      let a = seed;
      return () => {
        a |= 0;
        a = (a + 0x6d2b79f5) | 0;
        let t = Math.imul(a ^ (a >>> 15), 1 | a);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
      };
    }
    const rand = mulberry32(7);
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    for (let i = 0; i < 200; i++) {
      const len = 4 + Math.floor(rand() * 6); // 4-9 chars, longer than any real prefix
      let s = '';
      for (let j = 0; j < len; j++) s += alphabet[Math.floor(rand() * alphabet.length)];
      if (Object.prototype.hasOwnProperty.call(REYA_DOCUMENT_TYPES, s)) continue; // skip the astronomically-unlikely collision
      expect(docTypeLabel(s.toLowerCase())).toBe(s);
    }
  });
});

describe('docStatusLabel', () => {
  it('returns Thai labels for the 3 known statuses (case-sensitive, no trim/uppercase)', () => {
    expect(docStatusLabel('pending_approval')).toBe('รออนุมัติ');
    expect(docStatusLabel('approved')).toBe('อนุมัติ');
    expect(docStatusLabel('cancelled')).toBe('ยกเลิก');
  });

  it('unknown status falls back to the raw input, unmodified', () => {
    expect(docStatusLabel('weird_status')).toBe('weird_status');
    expect(docStatusLabel('APPROVED')).toBe('APPROVED'); // case-sensitive: PHP does NOT normalize this one
  });
});

describe('docStatusBadge', () => {
  it('renders the correct Tailwind class + escaped Thai label per status', () => {
    expect(docStatusBadge('approved')).toBe(
      '<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium border bg-emerald-100 text-emerald-800 border-emerald-200">อนุมัติ</span>'
    );
    expect(docStatusBadge('pending_approval')).toContain('bg-amber-100 text-amber-800 border-amber-200');
    expect(docStatusBadge('cancelled')).toContain('bg-rose-100 text-rose-800 border-rose-200');
  });

  it('unknown status gets the slate fallback class + its own label as text', () => {
    const html = docStatusBadge('weird_status');
    expect(html).toContain('bg-slate-100 text-slate-700 border-slate-200');
    expect(html).toContain('>weird_status<');
  });
});
