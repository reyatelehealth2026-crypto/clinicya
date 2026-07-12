/**
 * activeMatch.ts — pure port of includes/header.php's longest-prefix-wins
 * active-nav matcher (lines ~550-560):
 *
 *   $primaryNavActiveKey = null;
 *   $primaryNavBestLen = 0;
 *   foreach (array_merge($primaryNav, $primaryNavFooter) as $navItem) {
 *       foreach (($navItem['match'] ?? [$navItem['href']]) as $prefix) {
 *           if ($prefix !== '' && strpos($currentPath, $prefix) !== false && strlen($prefix) > $primaryNavBestLen) {
 *               $primaryNavBestLen = strlen($prefix);
 *               $primaryNavActiveKey = $navItem['key'];
 *           }
 *       }
 *   }
 *
 * IMPORTANT — this is a SUBSTRING check, not a "starts with" check, despite
 * 'match' being colloquially called a prefix list: PHP's strpos() finds the
 * needle anywhere in currentPath. Ported faithfully via String#includes(),
 * not String#startsWith(). On a tie in matched length, the FIRST item wins
 * (PHP's condition is strictly `>`, never `>=`) — so item order in the input
 * array matters for ties.
 */

export interface MatchableNavItem {
  key: string;
  href: string;
  match?: readonly string[];
}

export function findActivePrimaryNavKey(
  currentPath: string,
  items: readonly MatchableNavItem[]
): string | null {
  let bestLen = 0;
  let activeKey: string | null = null;

  for (const item of items) {
    const prefixes = item.match && item.match.length > 0 ? item.match : [item.href];
    for (const prefix of prefixes) {
      if (prefix !== '' && currentPath.includes(prefix) && prefix.length > bestLen) {
        bestLen = prefix.length;
        activeKey = item.key;
      }
    }
  }

  return activeKey;
}
