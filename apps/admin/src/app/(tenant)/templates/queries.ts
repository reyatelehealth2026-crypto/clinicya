import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { extractCategoryFilters } from './_lib/categories';

/**
 * queries.ts — data assembly for /templates, ported from templates.php lines
 * 38-42:
 *
 *   $stmt = $db->query("SELECT * FROM templates ORDER BY category, name");
 *   $templates = $stmt->fetchAll();
 *   $categories = array_unique(array_column($templates, 'category'));
 *
 * CONFIRMED BY READING THE FULL FILE: there is no `line_account_id` WHERE
 * clause anywhere in templates.php — the `templates` table has a nullable
 * `line_account_id` column, but this page never filters on it. The template
 * library is global to the tenant, not scoped to the currently-selected LINE
 * account. Do not add a WHERE clause here "to be safe" — that would change
 * behavior, not preserve it.
 *
 * Written as a raw `sql` fragment (not Kysely's typed `.selectFrom()`
 * builder), matching this codebase's established house style — see
 * users/queries.ts's `getUsersListPage()` doc comment for the full
 * rationale (no CamelCasePlugin on the shared Kysely<TenantDB> instance).
 */
export interface TemplateRow {
  id: number;
  name: string;
  category: string | null;
  messageType: string;
  content: string;
  createdAt: Date;
}

export interface TemplatesPageData {
  templates: TemplateRow[];
  categories: string[];
}

export async function getTemplatesData(db: Kysely<TenantDB>): Promise<TemplatesPageData> {
  const result = await sql<TemplateRow>`
    SELECT
      id, name, category, message_type AS messageType, content, created_at AS createdAt
    FROM templates
    ORDER BY category, name
  `.execute(db);

  const templates = result.rows;
  return { templates, categories: extractCategoryFilters(templates) };
}
