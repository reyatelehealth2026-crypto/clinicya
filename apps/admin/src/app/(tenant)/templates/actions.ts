'use server';

import { sql } from 'kysely';
import { revalidatePath } from 'next/cache';
import { requireTenantPageContext } from '../users/_lib/session';

/**
 * actions.ts — Server Actions for templates.php's single `if
 * ($_SERVER['REQUEST_METHOD'] === 'POST')` handler (lines 16-35):
 *
 *   - create -> action==='create': INSERT INTO templates (name, category,
 *     message_type, content) VALUES (?, ?, ?, ?)
 *   - update -> action==='update': UPDATE templates SET name=?, category=?,
 *     message_type=?, content=? WHERE id=?
 *   - delete -> action==='delete': DELETE FROM templates WHERE id = ?
 *
 * All three raw values (name/category/message_type/content) are written
 * exactly as PHP receives them from $_POST — no trimming/validation in the
 * PHP source, so none is added here either (behavior parity, not a
 * "helpful" improvement).
 *
 * PHP's redirect-after-POST (`header('Location: templates.php'); exit;`,
 * shared by all three actions) becomes `revalidatePath('/templates')` — the
 * page is a client-rendered grid updated by a modal/inline form, not a
 * server-navigated page-to-page flow, so there is no `redirect()` call here.
 * Same pattern as loyalty-members' `giveByPhoneAction` (see that action's
 * doc comment) rather than user-detail's `redirect()`-based actions, because
 * templates.php's own mutations are triggered from a modal opened over the
 * grid (like loyalty-members' add-points modal), not a full-page form
 * navigating between two different views (like user-detail's edit form).
 */

export interface TemplateActionResult {
  success: boolean;
}

export interface TemplateFormInput {
  name: string;
  category: string;
  messageType: string;
  content: string;
}

export async function createTemplateAction(input: TemplateFormInput): Promise<TemplateActionResult> {
  const { db } = await requireTenantPageContext();
  await sql`
    INSERT INTO templates (name, category, message_type, content)
    VALUES (${input.name}, ${input.category}, ${input.messageType}, ${input.content})
  `.execute(db);
  revalidatePath('/templates');
  return { success: true };
}

export async function updateTemplateAction(id: number, input: TemplateFormInput): Promise<TemplateActionResult> {
  const { db } = await requireTenantPageContext();
  await sql`
    UPDATE templates SET name = ${input.name}, category = ${input.category},
      message_type = ${input.messageType}, content = ${input.content}
    WHERE id = ${id}
  `.execute(db);
  revalidatePath('/templates');
  return { success: true };
}

export async function deleteTemplateAction(id: number): Promise<TemplateActionResult> {
  const { db } = await requireTenantPageContext();
  await sql`DELETE FROM templates WHERE id = ${id}`.execute(db);
  revalidatePath('/templates');
  return { success: true };
}
