/**
 * orderDetailStyles.ts — port of shop/order-detail.php's own `<style>` block
 * (PHP lines 611-728). Rendered once by page.tsx as a plain `<style>` tag
 * (the same self-contained-`<style>`-block approach the PHP page itself
 * uses), NOT added to `apps/admin/src/app/globals.css` — that file is
 * outside this batch's allowed-paths boundary.
 *
 * Every class name below is prefixed `od-` (order-detail) to avoid bleeding
 * into any other page's DOM — plain `<style>` tags are not scoped like CSS
 * Modules, so an unprefixed `.detail-grid` etc. (the PHP source's literal
 * names) could otherwise collide with a same-named class on another route.
 *
 * All `var(--color-*)`/`var(--space-*)`/`var(--radius-*)`/`var(--text-*)`
 * custom properties referenced here already exist on `apps/admin/src/app/
 * globals.css`'s `:root` (confirmed) — reused as-is, not redefined.
 */
export const ORDER_DETAIL_STYLES = `
.od-detail-grid {
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: var(--space-6);
    align-items: start;
}
.od-detail-section {
    background: #ffffff;
    border: 1px solid var(--color-slate-200);
    border-radius: var(--radius-lg);
    box-shadow: 0 1px 3px rgba(15,23,42,0.04);
    margin-bottom: var(--space-6);
    overflow: hidden;
}
.od-detail-section-hdr {
    padding: var(--space-4) var(--space-5);
    border-bottom: 1px solid var(--color-slate-200);
}
.od-detail-section-hdr h4 {
    margin: 0; font-size: var(--text-base); font-weight: 600; color: var(--color-dark-800);
}
.od-detail-section-body { padding: var(--space-5); }
.od-order-status-pill {
    padding: 6px 16px; border-radius: var(--radius-full);
    font-size: var(--text-sm); font-weight: 500;
}
.od-customer-link {
    display: flex; align-items: center; padding: var(--space-4);
    background: var(--color-slate-50); border-radius: var(--radius-md);
    text-decoration: none; transition: background var(--transition-fast);
}
.od-customer-link:hover { background: var(--color-slate-100); }
.od-order-item-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: var(--space-3); background: var(--color-slate-50);
    border-radius: var(--radius-md); margin-bottom: var(--space-2);
}
.od-totals-row { display: flex; justify-content: space-between; }
.od-form-lbl { display:block; font-size:var(--text-sm); font-weight:500; color:var(--color-dark-700); margin-bottom:var(--space-1); }
.od-form-ctrl {
    width:100%; height:40px; padding:0 12px;
    border:1px solid var(--color-slate-200); border-radius:var(--radius-md);
    background:var(--color-slate-50); font-size:var(--text-sm); color:var(--color-dark-800);
    box-sizing:border-box;
}
.od-form-ctrl:focus { outline:none; border-color:var(--color-primary-400); box-shadow:0 0 0 3px rgba(99,102,241,0.12); }
.od-form-area {
    width:100%; padding:10px 12px;
    border:1px solid var(--color-slate-200); border-radius:var(--radius-md);
    background:var(--color-slate-50); font-size:var(--text-sm); color:var(--color-dark-800);
    box-sizing:border-box; resize:vertical;
}
.od-form-area:focus { outline:none; border-color:var(--color-primary-400); box-shadow:0 0 0 3px rgba(99,102,241,0.12); }
.od-form-sel {
    width:100%; height:40px; padding:0 12px;
    border:1px solid var(--color-slate-200); border-radius:var(--radius-md);
    background:var(--color-slate-50); font-size:var(--text-sm); color:var(--color-dark-800);
    box-sizing:border-box;
}
.od-btn-act {
    width:100%; padding:12px; border:none; border-radius:var(--radius-md);
    font-size:var(--text-sm); font-weight:600; cursor:pointer;
    display:flex; align-items:center; justify-content:center; gap:var(--space-2);
    transition:all var(--transition-fast); margin-bottom:var(--space-2);
}
.od-btn-confirm { background:var(--color-primary-600); color:#fff; }
.od-btn-confirm:hover { background:var(--color-primary-700); }
.od-btn-track   { background:var(--color-violet-600); color:#fff; }
.od-btn-track:hover { background:#6d28d9; }
.od-btn-done    { background:var(--color-emerald-500); color:#fff; }
.od-btn-done:hover { background:var(--color-emerald-600); }
.od-btn-cancel-order {
    width:100%; padding:10px; background:transparent;
    border:1px solid var(--color-rose-300); color:var(--color-rose-600);
    border-radius:var(--radius-md); font-size:var(--text-sm); font-weight:500; cursor:pointer;
    transition:all var(--transition-fast);
}
.od-btn-cancel-order:hover { background:var(--color-rose-50); }
.od-btn-save {
    padding:10px var(--space-4); border:none; border-radius:var(--radius-md);
    background:var(--color-slate-500); color:#fff; font-size:var(--text-sm); font-weight:600;
    cursor:pointer; display:inline-flex; align-items:center; gap:var(--space-2);
}
.od-btn-save:hover { background:var(--color-dark-700); }
.od-btn-approve {
    flex:1; padding:12px; border:none; border-radius:var(--radius-md);
    background:var(--color-emerald-500); color:#fff; font-size:var(--text-sm); font-weight:600;
    cursor:pointer; display:flex; align-items:center; justify-content:center; gap:var(--space-2);
}
.od-btn-approve:hover { background:var(--color-emerald-600); }
.od-btn-reject {
    flex:1; padding:12px; border:none; border-radius:var(--radius-md);
    background:var(--color-rose-500); color:#fff; font-size:var(--text-sm); font-weight:600;
    cursor:pointer; display:flex; align-items:center; justify-content:center; gap:var(--space-2);
}
.od-btn-reject:hover { background:var(--color-rose-600); }
.od-slip-card { border:2px solid var(--color-slate-200); border-radius:var(--radius-lg); overflow:hidden; margin-bottom:var(--space-4); }
.od-slip-card-approved { border-color:var(--color-emerald-300); }
.od-slip-card-rejected  { border-color:var(--color-rose-300); }
.od-slip-card-pending   { border-color:var(--color-amber-300); }
.od-liff-info-box {
    margin-bottom:var(--space-4); padding:var(--space-4);
    background:var(--color-primary-50); border:1px solid var(--color-primary-100);
    border-radius:var(--radius-md);
}
@media (max-width: 1024px) { .od-detail-grid { grid-template-columns: 1fr; } }
.dark .od-detail-section { background:var(--color-dark-800); border-color:var(--color-dark-700); }
.dark .od-detail-section-hdr { border-color:var(--color-dark-700); }
.dark .od-detail-section-hdr h4 { color:var(--color-slate-100); }
.dark .od-customer-link { background:var(--color-dark-700); }
.dark .od-customer-link:hover { background:var(--color-dark-600); }
.dark .od-order-item-row { background:var(--color-dark-700); }
.dark .od-form-ctrl, .dark .od-form-area, .dark .od-form-sel {
    background:var(--color-dark-900); border-color:var(--color-dark-700); color:var(--color-slate-100);
}
.dark .od-liff-info-box { background:rgba(99,102,241,0.1); border-color:rgba(99,102,241,0.2); }
`;
