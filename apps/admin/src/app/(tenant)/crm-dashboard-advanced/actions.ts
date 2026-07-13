'use server';

import { revalidatePath } from 'next/cache';
import { requireTenantPageContext } from '../users/_lib/session';
import {
  moveDeal,
  createDeal,
  updateDeal,
  deleteDeal,
  createTicket,
  updateTicket,
  addTicketInteraction,
  type MoveDealResult,
  type CreateDealInput,
  type CreateDealResult,
  type UpdateDealInput,
  type MutationResult,
  type CreateTicketInput,
  type CreateTicketResult,
  type UpdateTicketInput,
  type AddTicketInteractionInput,
  type AddTicketInteractionResult,
} from './_lib/mutations';
import { getCustomer360, type Customer360 } from './queries';

/**
 * actions.ts — Server Actions for api/crm-dashboard-api.php's 7 write
 * actions (deal_move/deal_create/deal_update/deal_delete/ticket_create/
 * ticket_update/ticket_interaction) + the read-only `customer_360` action
 * (reached from the page shell's Customer 360 modal). Each wraps its
 * ../_lib/mutations.ts counterpart with `requireTenantPageContext()` (auth)
 * and `revalidatePath('/crm-dashboard-advanced')` on a successful write —
 * same convention as ../loyalty-members/actions.ts.
 *
 * Auth note: api/crm-dashboard-api.php itself has NO role/session check of
 * its own beyond whatever `config/config.php`/`config/database.php` impose
 * globally (confirmed by reading the full 329-line source — no
 * `isAdmin()`/`isSuperAdmin()` call, no `$_SESSION` check at all) — so this
 * reuses the same "any authenticated tenant admin" gate as the read side
 * (requireTenantPageContext()), not a narrower role.
 */

export async function moveDealAction(dealId: number, stage: string): Promise<MoveDealResult> {
  const { db } = await requireTenantPageContext();
  const result = await moveDeal(db, dealId, stage);
  if (result.success) {
    revalidatePath('/crm-dashboard-advanced');
  }
  return result;
}

export async function createDealAction(data: CreateDealInput): Promise<CreateDealResult> {
  const { db } = await requireTenantPageContext();
  const result = await createDeal(db, data);
  if (result.success) {
    revalidatePath('/crm-dashboard-advanced');
  }
  return result;
}

export async function updateDealAction(dealId: number, data: UpdateDealInput): Promise<MutationResult> {
  const { db } = await requireTenantPageContext();
  const result = await updateDeal(db, dealId, data);
  if (result.success) {
    revalidatePath('/crm-dashboard-advanced');
  }
  return result;
}

export async function deleteDealAction(dealId: number): Promise<MutationResult> {
  const { db } = await requireTenantPageContext();
  const result = await deleteDeal(db, dealId);
  if (result.success) {
    revalidatePath('/crm-dashboard-advanced');
  }
  return result;
}

export async function createTicketAction(data: CreateTicketInput): Promise<CreateTicketResult> {
  const { db } = await requireTenantPageContext();
  const result = await createTicket(db, data);
  if (result.success) {
    revalidatePath('/crm-dashboard-advanced');
  }
  return result;
}

export async function updateTicketAction(ticketId: number, data: UpdateTicketInput): Promise<MutationResult> {
  const { db } = await requireTenantPageContext();
  const result = await updateTicket(db, ticketId, data);
  if (result.success) {
    revalidatePath('/crm-dashboard-advanced');
  }
  return result;
}

export async function addTicketInteractionAction(data: AddTicketInteractionInput): Promise<AddTicketInteractionResult> {
  const { db } = await requireTenantPageContext();
  const result = await addTicketInteraction(db, data);
  if (result.success) {
    revalidatePath('/crm-dashboard-advanced');
  }
  return result;
}

/** Read-only — backs the page shell's Customer 360 modal (`crmApi('customer_360', {customer_id})`). No revalidation needed. */
export async function getCustomer360Action(customerId: number): Promise<Customer360 | null> {
  const { db } = await requireTenantPageContext();
  return getCustomer360(db, customerId);
}
