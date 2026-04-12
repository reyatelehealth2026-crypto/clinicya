# LINE Mini App — Gap closure (G1–G8) — 2026-04-13

| ID | Status | Notes |
|----|--------|--------|
| G1 | Done | `/register` + `RegisterClient` → `api/member.php` `register`; profile CTA + ข้ามไปช้อปก่อน |
| G2 | Done | Shop search/category + `checkout.php` `products` (already); verified in UI |
| G3 | Done | `shop_settings.bank_accounts` JSON + PromptPay on checkout + order detail; COD hides bank block |
| G4 | Done | `LiffMessageHandler` + `BusinessBot` use LIFF path URLs (`/shop`, `/checkout`, `/order/{id}`) |
| G5 | Done | LINE push to customer after `create_order`: COD → `sendOrderConfirmation`; transfer → bank + tracking link |
| G6 | Done | `2026-04-13-line-mini-app-human-vs-ai.md` |
| G7 | Done | Phase D routes: placeholder pages + “not prioritized” copy; product can expand per sprint |
| G8 | Done | `UAT-line-mini-app-b4.md` script for device QA |

Legacy `liff/index.php` + Vite `liff-app` may still use `?page=` query URLs — keep separate LIFF app or migrate endpoints in LINE Developers when cutting over.
