import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { isPhpEmpty, isPhpIsset } from './format';

/**
 * mutations.ts — the DB-touching half of CRMDashboardService.php's 7 write
 * methods (moveDeal/createDeal/updateDeal/deleteDeal/createTicket/
 * updateTicket/addTicketInteraction), wrapped as Server Actions in
 * ../actions.ts. Per this batch's brief, ALL SEVEN are ported 1:1 even
 * though only `deal_move`, `deal_create`, and `ticket_create` currently have
 * a reachable UI trigger in the 9 section partials (updateDeal/deleteDeal
 * have no "Edit"/"Delete" button anywhere — editDeal() is a bare
 * `alert(...)` stub; updateTicket/addTicketInteraction have no reachable
 * caller either — viewTicket() is likewise a bare `alert(...)` stub). These
 * are the full CRUD surface api/crm-dashboard-api.php exposes, named
 * explicitly in the brief's deliverable list.
 *
 * All 7 are ALREADY defensively try/caught in real PHP (return
 * `{success:false, error: e.getMessage()}`), so — unlike the read side —
 * there is no "AUTHORIZED RESOLUTION" needed here: calling any of these
 * against a tenant DB lacking crm_deals/crm_tickets/crm_ticket_interactions
 * already produces the exact real-PHP `{success:false, error:"..."}` shape,
 * ported 1:1 below.
 */

export interface MutationResult {
  success: boolean;
  error?: string;
}

export interface MoveDealResult extends MutationResult {
  message?: string;
}

export interface CreateDealResult extends MutationResult {
  deal_id?: number;
}

export interface CreateTicketResult extends MutationResult {
  ticket_id?: number;
}

export interface AddTicketInteractionResult extends MutationResult {
  interaction_id?: number;
}

function errorMessage(error: unknown): string {
  return error instanceof Error ? error.message : String(error);
}

const VALID_STAGES = ['lead', 'qualified', 'proposal', 'negotiation', 'closed_won', 'closed_lost'] as const;
type ValidStage = (typeof VALID_STAGES)[number];

/** Ported from CRMDashboardService::moveDeal($dealId, $newStage). */
export async function moveDeal(db: Kysely<TenantDB>, dealId: number, newStage: string): Promise<MoveDealResult> {
  if (!(VALID_STAGES as readonly string[]).includes(newStage)) {
    return { success: false, error: 'Invalid stage' };
  }
  const closesDeal = newStage === 'closed_won' || newStage === 'closed_lost';

  try {
    if (closesDeal) {
      await sql`UPDATE crm_deals SET stage = ${newStage as ValidStage}, closed_at = NOW(), updated_at = NOW() WHERE id = ${dealId}`.execute(db);
    } else {
      await sql`UPDATE crm_deals SET stage = ${newStage as ValidStage}, updated_at = NOW() WHERE id = ${dealId}`.execute(db);
    }
    return { success: true, message: 'Deal moved successfully' };
  } catch (error) {
    return { success: false, error: errorMessage(error) };
  }
}

export interface CreateDealInput {
  customer_id?: unknown;
  title?: unknown;
  description?: unknown;
  value?: unknown;
  stage?: unknown;
  probability?: unknown;
  expected_close?: unknown;
  assigned_to?: unknown;
  source?: unknown;
}

/** Ported from CRMDashboardService::createDeal($data). Required-field check uses PHP's `empty()` semantics (isPhpEmpty), not `isset()`. */
export async function createDeal(db: Kysely<TenantDB>, data: CreateDealInput): Promise<CreateDealResult> {
  for (const field of ['customer_id', 'title', 'value'] as const) {
    if (isPhpEmpty(data[field])) {
      return { success: false, error: `Missing required field: ${field}` };
    }
  }

  try {
    const result = await sql`
      INSERT INTO crm_deals (customer_id, title, description, value, stage, probability, expected_close, assigned_to, source, created_at, updated_at)
      VALUES (${data.customer_id}, ${data.title}, ${data.description ?? ''}, ${data.value}, ${data.stage ?? 'lead'},
              ${data.probability ?? 20}, ${data.expected_close ?? null}, ${data.assigned_to ?? null}, ${data.source ?? 'manual'}, NOW(), NOW())
    `.execute(db);
    return { success: true, deal_id: Number(result.insertId ?? 0) };
  } catch (error) {
    return { success: false, error: errorMessage(error) };
  }
}

export interface UpdateDealInput {
  title?: unknown;
  description?: unknown;
  value?: unknown;
  stage?: unknown;
  probability?: unknown;
  expected_close?: unknown;
  assigned_to?: unknown;
}

const UPDATE_DEAL_FIELDS = ['title', 'description', 'value', 'stage', 'probability', 'expected_close', 'assigned_to'] as const;

/** Ported from CRMDashboardService::updateDeal($dealId, $data). Field inclusion uses PHP's `isset()` semantics (isPhpIsset), NOT `empty()`. */
export async function updateDeal(db: Kysely<TenantDB>, dealId: number, data: UpdateDealInput): Promise<MutationResult> {
  const sets: ReturnType<typeof sql>[] = [];
  for (const field of UPDATE_DEAL_FIELDS) {
    if (isPhpIsset(data[field])) {
      sets.push(sql`${sql.raw(field)} = ${data[field]}`);
    }
  }
  if (sets.length === 0) {
    return { success: false, error: 'No fields to update' };
  }

  try {
    await sql`UPDATE crm_deals SET ${sql.join([...sets, sql`updated_at = NOW()`])} WHERE id = ${dealId}`.execute(db);
    return { success: true };
  } catch (error) {
    return { success: false, error: errorMessage(error) };
  }
}

/** Ported from CRMDashboardService::deleteDeal($dealId). */
export async function deleteDeal(db: Kysely<TenantDB>, dealId: number): Promise<MutationResult> {
  try {
    await sql`DELETE FROM crm_deals WHERE id = ${dealId}`.execute(db);
    return { success: true };
  } catch (error) {
    return { success: false, error: errorMessage(error) };
  }
}

export interface CreateTicketInput {
  customer_id?: unknown;
  subject?: unknown;
  description?: unknown;
  status?: unknown;
  priority?: unknown;
  category?: unknown;
  assigned_to?: unknown;
}

/**
 * Ported from CRMDashboardService::createTicket($data), INCLUDING the real
 * PHP bug: `strtotime('+{$slaHours[$priority]} hours')` is inside SINGLE
 * quotes in the PHP source, so `{$slaHours[$priority]}` is NEVER
 * interpolated — `strtotime()` receives the literal unparseable text
 * `+{$slaHours[$priority]} hours`, returns `false`, and `date('Y-m-d H:i:s',
 * false)` casts `false` to the Unix epoch (timestamp 0) -> with the
 * platform's forced Asia/Bangkok (+07:00) timezone, that's
 * '1970-01-01 07:00:00'. sla_deadline is therefore ALWAYS this exact string,
 * regardless of priority. Mirrored as a literal constant — do NOT compute a
 * real priority-based deadline.
 */
export async function createTicket(db: Kysely<TenantDB>, data: CreateTicketInput): Promise<CreateTicketResult> {
  for (const field of ['customer_id', 'subject'] as const) {
    if (isPhpEmpty(data[field])) {
      return { success: false, error: `Missing required field: ${field}` };
    }
  }

  const priority = (data.priority as string | undefined) ?? 'medium';
  const slaDeadline = '1970-01-01 07:00:00'; // always-epoch bug — see this function's doc comment

  try {
    const result = await sql`
      INSERT INTO crm_tickets (customer_id, subject, description, status, priority, category, assigned_to, sla_deadline, created_at)
      VALUES (${data.customer_id}, ${data.subject}, ${data.description ?? ''}, ${data.status ?? 'open'}, ${priority},
              ${data.category ?? 'general'}, ${data.assigned_to ?? null}, ${slaDeadline}, NOW())
    `.execute(db);
    return { success: true, ticket_id: Number(result.insertId ?? 0) };
  } catch (error) {
    return { success: false, error: errorMessage(error) };
  }
}

export interface UpdateTicketInput {
  subject?: unknown;
  description?: unknown;
  status?: unknown;
  priority?: unknown;
  category?: unknown;
  assigned_to?: unknown;
}

const UPDATE_TICKET_FIELDS = ['subject', 'description', 'status', 'priority', 'category', 'assigned_to'] as const;

/** Ported from CRMDashboardService::updateTicket($ticketId, $data). Sets resolved_at=NOW() when status is changed to resolved/closed. */
export async function updateTicket(db: Kysely<TenantDB>, ticketId: number, data: UpdateTicketInput): Promise<MutationResult> {
  const sets: ReturnType<typeof sql>[] = [];
  for (const field of UPDATE_TICKET_FIELDS) {
    if (isPhpIsset(data[field])) {
      sets.push(sql`${sql.raw(field)} = ${data[field]}`);
    }
  }
  if (sets.length === 0) {
    return { success: false, error: 'No fields to update' };
  }
  if (isPhpIsset(data.status) && (data.status === 'resolved' || data.status === 'closed')) {
    sets.push(sql`resolved_at = NOW()`);
  }

  try {
    await sql`UPDATE crm_tickets SET ${sql.join(sets)} WHERE id = ${ticketId}`.execute(db);
    return { success: true };
  } catch (error) {
    return { success: false, error: errorMessage(error) };
  }
}

export interface AddTicketInteractionInput {
  ticket_id?: unknown;
  interaction_type?: unknown;
  content?: unknown;
  staff_id?: unknown;
}

/** Ported from CRMDashboardService::addTicketInteraction($data). */
export async function addTicketInteraction(db: Kysely<TenantDB>, data: AddTicketInteractionInput): Promise<AddTicketInteractionResult> {
  for (const field of ['ticket_id', 'interaction_type', 'content'] as const) {
    if (isPhpEmpty(data[field])) {
      return { success: false, error: `Missing required field: ${field}` };
    }
  }

  try {
    const result = await sql`
      INSERT INTO crm_ticket_interactions (ticket_id, interaction_type, content, staff_id, created_at)
      VALUES (${data.ticket_id}, ${data.interaction_type}, ${data.content}, ${data.staff_id ?? null}, NOW())
    `.execute(db);
    return { success: true, interaction_id: Number(result.insertId ?? 0) };
  } catch (error) {
    return { success: false, error: errorMessage(error) };
  }
}
