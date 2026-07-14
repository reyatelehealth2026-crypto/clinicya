import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * mutations.ts — the ONE mutation ported from either source file: literal
 * port of `HealthArticleService::incrementViewCount()`
 * (classes/HealthArticleService.php lines 283-288):
 *
 *   private function incrementViewCount(int $id): void {
 *       try {
 *           $stmt = $this->db->prepare("UPDATE health_articles SET view_count = view_count + 1 WHERE id = ?");
 *           $stmt->execute([$id]);
 *       } catch (PDOException $e) {}
 *   }
 *
 * Split out of `getBySlug()` (which calls this as a side effect in PHP —
 * see queries.ts's `getArticleBySlug` doc for why the read is kept pure
 * here) into its own pure function so `actions.ts`'s Server Action can wrap
 * it with an explicit, single call site per page render — "fired on each
 * detail-page view, not deduped" (this batch's brief), never accidentally
 * double-fired by an unrelated second read of the same article.
 *
 * Same try/catch-swallow as PHP: a failed increment (e.g. a transient lock
 * wait) must never fail the page render.
 */
export async function incrementArticleViewCount(db: Kysely<TenantDB>, articleId: number): Promise<void> {
  try {
    await sql`UPDATE health_articles SET view_count = view_count + 1 WHERE id = ${articleId}`.execute(db);
  } catch {
    // swallow — matches PHP's catch (PDOException $e) {}
  }
}
