# -*- coding: utf-8 -*-
"""Canonicalise module ids in modules.json: tracers each invented their own naming
(bare name vs path vs alias). Merge identities, dedupe, drop self-edges."""
import json, io, os

DATA = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'data')

ALIAS = {
    'loyalty': 'LoyaltyPoints',
    'line': 'LINE', 'LINE API': 'LINE', 'line-manager': 'LineAccountManager',
    'tenant DB': 'MySQL', 'tenant-db': 'MySQL', 'schema': 'MySQL',
    'inbox-v2-ui': 'inbox-v2',
    'documents-page': 'documents', 'documents-ui': 'documents',
    'rewards': 'api-rewards', 'member': 'api-member', 'points': 'api-points',
    'checkout': 'api-checkout',
    'points-claim': 'PointsClaimAPI', 'crm-dashboard-api': 'CRMDashboardService',
    'resolve_subdomain': 'TenantResolver',
    'TenantOnboardingService': 'TenantProvisioning',
}

def canon(x):
    if not x:
        return x
    base = str(x).split('/')[-1]
    if base.endswith('.php'):
        base = base[:-4]
    if base.endswith('.ts') or base.endswith('.tsx'):
        base = base.rsplit('.', 1)[0]
    return ALIAS.get(base, ALIAS.get(str(x), base))

def main():
    p = os.path.join(DATA, 'modules.json')
    m = json.load(io.open(p, encoding='utf-8'))

    merged = {}
    for n in m.get('nodes', []):
        cid = canon(n.get('id'))
        cur = merged.get(cid)
        if cur is None:
            n = dict(n); n['id'] = cid; merged[cid] = n
        elif not cur.get('role') and n.get('role'):
            cur['role'] = n['role']

    seen, edges = set(), []
    for e in m.get('edges', []):
        a, b = canon(e.get('from')), canon(e.get('to'))
        if a and b and a != b and a in merged and b in merged and (a, b) not in seen:
            seen.add((a, b)); edges.append({'from': a, 'to': b})

    deg = {}
    for e in edges:
        deg[e['from']] = deg.get(e['from'], 0) + 1
        deg[e['to']] = deg.get(e['to'], 0) + 1

    m['nodes'] = sorted(merged.values(), key=lambda n: (-deg.get(n['id'], 0), n['id']))
    m['edges'] = edges
    json.dump(m, io.open(p, 'w', encoding='utf-8'), ensure_ascii=False, indent=2)

    orph = [n['id'] for n in m['nodes'] if deg.get(n['id'], 0) == 0]
    print('nodes %d, edges %d, orphans %d' % (len(m['nodes']), len(edges), len(orph)))
    if orph:
        print('orphans:', ', '.join(orph))

if __name__ == '__main__':
    main()
