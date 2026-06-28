#!/usr/bin/env node
// Auto-extract pages, integrations, cron, events, data entities — tagged by persona
import fs from 'node:fs';
import path from 'node:path';

const ROOT = process.cwd();
const KG = JSON.parse(fs.readFileSync(`${ROOT}/.understand-anything/knowledge-graph.json`, 'utf8'));
const nodeById = new Map(KG.nodes.map(n => [n.id, n]));

// ---------- Persona classifier ----------
function classifyPersona(filePath) {
  const p = filePath.replace(/\\/g, '/');
  // Customer = LINE Mini App / LIFF
  if (p.startsWith('line-mini-app/') || p.startsWith('liff/') || p.startsWith('liff-app/')) return 'customer';
  // Owner = platform-level admin (super_admin)
  if (p.startsWith('admin/') ||
      /^admin-(beta|users|reward|points)/.test(p) ||
      p === 'admin-users.php' ||
      p === 'beta.php' ||
      /platform-login|switch-tenant|beta-signups/.test(p))
    return 'owner';
  // Admin-side modern Next.js dashboard
  if (p.startsWith('frontend/src/app/')) return 'admin';
  // Admin = everything else PHP root + retail-api + backend
  if (p.startsWith('backend/') || p.startsWith('retail-api/')) return 'admin';
  if (/\.php$/.test(p) && !p.includes('/')) return 'admin'; // root PHP files
  if (p.startsWith('includes/') || p.startsWith('classes/') || p.startsWith('modules/') ||
      p.startsWith('api/') || p.startsWith('inventory/') || p.startsWith('shop/') ||
      p.startsWith('settings/') || p.startsWith('app/Views/')) return 'admin';
  return null; // infra/data/docs → not persona-bound
}

// ---------- Touchpoint classifier ----------
function classifyTouchpoint(filePath) {
  const p = filePath.replace(/\\/g, '/');
  if (p.startsWith('line-mini-app/') || p.startsWith('liff/')) return 'mini-app';
  if (p === 'webhook.php' || p.startsWith('api/webhook') || /webhook\.php$/.test(p)) return 'line-chat';
  if (p.startsWith('cron/')) return 'background';
  if (p.startsWith('api/')) return 'api';
  return 'admin-web';
}

// ---------- Extract pages ----------
const PAGE_TYPES = new Set(['file', 'endpoint']);
const pages = [];
for (const n of KG.nodes) {
  if (!PAGE_TYPES.has(n.type)) continue;
  const fp = n.filePath || '';
  if (!fp) continue;
  // only include files that are user-facing entry points
  const isPhp = fp.endsWith('.php');
  const isPage = fp.endsWith('page.tsx') || fp.endsWith('page.ts');
  const isApi  = fp.startsWith('api/') && isPhp;
  const isAdminPhp = isPhp && !fp.startsWith('includes/') && !fp.startsWith('classes/') &&
                     !fp.startsWith('modules/') && !fp.startsWith('cron/') &&
                     !fp.startsWith('install/') && !fp.startsWith('database/') &&
                     !fp.includes('webhook') && !fp.startsWith('test_') &&
                     !fp.startsWith('verify_') && !fp.startsWith('check_') &&
                     !fp.startsWith('sync_') && !fp.startsWith('fix_') &&
                     !fp.startsWith('bulk_') && !fp.startsWith('count_') &&
                     !fp.startsWith('describe_') && !fp.startsWith('dump_') &&
                     !fp.startsWith('final_') && !fp.startsWith('get_') &&
                     !fp.startsWith('force_') && !fp.startsWith('relax_') &&
                     !fp.startsWith('trigger_') && !fp.startsWith('update_') &&
                     !fp.startsWith('add_col') && !fp.startsWith('migration_') &&
                     !fp.startsWith('app/Views/') && !fp.endsWith('.bak');
  if (!(isPage || isAdminPhp)) continue;

  const persona = classifyPersona(fp);
  if (!persona) continue;

  pages.push({
    id: `page:${fp}`,
    name: path.basename(fp).replace(/\.(php|tsx?|html)$/, '').replace(/[-_]/g, ' '),
    filePath: fp,
    url: deriveUrl(fp),
    persona,
    touchpoint: classifyTouchpoint(fp),
    summary: n.summary || '',
    tags: n.tags || [],
    complexity: n.complexity || 'moderate'
  });
}

function deriveUrl(fp) {
  if (fp.startsWith('line-mini-app/src/app/')) {
    return '/' + fp.replace('line-mini-app/src/app/', '').replace(/\/page\.tsx?$/, '').replace(/^page\.tsx?$/, '');
  }
  if (fp.startsWith('admin/')) return '/' + fp.replace(/\.php$/, '');
  if (fp.endsWith('.php')) return '/' + fp.replace(/\.php$/, '');
  return '';
}

// ---------- Cron jobs ----------
const cronJobs = KG.nodes
  .filter(n => n.filePath && n.filePath.startsWith('cron/') && n.filePath.endsWith('.php'))
  .map(n => ({
    id: `cron:${n.filePath}`,
    name: path.basename(n.filePath, '.php').replace(/[-_]/g, ' '),
    filePath: n.filePath,
    summary: n.summary || '',
    tags: n.tags || []
  }));

// ---------- Integrations ----------
const INTEGRATION_PATTERNS = [
  { id: 'odoo',     name: 'Odoo ERP',           match: ['Odoo'] },
  { id: 'gemini',   name: 'Gemini AI',          match: ['Gemini'] },
  { id: 'openai',   name: 'OpenAI',             match: ['OpenAI'] },
  { id: 'line',     name: 'LINE Messaging API', match: ['LineAPI', 'LineMessag'] },
  { id: 'liff',     name: 'LINE LIFF',          match: ['liff', 'LIFF', 'line-miniapp'] },
  { id: 'telegram', name: 'Telegram',           match: ['Telegram'] },
  { id: 'facebook', name: 'Facebook Messenger', match: ['FacebookMessenger'] },
  { id: 'tiktok',   name: 'TikTok',             match: ['tiktok', 'TikTok'] },
  { id: 'prisma',   name: 'Prisma ORM',         match: ['prisma', 'Prisma'] },
  { id: 'redis',    name: 'Redis',              match: ['Redis', 'redis'] },
  { id: 'socketio', name: 'Socket.IO',          match: ['socket', 'Socket'] }
];

const integrations = INTEGRATION_PATTERNS.map(p => ({
  id: `integration:${p.id}`,
  name: p.name,
  category: 'external',
  usedByFiles: KG.nodes.filter(n => p.match.some(m => (n.filePath || '').includes(m))).map(n => n.filePath).slice(0, 30)
})).filter(i => i.usedByFiles.length > 0);

// ---------- Data entities (from table: nodes) ----------
const dataEntities = [];
for (const n of KG.nodes) {
  if (n.type !== 'table') continue;
  if (!n.id.includes(':')) continue;
  const parts = n.id.split(':');
  if (parts.length < 3) continue; // skip migration-file-level table nodes
  const tableName = parts[parts.length - 1];
  if (tableName.length < 2 || /^(if|in|so|then|apply|not|add|c|b2|ai)$/i.test(tableName)) continue;
  dataEntities.push({
    id: n.id,
    name: tableName,
    summary: n.summary || '',
    tags: n.tags || []
  });
}
// dedupe by name
const dataByName = new Map();
for (const d of dataEntities) if (!dataByName.has(d.name)) dataByName.set(d.name, d);
const dataEntitiesUnique = [...dataByName.values()];

// ---------- Webhook handlers (events) ----------
const webhookHandlers = KG.nodes
  .filter(n => n.id.startsWith('function:webhook.php:'))
  .map(n => ({
    id: n.id,
    name: n.name,
    summary: n.summary || '',
    source: 'line-webhook'
  }));

// ---------- Output ----------
const out = {
  generatedAt: new Date().toISOString(),
  personas: [
    { id: 'owner',    name: 'เจ้าของแพลตฟอร์ม',    role: 'super_admin', color: '#7c3aed', description: 'Platform operator — manages tenants, beta signups, platform-wide settings' },
    { id: 'admin',    name: 'แอดมินร้าน',          role: 'admin/pharmacist/staff', color: '#0ea5e9', description: 'Pharmacy operator — inbox, dispense, POS, inventory, Odoo dashboards' },
    { id: 'customer', name: 'ลูกค้า (LIFF)',       role: 'customer', color: '#10b981', description: 'End customer — shop, cart, checkout, AI chat, order tracking through LINE Mini App' }
  ],
  pages,
  features: [],   // filled by LLM
  journeys: [],   // filled by LLM
  touchpoints: [
    { id: 'mini-app',   name: 'LINE Mini App (LIFF)',  persona: ['customer'] },
    { id: 'line-chat',  name: 'LINE OA Chat',          persona: ['admin', 'customer'] },
    { id: 'admin-web',  name: 'Admin Web Dashboard',   persona: ['owner', 'admin'] },
    { id: 'api',        name: 'REST API',              persona: ['admin', 'customer'] },
    { id: 'background', name: 'Cron / Background Jobs', persona: ['admin'] }
  ],
  integrations,
  events: webhookHandlers,
  cronJobs,
  dataEntities: dataEntitiesUnique,
  stats: {}
};

out.stats = {
  pages: pages.length,
  pagesByPersona: pages.reduce((a, p) => (a[p.persona] = (a[p.persona] || 0) + 1, a), {}),
  cronJobs: cronJobs.length,
  integrations: integrations.length,
  webhookHandlers: webhookHandlers.length,
  dataEntities: dataEntitiesUnique.length
};

fs.writeFileSync(`${ROOT}/.understand-anything/persona-graph.json`, JSON.stringify(out, null, 2));
console.log('Extracted:');
console.log('  pages:', out.stats.pages, '— byPersona:', out.stats.pagesByPersona);
console.log('  cron:', out.stats.cronJobs, ' integrations:', out.stats.integrations);
console.log('  webhook handlers:', out.stats.webhookHandlers, ' data entities:', out.stats.dataEntities);
console.log('Output:', `${ROOT}/.understand-anything/persona-graph.json`);
