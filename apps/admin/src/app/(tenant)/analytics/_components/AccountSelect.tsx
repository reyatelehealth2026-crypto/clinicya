'use client';

import type { LineAccountOption } from '../_lib/accountQueries';

/**
 * AccountSelect.tsx — client port of account.php's
 * `<select name="account_id" onchange="this.form.submit()">`. A tiny client
 * island so the surrounding form (dates, submit button) can stay plain
 * server-rendered HTML — only the auto-submit-on-change behavior needs JS.
 */
export function AccountSelect({ accounts, selectedAccountId }: { accounts: LineAccountOption[]; selectedAccountId: number | null }) {
  return (
    <select
      name="account_id"
      defaultValue={selectedAccountId ?? ''}
      onChange={(e) => e.currentTarget.form?.submit()}
      className="w-full border rounded-lg px-3 py-2"
    >
      <option value="">-- เลือกบอท --</option>
      {accounts.map((acc) => (
        <option key={acc.id} value={acc.id}>
          {acc.name} {acc.is_default ? '(หลัก)' : ''}
        </option>
      ))}
    </select>
  );
}
