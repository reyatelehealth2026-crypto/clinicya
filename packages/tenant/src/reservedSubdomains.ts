/**
 * Reserved subdomain whitelist — exact port of
 * bootstrap/resolve_subdomain.php::reya_reserved_subdomains(). These are
 * NEVER treated as tenant slugs, no matter what master.tenants says.
 */
export const RESERVED_SUBDOMAINS: readonly string[] = [
  // Infra
  'www',
  'api',
  'admin',
  'platform',
  'cdn',
  'static',
  'assets',
  'mail',
  'webmail',
  'smtp',
  'imap',
  'pop',
  'webhook',
  'webhooks',
  'cpanel',
  'whm',
  'ftp',
  'sftp',
  'ns1',
  'ns2',
  'autodiscover',
  'autoconfig',
  'mta-sts',
  '_dmarc',
  '_dkim',
  'wpad',
  // App-internal subdomains
  'app',
  'dashboard',
  'pharmacy',
  'inventory',
  'inbox',
  'liff',
  'miniapp',
  'docs',
  'help',
  'support',
  'status',
  // Existing re-ya.com DNS records (do NOT treat as tenant slugs)
  'shop', // public storefront (separate service)
  'odoo', // Odoo ERP (separate service)
  'stg', // staging environment (same origin, separate code branch)
  'dev', // dev environment reserved
  // Reserved for future internal use
  'auth',
  'login',
  'signup',
  'register',
  'billing',
  'pay',
  'blog',
  'news',
  'about',
  'contact',
  'legal',
  'terms',
  'privacy',
];
