# LINE Mini App — Human contact vs AI (G6)

**Purpose / วัตถุประสงค์:** One-page definition for routing customers to **pharmacist / staff** vs **AI chat** in LINE OA + LIFF context.

## Routing (recommended)

| Channel | Intent | Behavior |
|--------|--------|----------|
| **Keyword in chat** (e.g. คุยเภสัช, ปรึกษา, ติดต่อคน) | Human | `webhook.php` / bot flow should **prioritize** queue or handoff template; do not start AI session for the same turn. |
| **Default product / shop** | App + automation | Deep links to `/shop`, `/order/:id` (LIFF path URLs). |
| **AI Chat route** (`/ai-chat` in Next mini app) | AI | Uses `ai_settings` per `line_account_id`; medical disclaimers per store policy. |

## Ops checklist

- Rich menu / quick reply: align wording with the table above.
- If **video_call_sessions** or consultation queue is used, webhook should log `line_account_id` for multi-tenant separation.

## Sign-off

Product owner confirms keyword list + business hours; engineering confirms `webhook.php` / `BusinessBot` / AI gateway behavior matches this table.
