import { PageHeader } from '@/components/PageHeader';
import { Tabs } from '@/components/Tabs';
import { Toolbar } from '@/components/Toolbar';
import { Pagination } from '@/components/Pagination';
import { EmptyState } from '@/components/EmptyState';
import { requireTenantPageContext } from './_lib/session';
import { isOdooIntegrationEnabled } from './_lib/odoo';
import { getAllTags, getUsersListPage, parseUsersListFilters, type RawSearchParams } from './queries';
import { FiltersForm } from './_components/FiltersForm';
import { UsersTable } from './_components/UsersTable';

/**
 * /users — Server Component port of users.php's LINE tab (the ONLY tab this
 * batch ports; see module doc below for the Odoo tab boundary). Reads
 * filters/pagination from the URL exactly like the PHP page (plain GET
 * params, no client-side state) and renders via the shared primitives in
 * apps/admin/src/components/.
 *
 * OUT OF SCOPE (Phase 8 follow-up, not silently dropped): users.php's Odoo
 * tab (`?tab=odoo`, lines 622-1093) — its odoo_line_users/odoo_customer_
 * projection queries and api/odoo-user-link.php / api/odoo-webhooks-
 * dashboard.php AJAX calls are not ported. When ODOO_INTEGRATION_ENABLED is
 * true and a caller requests `?tab=odoo`, this renders a stub panel linking
 * back to the still-live PHP page instead of either faking the tab or
 * silently redirecting to the LINE tab. When ODOO_INTEGRATION_ENABLED is
 * false, `?tab=odoo` is not offered at all and any request for it falls
 * back to the LINE tab — this mirrors users.php lines 36-41's
 * `$allowedUserTabs` gate exactly.
 */

interface UsersPageProps {
  searchParams: Promise<RawSearchParams>;
}

export default async function UsersPage({ searchParams }: UsersPageProps) {
  const params = await searchParams;
  const { db, session } = await requireTenantPageContext();

  const odooEnabled = isOdooIntegrationEnabled();
  const requestedTab = typeof params.tab === 'string' ? params.tab : 'line';
  const activeTab = requestedTab === 'odoo' && odooEnabled ? 'odoo' : 'line';

  const tabs = odooEnabled
    ? [
        { key: 'line', label: 'LINE Users' },
        { key: 'odoo', label: 'Odoo Customers' },
      ]
    : [{ key: 'line', label: 'LINE Users' }];

  const preserveParams: Record<string, string | undefined> = {
    search: typeof params.search === 'string' ? params.search : undefined,
    tag: typeof params.tag === 'string' ? params.tag : undefined,
    tier: typeof params.tier === 'string' ? params.tier : undefined,
    points: typeof params.points === 'string' ? params.points : undefined,
    activity: typeof params.activity === 'string' ? params.activity : undefined,
    purchase: typeof params.purchase === 'string' ? params.purchase : undefined,
    status: typeof params.status === 'string' ? params.status : undefined,
  };

  if (activeTab === 'odoo') {
    return (
      <div>
        <PageHeader
          title="Customers"
          subtitle="ลูกค้า Odoo ที่เชื่อมแล้ว"
          primaryAction={{ label: 'Odoo Dashboard', href: '/odoo-dashboard.php', variant: 'primary' }}
        />
        <Tabs tabs={tabs} activeTab={activeTab} basePath="/users" preserveParams={preserveParams} />
        <EmptyState
          heading="หน้า Odoo Customers ยังอยู่บนระบบเดิม"
          sub="ฟีเจอร์นี้จะถูกย้ายมาที่นี่ใน Phase 8 (Odoo stack) — ตอนนี้ใช้หน้า PHP เดิมต่อไปก่อน"
          cta={{ label: 'เปิดหน้า Odoo Customers (PHP)', href: '/users.php?tab=odoo' }}
        />
      </div>
    );
  }

  const filters = parseUsersListFilters(params);
  const [{ users, totalUsers, totalPages, page, perPage }, allTags] = await Promise.all([
    getUsersListPage(db, filters),
    getAllTags(db, session.currentBotId),
  ]);

  return (
    <div>
      <PageHeader
        title="Customers"
        subtitle={`ทั้งหมด ${totalUsers.toLocaleString()} คน`}
        primaryAction={odooEnabled ? { label: 'Odoo Dashboard', href: '/odoo-dashboard.php', variant: 'primary' } : null}
      />

      <Tabs tabs={tabs} activeTab={activeTab} basePath="/users" preserveParams={preserveParams} />

      <Toolbar
        hiddenFields={{ tab: 'line' }}
        search={{ name: 'search', value: filters.search, placeholder: 'ชื่อ, เบอร์โทร, LINE ID...' }}
        activeFilterCount={
          [filters.tier, filters.points, filters.activity, filters.purchase, filters.status, filters.tag ? String(filters.tag) : '']
            .filter(Boolean).length
        }
        advanced={<FiltersForm filters={filters} allTags={allTags} />}
      />

      <UsersTable users={users} allTags={allTags} />

      {totalPages > 1 ? (
        <Pagination
          currentPage={page}
          totalPages={totalPages}
          perPage={perPage}
          basePath="/users"
          queryParams={{ tab: 'line', ...preserveParams }}
          total={totalUsers}
        />
      ) : null}
    </div>
  );
}
