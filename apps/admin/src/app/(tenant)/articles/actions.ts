'use server';

import { requireTenantPageContext } from '../users/_lib/session';
import { incrementArticleViewCount } from './_lib/mutations';

/**
 * actions.ts — the one Server Action this batch ports: the view-count
 * increment `HealthArticleService::getBySlug()` fires as a side effect on
 * every successful lookup (classes/HealthArticleService.php line 96). See
 * `_lib/mutations.ts`'s doc for why the read (queries.ts's
 * `getArticleBySlug`) and this write are split apart.
 *
 * Resolves its own `db`/session via `requireTenantPageContext()` rather
 * than accepting a caller-supplied `Kysely<TenantDB>` — same defensive
 * convention loyalty-members/actions.ts's `giveByPhoneAction` already
 * establishes for this codebase's Server Actions (never trust a
 * caller-supplied db handle across the action boundary, even when the only
 * real caller today is a Server Component in the same route tree that
 * already resolved one for itself).
 *
 * Called directly from `[slug]/page.tsx` during render (`await
 * incrementViewCountAction(article.id)`), not wired to a `<form
 * action={...}>` — Server Actions are plain server-side async functions and
 * can be invoked imperatively like any other; this is the established
 * pattern for a page-view side effect that has no user-initiated form
 * submission to attach to. Fired exactly once per page render — "not
 * deduped, matching legacy behavior" (this batch's brief): loading the same
 * article twice increments `view_count` twice, same as PHP.
 */
export async function incrementViewCountAction(articleId: number): Promise<void> {
  const { db } = await requireTenantPageContext();
  await incrementArticleViewCount(db, articleId);
}
