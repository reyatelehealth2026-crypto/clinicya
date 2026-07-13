'use client';

import { useState, useTransition } from 'react';
import { Modal } from '@/components/Modal';
import { createTicketAction } from '../actions';
import type { DealCustomerOption } from './AddDealModal';

/**
 * CreateTicketModal.tsx — client island port of service-center.php's
 * "Create Ticket" button + `#createTicketModal` form (lines 78-136,
 * `submitCreateTicket()`). Self-contained trigger+modal, same rationale as
 * AddDealModal.tsx (PageHeader has no primaryAction slot for a modal).
 * Reused by both the Service Center tab and (implicitly reachable via the
 * same button) the Tickets tab's create flow.
 */
export function CreateTicketModal({ customers }: { customers: DealCustomerOption[] }) {
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
      subject: fd.get('subject'),
      priority: fd.get('priority'),
      category: fd.get('category'),
      description: fd.get('description'),
    };

    startTransition(async () => {
      const result = await createTicketAction(data);
      if (result.success) {
        close();
        form.reset();
      } else {
        setError(result.error ?? 'Failed to create ticket');
      }
    });
  }

  return (
    <>
      <button type="button" onClick={() => setOpen(true)} className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md bg-slate-900 text-white text-xs font-medium">
        + Create Ticket
      </button>

      <Modal open={open} onClose={close} title="Create Support Ticket" size="md">
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
            <label className="block text-sm font-medium text-gray-700 mb-1">Subject</label>
            <input type="text" name="subject" required className="w-full border rounded-md px-3 py-2 text-sm" placeholder="Brief description of the issue" />
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Priority</label>
              <select name="priority" defaultValue="medium" className="w-full border rounded-md px-3 py-2 text-sm">
                <option value="low">Low</option>
                <option value="medium">Medium</option>
                <option value="high">High</option>
                <option value="urgent">Urgent</option>
              </select>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Category</label>
              <select name="category" defaultValue="general" className="w-full border rounded-md px-3 py-2 text-sm">
                <option value="general">General</option>
                <option value="technical">Technical</option>
                <option value="billing">Billing</option>
                <option value="sales">Sales</option>
              </select>
            </div>
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Description</label>
            <textarea name="description" rows={4} className="w-full border rounded-md px-3 py-2 text-sm" placeholder="Detailed description of the issue..." />
          </div>

          {error ? <p className="text-sm text-red-600">{error}</p> : null}

          <div className="flex gap-2 pt-2">
            <button type="button" onClick={close} className="flex-1 px-4 py-2 rounded-md bg-gray-100 text-gray-700 text-sm font-medium">
              Cancel
            </button>
            <button type="submit" disabled={isPending} className="flex-1 px-4 py-2 rounded-md bg-slate-900 text-white text-sm font-medium disabled:opacity-60">
              {isPending ? 'Creating…' : 'Create Ticket'}
            </button>
          </div>
        </form>
      </Modal>
    </>
  );
}
