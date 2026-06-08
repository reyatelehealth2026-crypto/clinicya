# Ontology - Knowledge Graph

Last updated: 2026-06-03
Timezone: Asia/Bangkok

## Entities

### person:user
- type: person
- workspace: C:\Users\Administrator\clinicya
- environment: Windows / PowerShell
- preferences: [direct execution, end-state verification, concise progress updates]
- last_updated: 2026-06-03

### project:clinicya
- type: project
- stack: [PHP 8, MySQL, LINE Official Account, Odoo, AI services, Next.js, Fastify, Prisma]
- domain_context: Thai pharmacy CRM/e-commerce and telepharmacy platform
- timezone: Asia/Bangkok
- language_policy: bilingual Thai/English UI text and DB comments
- status: active
- last_updated: 2026-06-03

### app:line-mini-app
- type: app
- project: clinicya
- framework: Next.js 15
- role: active deployed LIFF client
- paths: [line-mini-app/]
- rule: add new LINE in-app shop features here, not legacy LIFF apps
- last_updated: 2026-06-03

### app:admin-inbox
- type: app
- project: clinicya
- paths: [inbox-v2.php, api/inbox-v2.php]
- role: active CRM HUD inbox and dispense workflow surface
- rule: add new inbox features to inbox-v2.php first
- last_updated: 2026-06-03

### app:legacy-liff-reference
- type: app
- project: clinicya
- paths: [liff-app/, liff/]
- status: read-only reference for routine work
- rule: do not add new shop features here
- last_updated: 2026-06-03

### system:graphify
- type: knowledge_graph
- project: clinicya
- paths: [graphify-out/GRAPH_REPORT.md, graphify-out/wiki/index.md]
- rule: read graphify-out/GRAPH_REPORT.md before source inspection or codebase answers
- last_updated: 2026-06-03

### workflow:ontology-self-evolving-agent
- type: skill
- surfaces: [ONTOLOGY.md, REFLECTIONS.md, PROACTIVE.md]
- role: maintain structured project knowledge, self-reflection, and proactive action queues
- last_updated: 2026-06-03

## Relations

- user -> owns_context_for -> project:clinicya
- project:clinicya -> uses -> app:line-mini-app
- project:clinicya -> uses -> app:admin-inbox
- project:clinicya -> retains_reference -> app:legacy-liff-reference
- project:clinicya -> mapped_by -> system:graphify
- workflow:ontology-self-evolving-agent -> maintains -> ONTOLOGY.md
- workflow:ontology-self-evolving-agent -> maintains -> REFLECTIONS.md
- workflow:ontology-self-evolving-agent -> maintains -> PROACTIVE.md

## Patterns

- Prefer existing project conventions and small reversible diffs.
- Verify changes before claiming completion; report evidence and known gaps.
- Treat `line_account_id` scoping as a core invariant for LINE-related features.
- Treat `Asia/Bangkok` as the default runtime and product timezone.
- For large repo exploration, start with graphify and then narrow to source files.

## Rules

- Do not instantiate PDO directly; use `Database::getInstance()->getConnection()`.
- Do not hardcode AI model names; read tenant settings from `ai_settings`.
- Do not lower inbox conversation pagination limits without checking customer visibility concerns.
- Do not add routine production shop features to `liff/` or `liff-app/`.
- Run `graphify update .` after code changes that materially affect the graph.
