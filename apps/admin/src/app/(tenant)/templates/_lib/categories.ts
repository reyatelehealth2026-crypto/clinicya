/**
 * categories.ts — replicates templates.php's category-filter-bar button
 * order (lines 42, 160-163):
 *
 *   $categories = array_unique(array_column($templates, 'category'));
 *   ...
 *   <?php foreach ($categories as $cat): if ($cat): ?>
 *   <button ...><?= htmlspecialchars($cat) ?></button>
 *   <?php endif; endforeach; ?>
 *
 * `$templates` is already `ORDER BY category, name` from SQL — so the button
 * order is alphabetical BY CONSTRUCTION of the query, not by any sort PHP
 * performs. `array_unique` keeps the FIRST occurrence of each distinct
 * (loosely-string-compared) value and never re-sorts, so this dedupes the
 * already-sorted list in first-seen order — it must NOT call `.sort()` in
 * TS, which would coincidentally look right here (categories happen to also
 * be alphabetical) but is the wrong mechanism and would silently diverge the
 * moment the underlying ORDER BY changes.
 *
 * The render loop's `if ($cat)` is PHP loose-truthiness: null, '', and the
 * single-character string '0' are all falsy and get skipped (never rendered
 * as a button), even though `array_unique` itself keeps them in the
 * intermediate array. Because a falsy value is filtered out regardless of
 * where it sits in the list, filtering falsy values inline (as this does)
 * produces the exact same visible first-seen order as PHP's two-step
 * dedupe-then-filter — there is no case where doing it in one pass changes
 * the result.
 */
export function extractCategoryFilters(templates: readonly { category: string | null }[]): string[] {
  const seen = new Set<string>();
  const result: string[] = [];
  for (const t of templates) {
    const cat = t.category;
    if (!cat || cat === '0') {
      continue;
    }
    if (seen.has(cat)) {
      continue;
    }
    seen.add(cat);
    result.push(cat);
  }
  return result;
}
