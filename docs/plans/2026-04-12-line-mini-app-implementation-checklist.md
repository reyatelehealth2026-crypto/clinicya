# LINE Mini App — Implementation Checklist

**Purpose:** Track B2C CRM + shop flow for [`line-mini-app`](../../line-mini-app/) (Next.js) against PHP backend.  
**Workflow:** Aligns with subagent-driven development — one task → implement → spec review → code quality review → next task.

**Related:** [`api/checkout.php`](../../api/checkout.php), [`webhook.php`](../../webhook.php), [`liff-app/`](../liff-app/) (Vite reference).  
**Full coverage + subagent orchestration:** [`2026-04-13-line-mini-app-100pct-subagent-orchestration.md`](./2026-04-13-line-mini-app-100pct-subagent-orchestration.md)

| Phase | Status |
|-------|--------|
| **A — Access & configuration** | `completed` |
| **B — E-commerce core** | `completed` |
| **C — Engagement & CRM** | `completed` |
| **D — Extended services** | `deferred` (see Notes) |
| **E — Quality gates** | `ongoing` (per release) |

---

## Phase A — Access & configuration (do first) <!-- phase-a-access: completed -->

| ID | Task | Done when |
|----|------|-----------|
| A1 | **Single source of truth for LIFF** — Document which URL customers use: Next deploy (e.g. Vercel) vs legacy [`liff/index.php`](../../liff/index.php). OA Endpoint must match. | Table: environment, LIFF ID, LINE account, test link |
| A2 | **Env audit** — `NEXT_PUBLIC_PHP_API_BASE_URL`, `NEXT_PUBLIC_LINE_LIFF_ID`, `NEXT_PUBLIC_LINE_ACCOUNT_ID` per env (dev/staging/prod). | `.env.example` + deploy notes consistent |
| A3 | **Browser → PHP** — Verify `php-bridge` calls (e.g. [`api/member.php`](../../api/member.php)) work from Next origin (CORS / no mixed-content). | Manual test from deployed Next |

### A1 — LIFF entry URL (single source of truth)

| Environment | Customer-facing LIFF URL | LIFF ID | `NEXT_PUBLIC_LINE_ACCOUNT_ID` | Test |
|-------------|-------------------------|---------|----------------------------------|------|
| **Production** | Set in LINE Developers → LIFF → **Endpoint URL** = your Next app origin (e.g. `https://<project>.vercel.app` or custom domain). | From same LIFF app | DB: `line_accounts.id` for that OA | Open OA → Mini App / rich menu → home loads |
| **Legacy** | [`liff/index.php`](../../liff/index.php) serves the Vite [`liff-app`](../../liff-app/) SPA — **different stack** from `line-mini-app`. | Separate LIFF app if used | Same tenant rules | Do not mix endpoints on one LIFF ID |

**Rule:** One LIFF ID → one Endpoint URL. If migrating to Next, create/update the LIFF app so the endpoint matches the Next deploy; keep PHP API base in `NEXT_PUBLIC_PHP_API_BASE_URL` for API calls.

### A3 — Browser → PHP

- **Member / CRM (`php-bridge`):** `GET/POST` to `{NEXT_PUBLIC_PHP_API_BASE_URL}/api/member.php` from the browser — cross-origin. [`api/member.php`](../../api/member.php) sends `Access-Control-Allow-Origin: *`, `OPTIONS` preflight, JSON responses — OK for HTTPS→HTTPS.
- **Shop (`/api/checkout` Next proxy):** The mini app calls **same-origin** `/api/checkout`, which server-side proxies to PHP — no browser CORS issue; avoid mixed content by serving Next over **HTTPS** and pointing PHP base to **HTTPS**.

---

## Phase B — E-commerce core

| ID | Task | Done when |
|----|------|-----------|
| B1 | **Cart edit** — Wire `update_cart` / `remove_from_cart` / optional `clear_cart` in UI ([`checkout.php`](../../api/checkout.php) already exposes actions). | Quantity +/- and remove; cart refetches |
| B2 | **PromptPay QR** — Use `action=promptpay_qr` in checkout UX so transfer users see QR for the payable amount before/after order per product rules. | QR or image URL displayed; works with `shop_settings.promptpay_number` |
| B3 | **Promo codes (optional)** — Call `validate_promo` before `create_order` if business needs coupons. | Discount reflected in totals when valid |
| B4 | **E2E smoke test** — One scripted path: product → cart → checkout (transfer + COD) → order list → slip upload. | Steps recorded in this doc or QA sheet |

### B4 — E2E smoke (manual)

1. Open mini app home (LIFF) → **ร้านค้า** → add product → **ตะกร้า** (± quantity, remove line, optional ล้างตะกร้า).
2. **ชำระเงิน** → โอน: ตรวจ QR พร้อมเพย์ + ยืนยันคำสั่งซื้อ; แยกเคส **COD** ไม่ต้องอัปโหลดสลิป.
3. **ออเดอร์** → เปิดรายการ → โอน: ตรวจ QR บนหน้ารายละเอียด + อัปโหลดสลิป (ถ้ายังรอชำระ).
4. (Optional) โค้ดส่วนลด: กด **ใช้โค้ด** แล้วยืนยันว่ายอดและ `create_order` ส่ง `subtotal` หลังหักส่วนลดสอดคล้อง [`checkout.php`](../../api/checkout.php).

---

## Phase C — Engagement & CRM in app

| ID | Task | Done when |
|----|------|-----------|
| C1 | **AI Chat route** — Add `src/app/ai-chat/page.tsx` (or similar) rendering [`AIChatClient`](../../line-mini-app/src/components/miniapp/AIChatClient.tsx); link from Home/BottomNav if desired. | Opens inside LIFF |
| C2 | **Registration / profile parity** — Compare [`liff-app` RegisterPage](../../liff-app/src/pages/RegisterPage.tsx); implement or defer explicitly. | Decision recorded; minimal flow if required |

### C2 — Decision

**Deferred:** Full registration form parity with `liff-app` RegisterPage is not implemented in this pass. The mini app uses [`api/member.php`](../../api/member.php) `check` / `get_card` on profile; users who must register can complete flows in legacy LIFF or staff can onboard in CRM until a dedicated `/register` route is prioritized.

---

## Phase D — Extended services (after B stable)

| ID | Task | Done when |
|----|------|-----------|
| D1 | **Port remaining LIFF routes** — Notifications, wishlist, appointments, video, health profile (from `liff-app` App routes) as product priority dictates. | Routes exist + API wired or “not in scope” |
| D2 | **Deep links** — Flex / LINE messages URI to `/shop`, `/order/:id`, etc. | Test from real message |

**Status:** Not started in this checklist pass — wait until B is stable in production LIFF testing.

---

## Phase E — Quality gates (per task / release)

| ID | Task |
|----|------|
| E1 | Spec compliance: task matches acceptance in table above; no extra scope |
| E2 | Code quality: types, errors, no duplicate API wrappers without reason |
| E3 | Final pass before merge: build passes, manual LIFF check on device |

---

## Notes

- **Odoo / B2B** is intentionally out of scope for this mini-app; CRM data = MySQL via PHP.
- **Slip upload** proxy: [`line-mini-app/src/app/api/checkout-slip/route.ts`](../../line-mini-app/src/app/api/checkout-slip/route.ts).
