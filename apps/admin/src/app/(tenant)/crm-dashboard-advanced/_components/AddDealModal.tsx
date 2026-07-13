'use client';

import { useState, useTransition } from 'react';
import { Modal } from '@/components/Modal';
import { createDealAction } from '../actions';

/**
 * AddDealModal.tsx — client island port of sales-pipeline.php's "Add Deal"
 * button + `#addDealModal` form (lines 62-138, `submitAddDeal()`). PageHeader
 * has no primaryAction slot for a modal-opening button (link-only), so — per
 * this batch's brief — this is a self-contained client component owning both
 * the trigger button AND the modal (same pattern as loyalty-members'
 * MembersListClient), droppable into both the Sales Pipeline tab and the All
 * Deals tab (both had their own "Add Deal" button in the PHP source, wired
 * to the exact same modal/submit handler).
 *
 * `customers` is pre-fetched server-side by the parent tab
 * (`getCustomers(db, lineAccountId, {limit:100})`, matching the PHP source's
 * `crmApi('customers', {limit:100})` call made when the modal opens) rather
 * than fetched on-demand — simpler than a dedicated Server Action just for
 * dropdown population, same "server-render into a client island's initial
 * state" pattern this batch's brief calls for.
 */
export interface DealCustomerOption {
  id: number;
  display_name: string | null;
  line_user_id: string;
}

export function AddDealModal({ customers }: { customers: DealCustomerOption[] }) {
  const [open, setOpen] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [isPending, startTransition] = useTransition();

  function close() {
    setOpen(false);
    setError(null);
  }

  function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const form = e.currentTarget;
    const fd = new FormData(form);
    const data = {
      customer_id: fd.get('customer_id'),
      title: fd.get('title'),
      value: Number.parseFloat(String(fd.get('value') ?? '')) || 0,
      stage: fd.get('stage'),
      probability: Number.parseInt(String(fd.get('probability') ?? ''), 10) || 20,
      expected_close: fd.get('expected_close') || null,
      description: fd.get('description'),
      source: fd.get('source'),
    };

    startTransition(async () => {
      const result = await createDealAction(data);
      if (result.success) {
        close();
        form.reset();
      } else {
        setError(result.error ?? 'Failed to create deal');
      }
    });
  }

  return (
    <>
      <button type="button" onClick={() => setOpen(true)} className="btn btn-primary btn-sm inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md bg-slate-900 text-white text-xs font-medium">
        + Add Deal
      </button>

      <Modal open={open} onClose={close} title="Add New Deal" size="md">
        <form onSubmit={onSubmit} className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Customer</label>
            <select name="customer_id" required className="w-full border rounded-md px-3 py-2 text-sm">
              <option value="">Select customer...</option>
              {customers.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.display_name || c.line_user_id}
                </option>
              ))}
            </select>
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Deal Title</label>
            <input type="text" name="title" required className="w-full border rounded-md px-3 py-2 text-sm" placeholder="e.g., Enterprise Software Package" />
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Value (฿)</label>
              <input type="number" name="value" required className="w-full border rounded-md px-3 py-2 text-sm" placeholder="50000" />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Stage</label>
              <select name="stage" defaultValue="lead" className="w-full border rounded-md px-3 py-2 text-sm">
                <option value="lead">New Lead</option>
                <option value="qualified">Qualified</option>
                <option value="proposal">Proposal</option>
                <option value="negotiation">Negotiation</option>
              </select>
            </div>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Probability (%)</label>
              <input type="number" name="probability" min={0} max={100} defaultValue={20} className="w-full border rounded-md px-3 py-2 text-sm" />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Expected Close</label>
              <input type="date" name="expected_close" className="w-full border rounded-md px-3 py-2 text-sm" />
            </div>
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Description</label>
            <textarea name="description" rows={3} className="w-full border rounded-md px-3 py-2 text-sm" placeholder="Additional details about this deal..." />
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Source</label>
            <select name="source" defaultValue="manual" className="w-full border rounded-md px-3 py-2 text-sm">
              <option value="manual">Manual Entry</option>
              <option value="website">Website</option>
              <option value="referral">Referral</option>
              <option value="line">LINE</option>
              <option value="phone">Phone</option>
            </select>
          </div>

          {error ? <p className="text-sm text-red-600">{error}</p> : null}

          <div className="flex gap-2 pt-2">
            <button type="button" onClick={close} className="flex-1 px-4 py-2 rounded-md bg-gray-100 text-gray-700 text-sm font-medium">
              Cancel
            </button>
            <button type="submit" disabled={isPending} className="flex-1 px-4 py-2 rounded-md bg-slate-900 text-white text-sm font-medium disabled:opacity-60">
              {isPending ? 'Creating…' : 'Create Deal'}
            </button>
          </div>
        </form>
      </Modal>
    </>
  );
}
