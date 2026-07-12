import type { ReactNode } from 'react';

/**
 * Tabs — React port of includes/components/tabs.php's renderTabs($tabs,
 * $activeTab, $options). Server Component: plain `<a>` links that carry the
 * current `tab=` query param plus any `preserveParams` copied over from the
 * current search params (mirrors the PHP component's
 * `$baseUrl . '?tab=' . urlencode($key) . $preservedQuery` construction) —
 * no client JS needed, a tab click is a normal navigation.
 */
export interface TabItem {
  key: string;
  label: ReactNode;
  icon?: ReactNode;
  disabled?: boolean;
}

export interface TabsProps {
  tabs: TabItem[];
  activeTab: string;
  /** Base pathname (no query string), e.g. "/users". */
  basePath: string;
  /** Current search params to preserve on every tab link, minus `tab` itself. */
  preserveParams?: Record<string, string | undefined>;
}

export function Tabs({ tabs, activeTab, basePath, preserveParams = {} }: TabsProps) {
  const preservedQuery = new URLSearchParams();
  for (const [key, value] of Object.entries(preserveParams)) {
    if (value !== undefined && value !== '') {
      preservedQuery.set(key, value);
    }
  }

  return (
    <div className="tabs-component">
      <div className="tabs-nav tabs-pills tabs-md">
        {tabs.map((tab) => {
          const isActive = tab.key === activeTab;
          const query = new URLSearchParams(preservedQuery);
          query.set('tab', tab.key);
          const href = tab.disabled ? '#' : `${basePath}?${query.toString()}`;

          return (
            <a
              key={tab.key}
              href={href}
              aria-current={isActive ? 'page' : undefined}
              className={`tab-item${isActive ? ' active' : ''}${tab.disabled ? ' disabled' : ''}`}
            >
              {tab.icon ? <span aria-hidden="true">{tab.icon}</span> : null}
              <span className="tab-label">{tab.label}</span>
            </a>
          );
        })}
      </div>
    </div>
  );
}
