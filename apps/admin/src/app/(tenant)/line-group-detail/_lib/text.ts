/**
 * text.ts — Thai-safe multi-byte-aware string helpers mirroring PHP's
 * `mb_substr()`/`mb_strlen()`, used by line-group-detail.php's message
 * preview (line 167-168):
 *
 *   <?= htmlspecialchars(mb_substr($msg['content'], 0, 100)) ?>
 *   <?= mb_strlen($msg['content']) > 100 ? '...' : '' ?>
 *
 * `mb_substr()`/`mb_strlen()` (UTF-8 mode, the app's charset — see
 * CLAUDE.md's `utf8mb4_unicode_ci`) count by Unicode CODE POINT, not byte,
 * and not UTF-16 code unit. A naive JS `str.slice(0, 100)` counts UTF-16
 * code units instead — for pure Thai text (all of U+0E00–U+0E7F, no
 * surrogate pairs) that happens to coincide with code-point counting, but
 * LINE message content routinely mixes in emoji/other supplementary-plane
 * characters (surrogate pairs in UTF-16, 2 code units each), where a naive
 * `.slice()` would cut a character in half (producing a lone surrogate /
 * mangled glyph) and count it as "2 characters" against the 100-char
 * budget — diverging from PHP's byte-identical, code-point-accurate output.
 * `Array.from(str)` iterates by Unicode code point (handles surrogate pairs
 * correctly, same granularity mb_substr uses under a UTF-8 connection), so
 * that's used here instead of `.slice()`.
 */

export function mbSubstr(str: string, length: number): string {
  return Array.from(str).slice(0, length).join('');
}

export function mbStrlen(str: string): number {
  return Array.from(str).length;
}

/** `mb_substr($s, 0, 100) . (mb_strlen($s) > 100 ? '...' : '')` in one call. */
export function truncateMb(str: string, length: number): string {
  return mbStrlen(str) > length ? `${mbSubstr(str, length)}...` : str;
}
