import { describe, expect, it, vi } from 'vitest';
import { sql } from 'kysely';
import { genDocNumber, GenDocNumberError } from '../src/genDocNumber';
import { makeFakeTenantDb, type QueryImpl } from './testHelpers/fakeTenantDb';

/**
 * genDocNumber.test.ts — mocked-Kysely unit tests. Proves: unknown-doc_type
 * / lineAccountId<=0 validation happens BEFORE any query runs; the produced
 * doc number's exact format; that year_month derives from Asia/Bangkok
 * "now" (or an injected `when`), never server-local time; the exact
 * SQL/params shape of the INSERT IGNORE + SELECT...FOR UPDATE + UPDATE
 * queries; and the transaction-ownership property the create route's
 * atomicity depends on (own-tx when called with a plain Kysely handle,
 * NO nested transaction when called with an already-open Transaction
 * handle).
 */

function passthroughTxControl(sqlText: string): boolean {
  return /^(begin|commit|rollback)/i.test(sqlText.trim());
}

/** Standard happy-path responder: last_seq=41 so the next number is 0042. */
function happyPathImpl(lastSeq = 41): QueryImpl {
  return (sqlText) => {
    if (passthroughTxControl(sqlText)) return {};
    if (/insert\s+ignore\s+into\s+`?document_sequences`?/i.test(sqlText)) return { affectedRows: 1 };
    if (/select .*from\s+`?document_sequences`?/i.test(sqlText) && /for update/i.test(sqlText)) {
      return [{ id: 1, last_seq: lastSeq }];
    }
    if (/^update\s+`?document_sequences`?/i.test(sqlText)) return { affectedRows: 1 };
    throw new Error(`unexpected query: ${sqlText}`);
  };
}

describe('genDocNumber — validation happens before any query', () => {
  it('unknown doc_type throws GenDocNumberError, zero queries issued', async () => {
    const { db, queries } = makeFakeTenantDb(happyPathImpl());
    await expect(genDocNumber(db, 1, 'XX')).rejects.toThrow(GenDocNumberError);
    await expect(genDocNumber(db, 1, 'XX')).rejects.toMatchObject({ code: 'unknown_doc_type' });
    expect(queries).toHaveLength(0);
  });

  it('lineAccountId <= 0 throws GenDocNumberError, zero queries issued (both 0 and negative)', async () => {
    const { db: db1, queries: q1 } = makeFakeTenantDb(happyPathImpl());
    await expect(genDocNumber(db1, 0, 'QT')).rejects.toMatchObject({ code: 'invalid_line_account_id' });
    expect(q1).toHaveLength(0);

    const { db: db2, queries: q2 } = makeFakeTenantDb(happyPathImpl());
    await expect(genDocNumber(db2, -5, 'QT')).rejects.toMatchObject({ code: 'invalid_line_account_id' });
    expect(q2).toHaveLength(0);
  });

  it('doc_type validation runs before the lineAccountId check would matter (unknown type + bad id both fail on type)', async () => {
    const { db, queries } = makeFakeTenantDb(happyPathImpl());
    await expect(genDocNumber(db, -1, 'NOPE')).rejects.toMatchObject({ code: 'unknown_doc_type' });
    expect(queries).toHaveLength(0);
  });
});

describe('genDocNumber — output format', () => {
  it('produces exactly {PREFIX}-{YYMM}-{seq:4d} with Buddhist year tail, via an injected `when`', async () => {
    const { db } = makeFakeTenantDb(happyPathImpl(41));
    // May 2026 (Gregorian) -> Buddhist 2569 -> tail "69", month "05" -> "6905".
    const when = new Date(Date.UTC(2026, 4, 24));
    const result = await genDocNumber(db, 7, 'qt', when); // lowercase input, normalized to QT
    expect(result).toBe('QT-6905-0042');
  });

  it('zero-pads the sequence to 4 digits for small values and does not truncate large ones', async () => {
    const { db: db1 } = makeFakeTenantDb(happyPathImpl(0));
    expect(await genDocNumber(db1, 1, 'INV', new Date(Date.UTC(2026, 0, 1)))).toBe('INV-6901-0001');

    const { db: db2 } = makeFakeTenantDb(happyPathImpl(9998));
    expect(await genDocNumber(db2, 1, 'INV', new Date(Date.UTC(2026, 0, 1)))).toBe('INV-6901-9999');
  });

  it('January (Bangkok) maps to buddhist-tail "69" for Gregorian 2026 and "01" month', async () => {
    const { db } = makeFakeTenantDb(happyPathImpl(0));
    const result = await genDocNumber(db, 1, 'RE', new Date(Date.UTC(2026, 0, 15)));
    expect(result).toBe('RE-6901-0001');
  });
});

describe('genDocNumber — year_month derives from Asia/Bangkok "now", never server-local time', () => {
  it('a mocked Date.now() straddling a UTC month boundary resolves to the Bangkok (UTC+7) month', async () => {
    // 2026-04-30T23:00:00Z is still April in UTC, but 2026-05-01T06:00:00
    // in Bangkok (UTC+7) — the doc number's month tail must be "05", proving
    // the function does NOT just read UTC (or any other server-local zone).
    const fixedNow = Date.UTC(2026, 3, 30, 23, 0, 0); // April 30, 23:00 UTC
    const nowSpy = vi.spyOn(Date, 'now').mockReturnValue(fixedNow);
    try {
      const { db } = makeFakeTenantDb(happyPathImpl(0));
      const result = await genDocNumber(db, 1, 'QT');
      expect(result).toBe('QT-6905-0001'); // May (05), not April (04)
    } finally {
      nowSpy.mockRestore();
    }
  });

  it('a mocked Date.now() straddling a UTC year boundary resolves to the Bangkok year', async () => {
    // 2025-12-31T18:00:00Z is 2026-01-01T01:00 in Bangkok.
    const fixedNow = Date.UTC(2025, 11, 31, 18, 0, 0);
    const nowSpy = vi.spyOn(Date, 'now').mockReturnValue(fixedNow);
    try {
      const { db } = makeFakeTenantDb(happyPathImpl(0));
      const result = await genDocNumber(db, 1, 'QT');
      expect(result).toBe('QT-6901-0001'); // 2026 Gregorian -> 2569 Buddhist -> tail 69, month 01
    } finally {
      nowSpy.mockRestore();
    }
  });
});

describe('genDocNumber — exact SQL/params shape', () => {
  it('INSERT IGNORE INTO document_sequences (line_account_id, doc_type, year_month, last_seq=0)', async () => {
    const { db, queries } = makeFakeTenantDb(happyPathImpl(5));
    await genDocNumber(db, 42, 'INV', new Date(Date.UTC(2026, 4, 1)));

    const insert = queries.find((q) => /insert\s+ignore\s+into/i.test(q.sql));
    expect(insert).toBeDefined();
    expect(insert!.sql.toLowerCase()).toContain('insert ignore into');
    expect(insert!.sql.toLowerCase()).toContain('document_sequences');
    expect(insert!.params).toEqual([42, 'INV', '6905', 0]);
  });

  it('SELECT id, last_seq FROM document_sequences WHERE line_account_id=? AND doc_type=? AND year_month=? FOR UPDATE', async () => {
    const { db, queries } = makeFakeTenantDb(happyPathImpl(5));
    await genDocNumber(db, 42, 'INV', new Date(Date.UTC(2026, 4, 1)));

    const select = queries.find((q) => /^select/i.test(q.sql.trim()));
    expect(select).toBeDefined();
    expect(select!.sql.toLowerCase()).toContain('document_sequences');
    expect(select!.sql.toLowerCase()).toContain('for update');
    expect(select!.params).toEqual([42, 'INV', '6905']);
  });

  it('UPDATE document_sequences SET last_seq=? WHERE id=?', async () => {
    const { db, queries } = makeFakeTenantDb(happyPathImpl(5));
    await genDocNumber(db, 42, 'INV', new Date(Date.UTC(2026, 4, 1)));

    const update = queries.find((q) => /^update/i.test(q.sql.trim()));
    expect(update).toBeDefined();
    expect(update!.sql.toLowerCase()).toContain('document_sequences');
    expect(update!.params).toEqual([6, 1]); // next_seq=5+1=6, row id=1 (from happyPathImpl's fake row)
  });

  it('throws when the row is missing after INSERT IGNORE (defensive — should never happen against a real DB)', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (passthroughTxControl(sqlText)) return {};
      if (/insert\s+ignore/i.test(sqlText)) return { affectedRows: 1 };
      if (/^select/i.test(sqlText)) return []; // no row
      throw new Error('unreachable');
    });
    await expect(genDocNumber(db, 1, 'QT', new Date(Date.UTC(2026, 0, 1)))).rejects.toThrow(
      'document_sequences row missing after INSERT IGNORE'
    );
  });
});

describe('genDocNumber — transaction ownership (the atomicity property the create route depends on)', () => {
  it('opens its own transaction when called with a plain (non-transaction) Kysely handle', async () => {
    const { db, queries } = makeFakeTenantDb(happyPathImpl(0));
    expect(db.isTransaction).toBe(false);

    await genDocNumber(db, 1, 'QT', new Date(Date.UTC(2026, 0, 1)));

    const controlStatements = queries.filter((q) => passthroughTxControl(q.sql)).map((q) => q.sql.trim().toLowerCase());
    expect(controlStatements).toEqual(['begin', 'commit']);
  });

  it('does NOT open a nested transaction when already given an open Transaction handle (isTransaction=true)', async () => {
    const { db, queries } = makeFakeTenantDb(happyPathImpl(0));

    await db.transaction().execute(async (trx) => {
      expect(trx.isTransaction).toBe(true);
      const result = await genDocNumber(trx, 1, 'QT', new Date(Date.UTC(2026, 0, 1)));
      expect(result).toBe('QT-6901-0001');
    });

    // Exactly ONE begin/commit pair total — from the OUTER db.transaction()
    // call, not a second nested one from inside genDocNumber.
    const controlStatements = queries.filter((q) => passthroughTxControl(q.sql)).map((q) => q.sql.trim().toLowerCase());
    expect(controlStatements).toEqual(['begin', 'commit']);
  });

  it('atomicity: when a later write in the SAME shared transaction throws, the sequence bump is inside the single rollback (not separately committed)', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (passthroughTxControl(sqlText)) return {};
      if (/insert\s+ignore/i.test(sqlText)) return { affectedRows: 1 };
      if (/^select/i.test(sqlText)) return [{ id: 1, last_seq: 0 }];
      if (/^update\s+`?document_sequences`?/i.test(sqlText)) return { affectedRows: 1 };
      if (sqlText.toLowerCase().includes('business_documents')) {
        throw new Error('simulated business_documents insert failure');
      }
      throw new Error(`unexpected query: ${sqlText}`);
    });

    await expect(
      db.transaction().execute(async (trx) => {
        const docNumber = await genDocNumber(trx, 1, 'QT', new Date(Date.UTC(2026, 0, 1)));
        // Simulate documents_insert()'s INSERT INTO business_documents failing
        // inside the SAME shared transaction genDocNumber just ran in.
        await sql`INSERT INTO business_documents (line_account_id) VALUES (1)`.execute(trx);
        return docNumber;
      })
    ).rejects.toThrow('simulated business_documents insert failure');

    const controlStatements = queries.filter((q) => passthroughTxControl(q.sql)).map((q) => q.sql.trim().toLowerCase());
    // No COMMIT anywhere — only begin then rollback. Because genDocNumber
    // never issued its own commit (isTransaction was already true), the
    // last_seq UPDATE it ran is only ever visible inside this one
    // never-committed transaction, so it rolls back with everything else.
    expect(controlStatements).toEqual(['begin', 'rollback']);
    expect(controlStatements).not.toContain('commit');
  });
});
