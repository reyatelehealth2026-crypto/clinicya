<?php
/**
 * platform_shell.php — shared layout (sidebar + topbar) for ALL Platform Owner
 * pages, so the super-admin console is one cohesive dashboard instead of a set
 * of standalone pages.
 *
 * Usage (caller must already have gated on $_SESSION['platform_user_id'] and
 * have a platform PDO available):
 *
 *   require_once __DIR__ . '/../includes/platform_shell.php';
 *   platform_shell_top('dashboard', 'แดชบอร์ด', 'subtitle', $actionsHtml);
 *   // ... page content (cards, tables, forms) ...
 *   platform_shell_bottom();
 *
 * Design: "Data-Dense Dashboard" — Tailwind (CDN) + Sarabun/Inter, emerald/slate
 * brand. Reusable .pf-* component classes + design tokens are defined here so
 * every page looks consistent. Chart.js (CDN) is loaded for dashboard widgets.
 */
declare(strict_types=1);

/** Canonical nav for the platform console (single source of truth). */
function platform_nav_items(): array
{
    return [
        ['key' => 'dashboard', 'label' => 'แดชบอร์ด',       'icon' => 'fa-gauge-high',     'href' => '/admin/platform-dashboard.php', 'section' => 'ภาพรวม'],
        ['key' => 'tenants',   'label' => 'ร้านค้า (Switch)', 'icon' => 'fa-store',          'href' => '/admin/switch-tenant.php',      'section' => 'จัดการร้าน'],
        ['key' => 'customers', 'label' => 'ลูกค้า / ร้าน',    'icon' => 'fa-users',          'href' => '/admin/customers.php',          'section' => 'จัดการร้าน'],
        ['key' => 'approvals', 'label' => 'รออนุมัติ',        'icon' => 'fa-hourglass-half', 'href' => '/admin/tenant-approvals.php',   'section' => 'จัดการร้าน', 'badge' => 'approvals'],
        ['key' => 'beta',      'label' => 'Beta Signups',     'icon' => 'fa-inbox',          'href' => '/admin/beta-signups.php',       'section' => 'การตลาด',   'badge' => 'beta'],
    ];
}

/** Live badge counts (pending approvals, new beta leads). Defensive. */
function platform_nav_badges(): array
{
    $out = ['approvals' => 0, 'beta' => 0];
    try {
        $db = Database::platform()->getConnection();
        $out['approvals'] = (int) $db->query("SELECT COUNT(*) FROM tenants WHERE status='pending_setup'")->fetchColumn();
        $out['beta']      = (int) $db->query("SELECT COUNT(*) FROM beta_signups WHERE status='new'")->fetchColumn();
    } catch (\Throwable $e) {
        // leave zeros
    }
    return $out;
}

/**
 * @param string $active   nav key to highlight
 * @param string $title    page title (topbar)
 * @param string $subtitle small grey line under the title
 * @param string $actions  optional raw HTML rendered on the right of the topbar
 *                          (page-specific buttons / date range / export)
 */
function platform_shell_top(string $active, string $title = '', string $subtitle = '', string $actions = ''): void
{
    $h      = static fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
    $name   = (string) ($_SESSION['platform_user_name'] ?? 'Platform Owner');
    $items  = platform_nav_items();
    $badges = platform_nav_badges();

    // group nav items by section, preserving order
    $sections = [];
    foreach ($items as $it) {
        $sections[$it['section']][] = $it;
    }
    ?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $h($title ?: 'Platform Owner') ?> — REYA Platform</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Sarabun:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{
            --pf-primary:#059669; --pf-primary-dark:#047857;
            --st-active-bg:#d1fae5;    --st-active-fg:#047857;
            --st-pending-bg:#fef3c7;   --st-pending-fg:#b45309;
            --st-suspended-bg:#fee2e2; --st-suspended-fg:#b91c1c;
            --st-terminated-bg:#e2e8f0;--st-terminated-fg:#475569;
            --st-new-bg:#dbeafe;       --st-new-fg:#1d4ed8;
            --st-contacted-bg:#cffafe; --st-contacted-fg:#0e7490;
            --st-demo-bg:#ede9fe;      --st-demo-fg:#6d28d9;
        }
        body{font-family:'Sarabun','Inter',sans-serif;background:#f1f5f9;}
        .tnum{font-variant-numeric:tabular-nums;font-feature-settings:"tnum"}
        /* nav */
        .nav-link{position:relative}
        .nav-link.active{background:#ecfdf5;color:#047857;font-weight:600}
        .nav-link.active i{color:#059669}
        .nav-link.active::before{content:"";position:absolute;left:0;top:18%;bottom:18%;width:3px;border-radius:0 3px 3px 0;background:#059669}
        /* cards */
        .pf-card{background:#fff;border:1px solid rgba(226,232,240,.8);border-radius:1rem;
            box-shadow:0 1px 2px rgba(15,23,42,.04),0 8px 24px -14px rgba(15,23,42,.10)}
        .pf-card-pad{padding:1.25rem}
        .pf-int{transition:transform .2s cubic-bezier(.16,1,.3,1),box-shadow .2s cubic-bezier(.16,1,.3,1)}
        .pf-int:hover{transform:translateY(-2px);box-shadow:0 4px 10px rgba(15,23,42,.06),0 18px 38px -16px rgba(15,23,42,.18)}
        .pf-kpi-fig{font-size:1.85rem;font-weight:800;line-height:1.05;color:#0f172a;font-variant-numeric:tabular-nums}
        /* pills */
        .pf-pill{display:inline-flex;align-items:center;gap:.3rem;font-size:.7rem;font-weight:600;
            padding:.18rem .6rem;border-radius:999px;white-space:nowrap;line-height:1.4}
        .pf-pill[data-st="active"]{background:var(--st-active-bg);color:var(--st-active-fg)}
        .pf-pill[data-st="pending_setup"]{background:var(--st-pending-bg);color:var(--st-pending-fg)}
        .pf-pill[data-st="suspended"]{background:var(--st-suspended-bg);color:var(--st-suspended-fg)}
        .pf-pill[data-st="terminated"]{background:var(--st-terminated-bg);color:var(--st-terminated-fg)}
        .pf-pill[data-st="new"]{background:var(--st-new-bg);color:var(--st-new-fg)}
        .pf-pill[data-st="contacted"]{background:var(--st-contacted-bg);color:var(--st-contacted-fg)}
        .pf-pill[data-st="demo_booked"]{background:var(--st-demo-bg);color:var(--st-demo-fg)}
        .pf-pill[data-st="trial_started"]{background:var(--st-pending-bg);color:var(--st-pending-fg)}
        .pf-pill[data-st="signed_up"]{background:var(--st-active-bg);color:var(--st-active-fg)}
        .pf-pill[data-st="spam"],.pf-pill[data-st="disqualified"]{background:var(--st-terminated-bg);color:var(--st-terminated-fg)}
        /* buttons */
        .pf-btn{display:inline-flex;align-items:center;justify-content:center;gap:.45rem;font-family:inherit;
            font-weight:600;font-size:.875rem;border-radius:.7rem;padding:.55rem 1rem;border:1px solid transparent;
            cursor:pointer;transition:background .15s,border-color .15s,transform .15s,box-shadow .15s;text-decoration:none;line-height:1.2}
        .pf-btn:active{transform:scale(.97)}
        .pf-btn:focus-visible{outline:none;box-shadow:0 0 0 3px rgba(5,150,105,.4)}
        .pf-btn-primary{background:#059669;color:#fff}
        .pf-btn-primary:hover{background:#047857}
        .pf-btn-ghost{background:#fff;color:#334155;border-color:#e2e8f0}
        .pf-btn-ghost:hover{background:#f8fafc;border-color:#cbd5e1}
        .pf-btn-dark{background:#0f172a;color:#fff}
        .pf-btn-dark:hover{background:#1e293b}
        .pf-btn-danger{background:#fff;color:#dc2626;border-color:#fecaca}
        .pf-btn-danger:hover{background:#fef2f2;border-color:#fca5a5}
        .pf-iconbtn{width:2rem;height:2rem;border-radius:.6rem;display:inline-flex;align-items:center;justify-content:center;
            background:#f1f5f9;color:#475569;border:none;cursor:pointer;transition:background .15s,color .15s;text-decoration:none}
        .pf-iconbtn:hover{background:#e2e8f0;color:#0f172a}
        .pf-iconbtn:focus-visible{outline:none;box-shadow:0 0 0 3px rgba(5,150,105,.35)}
        /* inputs */
        .pf-input{width:100%;border:1px solid #cbd5e1;border-radius:.7rem;padding:.55rem .85rem;font-size:.875rem;
            background:#fff;font-family:inherit;color:#0f172a}
        .pf-input:focus{outline:none;border-color:#059669;box-shadow:0 0 0 3px rgba(5,150,105,.15)}
        /* chips */
        .pf-chip{display:inline-flex;align-items:center;gap:.35rem;font-size:.8rem;font-weight:600;padding:.4rem .8rem;
            border-radius:999px;background:#fff;color:#475569;border:1px solid #e2e8f0;cursor:pointer;transition:all .15s;text-decoration:none}
        .pf-chip:hover{border-color:#cbd5e1;background:#f8fafc}
        .pf-chip.on{background:#ecfdf5;color:#047857;border-color:#6ee7b7}
        .pf-chip .pf-chip-n{font-variant-numeric:tabular-nums;font-size:.7rem;opacity:.7}
        /* table */
        .pf-th{text-align:left;font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#64748b;padding:.7rem 1rem}
        .pf-empty{text-align:center;padding:3rem 1rem;color:#94a3b8}
        ::-webkit-scrollbar{width:8px;height:8px} ::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:4px}
        @media(prefers-reduced-motion:reduce){.pf-int{transition:none}.pf-int:hover{transform:none}}
    </style>
</head>
<body class="min-h-screen">
<div class="flex min-h-screen">
    <!-- Sidebar -->
    <aside id="pfSidebar" class="fixed lg:static z-40 inset-y-0 left-0 w-64 bg-white border-r border-slate-200 flex flex-col -translate-x-full lg:translate-x-0 transition-transform">
        <div class="px-5 py-5 border-b border-slate-100 flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-emerald-600 text-white flex items-center justify-center font-extrabold text-lg shadow-sm">R</div>
            <div>
                <div class="font-bold text-slate-900 leading-tight">REYA Platform</div>
                <div class="text-[11px] text-slate-400">Platform Owner Console</div>
            </div>
        </div>
        <nav class="flex-1 px-3 py-4 space-y-4 overflow-y-auto">
            <?php foreach ($sections as $sectionName => $sectionItems): ?>
                <div>
                    <div class="px-3 mb-1 text-[10px] font-bold uppercase tracking-wider text-slate-400"><?= $h($sectionName) ?></div>
                    <div class="space-y-0.5">
                        <?php foreach ($sectionItems as $it):
                            $isActive = $active === $it['key'];
                            $count = isset($it['badge']) ? (int) ($badges[$it['badge']] ?? 0) : 0; ?>
                            <a href="<?= $h($it['href']) ?>"
                               class="nav-link <?= $isActive ? 'active' : 'text-slate-600 hover:bg-slate-50' ?> flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition">
                                <i class="fas <?= $h($it['icon']) ?> w-5 text-center"></i>
                                <span class="flex-1"><?= $h($it['label']) ?></span>
                                <?php if ($count > 0): ?>
                                    <span class="text-[11px] font-bold bg-rose-500 text-white rounded-full px-2 py-0.5 tnum"><?= $count ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </nav>
        <div class="px-3 py-3 border-t border-slate-100">
            <a href="/admin/platform-login.php?action=logout" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium text-slate-500 hover:bg-red-50 hover:text-red-600 transition">
                <i class="fas fa-arrow-right-from-bracket w-5 text-center"></i> ออกจากระบบ
            </a>
        </div>
    </aside>
    <div id="pfOverlay" class="fixed inset-0 bg-black/30 z-30 hidden lg:hidden" onclick="pfToggle()"></div>

    <!-- Main -->
    <div class="flex-1 flex flex-col min-w-0">
        <header class="bg-white/90 backdrop-blur border-b border-slate-200 sticky top-0 z-20">
            <div class="px-5 lg:px-8 py-3.5 flex items-center justify-between gap-4">
                <div class="flex items-center gap-3 min-w-0">
                    <button class="lg:hidden text-slate-500" onclick="pfToggle()" aria-label="เปิดเมนู"><i class="fas fa-bars text-lg"></i></button>
                    <div class="min-w-0">
                        <h1 class="text-lg font-bold text-slate-900 truncate"><?= $h($title) ?></h1>
                        <?php if ($subtitle !== ''): ?><p class="text-xs text-slate-400 truncate"><?= $h($subtitle) ?></p><?php endif; ?>
                    </div>
                </div>
                <div class="flex items-center gap-3 flex-shrink-0">
                    <?php if ($actions !== ''): ?><div class="flex items-center gap-2"><?= $actions ?></div><?php endif; ?>
                    <div class="hidden sm:flex items-center gap-2.5 pl-3 border-l border-slate-200">
                        <div class="text-right">
                            <div class="text-sm font-semibold text-slate-700 leading-tight"><?= $h($name) ?></div>
                            <div class="text-[11px] text-slate-400">Platform Owner</div>
                        </div>
                        <div class="w-9 h-9 rounded-full bg-emerald-600 text-white flex items-center justify-center font-bold"><?= $h(mb_substr($name, 0, 1)) ?></div>
                    </div>
                </div>
            </div>
        </header>
        <main class="flex-1 px-5 lg:px-8 py-6 max-w-7xl w-full mx-auto">
    <?php
}

function platform_shell_bottom(): void
{
    ?>
        </main>
    </div>
</div>
<script>
function pfToggle(){var s=document.getElementById('pfSidebar'),o=document.getElementById('pfOverlay');s.classList.toggle('-translate-x-full');o.classList.toggle('hidden');}
</script>
</body>
</html>
    <?php
}
