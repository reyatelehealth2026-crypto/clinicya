import type { Metadata } from 'next';
import { requireTenantPageContext } from '../users/_lib/session';
import { getTemplatesData } from './queries';
import { TemplatesClient } from './_components/TemplatesClient';

/**
 * (tenant)/templates/page.tsx — Server Component port of templates.php
 * (284 LOC, confirmed by reading the full file). Pure CRUD on the
 * `templates` table with NO `line_account_id` scoping anywhere — see
 * queries.ts's module doc. Serves at the same clean URL PHP does —
 * `/templates`, no query params.
 *
 * Access gate: templates.php has no page-specific role check beyond
 * `includes/header.php`'s generic "must be logged in" requirement (grepped
 * for isSuperAdmin/isAdmin/isStaff in the full file — zero hits) — reuses
 * users/_lib/session's requireTenantPageContext(), the same cross-route
 * import convention loyalty-members and user-detail already establish.
 */
export const metadata: Metadata = { title: 'Template Library' };

export default async function TemplatesPage() {
  const { db } = await requireTenantPageContext();
  const { templates, categories } = await getTemplatesData(db);

  return (
    <div className="max-w-6xl mx-auto px-4 py-4">
      <TemplatesClient templates={templates} categories={categories} />
    </div>
  );
}
