# -*- coding: utf-8 -*-
"""Rebuild manifest.groups, modules.json and totals from whatever grp_*.json parse.
Idempotent: safe to re-run as more group files land."""
import json, io, os, glob

DATA = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'data')

LAYER = {
    'line_webhook': 'intake', 'shop_browse': 'storefront', 'cart_checkout': 'order',
    'payment_slip': 'payment', 'dispense': 'fulfilment', 'vat_documents': 'finance',
    'loyalty_points': 'loyalty',
}

def load(p):
    return json.load(io.open(p, encoding='utf-8'))

def save(p, d):
    json.dump(d, io.open(p, 'w', encoding='utf-8'), ensure_ascii=False, indent=2)

def main():
    man = load(os.path.join(DATA, 'manifest.json'))
    prev = {g['id']: g for g in man.get('groups', [])}

    groups, skipped = [], []
    mod_role, edges = {}, set()

    for p in sorted(glob.glob(os.path.join(DATA, 'grp_*.json'))):
        fn = os.path.basename(p)
        try:
            d = load(p)
        except Exception as e:
            skipped.append((fn, str(e)[:60])); continue
        gid = d.get('id') or fn[4:-5]
        old = prev.get(gid, {})
        groups.append({
            'id': gid,
            'title': d.get('title') or old.get('title') or gid,
            'file': fn,
            'layer': LAYER.get(gid, old.get('layer', 'core')),
            'summary': (d.get('summary') or old.get('summary') or '')[:400],
            'step_count': len(d.get('steps', [])),
            'case_count': len(d.get('cases', [])),
            'module_count': len(d.get('modules_touched', [])),
        })
        for m in d.get('modules_touched', []):
            mid = m.get('module')
            if mid and mid not in mod_role:
                mod_role[mid] = (m.get('role') or '')[:160]
        for s in d.get('steps', []):
            src = s.get('module')
            for c in s.get('calls', []) or []:
                dst = c.get('module') if isinstance(c, dict) else None
                if src and dst and src != dst:
                    edges.add((src, dst))
    return man, groups, skipped, mod_role, edges

LAYER_OF_MODULE = {
    'line-mini-app': 'ui', 'php-bridge': 'ui', 'shop': 'ui', 'inbox-v2': 'ui',
    'messages': 'ui', 'documents': 'ui', 'membership': 'ui',
    'api-rewards': 'integration', 'api-points': 'integration', 'api-member': 'integration',
    'api-checkout': 'integration', 'api-documents': 'integration', 'api-ajax': 'integration',
    'api-products': 'integration', 'api-ai-admin': 'integration', 'odoo-webhook': 'integration',
    'LINE': 'integration', 'route_by_account': 'infra', 'TenantResolver': 'infra',
    'TenantContext': 'infra', 'Database': 'infra', 'TenantProvisioning': 'infra',
    'MySQL': 'data', 'tenant DB': 'data',
}

def run():
    man, groups, skipped, mod_role, edges = main()

    # --- modules.json ---
    mp = os.path.join(DATA, 'modules.json')
    mod = load(mp)
    existing = {n['id'] for n in mod.get('nodes', [])}
    for mid, role in sorted(mod_role.items()):
        if mid not in existing:
            mod['nodes'].append({
                'id': mid,
                'layer': LAYER_OF_MODULE.get(mid, 'domain'),
                'role': role,
            })
    have = {(e['from'], e['to']) for e in mod.get('edges', [])}
    for a, b in sorted(edges):
        if (a, b) not in have:
            mod['edges'].append({'from': a, 'to': b})
    mod.setdefault('_meta', {})['edge_rule'] = (
        'ลากเส้นเมื่อพบการเรียกจริงใน steps[].calls ของ group ไม่ลากจาก import อย่างเดียว')
    save(mp, mod)

    # --- manifest.json ---
    man['groups'] = groups
    man['totals'] = {
        'groups': len(groups),
        'steps': sum(g['step_count'] for g in groups),
        'cases': sum(g['case_count'] for g in groups),
        'modules': len(mod['nodes']),
        'gaps': len(load(os.path.join(DATA, 'gaps.json')).get('gaps', [])),
    }
    save(os.path.join(DATA, 'manifest.json'), man)

    print('groups   :', len(groups))
    for g in groups:
        print('   %-16s %-12s steps=%-3d cases=%-3d' % (g['id'], g['layer'], g['step_count'], g['case_count']))
    print('modules  :', len(mod['nodes']), 'nodes,', len(mod['edges']), 'edges')
    print('totals   :', man['totals'])
    if skipped:
        print('SKIPPED (not valid JSON yet):')
        for fn, e in skipped:
            print('   ', fn, '->', e)

if __name__ == '__main__':
    run()
