export const meta = {
  name: 'migration-autopilot',
  description: 'Program-level parallel scheduler for the PHP -> Next.js migration: runs N file-disjoint phase/batch streams at once, each its own git worktree with a full brief -> build -> verify -> fix -> commit cycle, returning results for the coordinator to merge/push/PR.',
  phases: [
    { title: 'Plan', detail: 'mig-autopilot proposes streams if none were supplied' },
    { title: 'Streams', detail: 'each stream: worktree setup -> brief -> parallel builders -> infra -> verify -> fix -> commit' },
  ],
}

const WT_ROOT = '/tmp/claude-0/-home-user-clinicya/c8338dc7-e596-54f1-b5cb-64bae9a6109a/scratchpad/worktrees'
const REPO_ROOT = '/home/user/clinicya'

// HISTORICAL RECORD ONLY — this was Round 1's stream plan (PR #64). It is NOT a live
// fallback: a prior "fix" silently substituted this whenever args.streams failed to
// parse, which meant a later round (args.streams genuinely non-empty, but `args` itself
// arrived stringified — see the defensive JSON.parse below) silently re-ran this
// already-merged Round 1 work instead of erroring, burning a full agent fleet for zero
// new output and no visible failure signal. Never resurrect a silent default-streams
// fallback in this script — a coordinator script must fail LOUDLY when it can't
// determine what to run, never quietly substitute unrelated old work. Kept here only so
// the shape/style of a real stream spec is easy to find; not read by any code below.
const ROUND_1_STREAMS_FOR_REFERENCE_ONLY = [
  {
    id: 'inbox-reads',
    worktreeName: 'wt-inbox-reads',
    branch: 'claude/phase4-batch1-inbox-reads',
    builders: [
      { key: 'conversationList', agentType: 'mig-api' },
      { key: 'messageThread', agentType: 'mig-ui' },
    ],
    infraAgentType: 'mig-infra',
    briefContext:
      "Phase 4 batch 1 of docs/plans/2026-07-12-nextjs-full-migration-plan.md Phase 4 (Inbox v2) — READS ONLY this round. Do NOT port send_message, tag/note, dispense, or any of the ~19 AI-copilot actions — those are deferred to later batches per the plan's 'actions ทีละ ~5' guidance. Read in full before writing anything: inbox-v2.php (14,518 LOC, page + list markup + filter UI — study the read/render path only), api/inbox-v2.php (3,560 LOC AJAX action switch — study only getConversations/getMessages and other read actions), classes/InboxService.php (the getConversationsDelta($lineAccountId, $since, $cursor, $limit, $search, $filters) method). CLAUDE.md documents the binding contract: returns {conversations, next_cursor, has_more}; cursor = last conversation's last_message_at (DESC sort, keyset pagination); page server-renders 200 rows then a ConversationLoader IIFE auto-loads more via GET api/inbox-v2.php?action=getConversations&cursor=...&limit=200; API caps limit at 500, defaults to 200 — do not lower these. Deliverables: (1) conversationList builder — a Route Handler under apps/admin/src/app/api/inbox/** replicating the cursor contract exactly, reading via packages/db Kysely against the tenant DB, plus the conversation-list Server Component (search, filter chips, unread counts, tag chips); (2) messageThread builder — Server Components under apps/admin/src/app/(tenant)/inbox/** rendering every message type in use today (text/image/sticker/flex/file/video/audio), rendering flex messages to structurally match classes/FlexTemplates output (byte-parity is out of scope here — that lives in the sibling line-package stream). Explicitly OUT of scope: any mutation/action, AI copilot sidebar, websocket/realtime wiring (sibling 'realtime-worker' stream owns apps/worker this round — do not touch it). Do not modify inbox-v2.php or api/inbox-v2.php (PHP stays unchanged). Do not touch apps/admin/src/app/api/miniapp/** (owned by the sibling 'checkout-completion' stream this round).",
    coordinationNotes:
      "conversationList builder (mig-api) owns the Route Handler + cursor-pagination data layer under apps/admin/src/app/api/inbox/**; messageThread builder (mig-ui) owns the Server Components/UI under apps/admin/src/app/(tenant)/inbox/**. Treat the API route's response shape as an interface contract defined in the brief — do not co-edit each other's files.",
    highRisk: false,
    commitSummary:
      'Phase 4 batch 1: inbox-v2 reads-only port — cursor-paginated conversation list Route Handler + message thread rendering for all message types, no mutations/actions this round.',
  },
  {
    id: 'line-package',
    worktreeName: 'wt-line-package',
    branch: 'claude/phase6-prep-line-package',
    builders: [
      { key: 'lineApi', agentType: 'mig-line' },
      { key: 'flexTemplates', agentType: 'mig-line' },
    ],
    infraAgentType: 'mig-infra',
    briefContext:
      "Foundation stream ahead of Phase 6, unblocking two already-merged TODOs: apps/admin/src/app/(tenant)/loyalty-members/_lib/pointsClaim.ts and apps/admin/src/app/(tenant)/line-groups/actions.ts, both of which have doc comments stating they need LINE push/notify but 'packages/line doesn't exist yet'. lineApi builder: port classes/LineAPI.php's validateSignature (HMAC-SHA256 webhook signature check) and the smart sendMessage() (checks reply_token first to avoid push-message quota, falls back to pushMessage() only when reply_token is unavailable — this exact preference order is documented in CLAUDE.md and must be preserved). Explicitly OUT of scope: rich menu management, multicast/narrowcast, LIFF-specific helpers — defer to Phase 6 proper. flexTemplates builder: port classes/FlexTemplates.php's medicineLabel(), medicineLabelsCarousel() (auto-used when count(items) > 1 per CLAUDE.md), and toMessage(), with golden JSON fixtures captured from the real PHP class's actual output (byte-diff harness) — this becomes the dependency the future Phase 5 dispense port imports rather than re-implementing Flex JSON, so fixture fidelity matters more than coverage breadth. Deliverables: packages/line/src/api.ts (lineApi builder), packages/line/src/flex.ts + packages/line/src/__fixtures__/*.json (flexTemplates builder), both exported from packages/line/src/index.ts using the append-only barrel convention already established in packages/contracts. No live LINE API calls needed this round — signature verification and Flex JSON structure are pure-function testable.",
    coordinationNotes:
      "lineApi builder owns packages/line/src/api.ts; flexTemplates builder owns packages/line/src/flex.ts + __fixtures__/**. Both append their own export line to packages/line/src/index.ts — do not rewrite each other's lines, only append.",
    highRisk: false,
    commitSummary:
      'Phase 6 prep: packages/line foundation — LineAPI signature-verify + smart sendMessage, FlexTemplates port with golden JSON fixtures, unblocking loyalty-members and line-groups TODOs.',
  },
  {
    id: 'checkout-completion',
    worktreeName: 'wt-checkout',
    branch: 'claude/phase3-checkout',
    builders: [
      { key: 'cartAndPricing', agentType: 'mig-api' },
      { key: 'orderCreation', agentType: 'mig-api' },
    ],
    infraAgentType: 'mig-infra',
    briefContext:
      "Completes docs/plans/2026-07-12-nextjs-full-migration-plan.md Phase 3 — the remaining, NOT-yet-ported piece of api/checkout.php (2,794 LOC). Phase 3 batch 1 already shipped apps/admin/src/app/api/miniapp/shop-products/** covering products and product_detail actions — DO NOT re-port those; read that existing route FIRST and match its established house style (apps/admin/src/lib/miniapp/{cors,tenant}.ts helpers, packages/contracts zod+fixtures pattern), documented as binding in docs/runbooks/phase3-batch1-miniapp-api-parity.md and phase3-batch2-miniapp-api-parity.md. This round ports: cart pricing/calculation actions (cartAndPricing builder) and create_order + the payment/slip upload flow (orderCreation builder) from api/checkout.php — read the full 2,794-line file before writing anything. Must preserve byte-for-byte: the guarded stock decrement UPDATE business_items SET stock = stock - qty WHERE id=? AND stock >= qty (race-safe — never weaken the WHERE guard), and the pending-transaction seed shape for transfer/later payment methods (delivery_info JSON referencing dispense_id where applicable). Tenant resolution via routeByLineAccount() from packages/tenant, same un-gated public mini-app API model as shop-products (trust-on-input identity: line_user_id + line_account_id, NOT behind the admin session gate, permissive CORS via the shared miniapp/{cors,tenant}.ts helpers). Deliverables: apps/admin/src/app/api/miniapp/checkout/** (cartAndPricing: pricing/cart sub-paths; orderCreation: a distinct .../checkout/order/** sub-path, to avoid file collision) plus packages/contracts zod schemas + golden fixtures for every new endpoint. HIGH RISK — mig-verify PASS authorizes merge of code+tests only; production traffic flip needs a follow-up mig-orchestrator co-sign (routes.json stays default-php regardless of this round's outcome).",
    coordinationNotes:
      'cartAndPricing builder owns apps/admin/src/app/api/miniapp/checkout/(cart + pricing sub-paths, not /order); orderCreation builder owns apps/admin/src/app/api/miniapp/checkout/order/** (create_order, slip upload, payment). Keep these as physically separate route directories. Both import (never duplicate) the existing shop-products tenant/cors helpers.',
    highRisk: true,
    commitSummary:
      'Phase 3 completion: checkout.php cart pricing + create_order + payment/slip flow ported to apps/admin/src/app/api/miniapp/checkout/** with zod contracts and golden fixtures. HIGH RISK — merge-only, no traffic flip.',
  },
  {
    id: 'realtime-worker',
    worktreeName: 'wt-realtime-worker',
    branch: 'claude/phase4-realtime-worker',
    builders: [{ key: 'wsInboxRelay', agentType: 'mig-worker' }],
    infraAgentType: 'mig-infra',
    briefContext:
      "Extends the already-merged apps/worker scaffold (BullMQ job registry, forEachActiveTenant()/withTenant() tenant fan-out, worker-heartbeat proof-of-life job, DLQ, plain-HTTP health endpoint — read them first to match conventions) with real functionality: port websocket-server.js's (repo root) Redis inbox_updates pub/sub relay — the exact channel PHP's classes/WebSocketNotifier already publishes to during the coexistence period (do not touch that PHP class; it keeps publishing unchanged, this is additive). Read websocket-server.js in full to find the subscribe/relay logic for the inbox_updates channel and its Socket.io room/broadcast semantics. Deliverable: a new module in apps/worker (e.g. apps/worker/src/realtime/inboxRelay.ts) that subscribes to the same Redis channel and re-emits to connected Socket.io clients, matching the existing message envelope shape byte-for-byte (a future round wires a socket client into the inbox-reads UI — not this round). Explicitly OUT of scope: the WebRTC/video signaling namespace in websocket-server.js, and consolidating the other 2 legacy websocket implementations. Live-test with a smoke test in the spirit of infra/e2e/worker-smoke.mjs: publish a synthetic inbox_updates message to Redis, assert a connected test Socket.io client receives it relayed correctly.",
    highRisk: false,
    commitSummary:
      "Phase 4 realtime: port websocket-server.js's Redis inbox_updates relay into apps/worker as a new Socket.io relay module, live-smoke-tested; WebRTC signaling and full websocket consolidation deferred.",
  },
]

const BUILDER_BRIEF_SHAPE = {
  type: 'object',
  required: ['scope', 'deliverables', 'acceptance', 'boundaries'],
  properties: {
    scope: { type: 'string' },
    deliverables: { type: 'array', items: { type: 'string' } },
    acceptance: { type: 'array', items: { type: 'string' } },
    boundaries: { type: 'string' },
  },
}

const VERDICT_SCHEMA = {
  type: 'object',
  required: ['pass', 'summary', 'failures'],
  properties: {
    pass: { type: 'boolean' },
    summary: { type: 'string' },
    failures: {
      type: 'array',
      items: {
        type: 'object',
        required: ['owner', 'class', 'detail'],
        properties: {
          owner: { type: 'string' },
          class: { type: 'string', enum: ['contract-drift', 'parity-miss', 'missing-evidence', 'rollback-untested', 'build-broken', 'scope-violation'] },
          detail: { type: 'string' },
        },
      },
    },
  },
}

const GUARDRAILS =
  '\nGLOBAL GUARDRAILS:\n' +
  '- Do NOT git commit/push/checkout except where explicitly instructed (the commit step below).\n' +
  "- Run `pnpm install` in your worktree yourself if node_modules is missing there.\n" +
  '- Docker available; clean up any containers you start (docker rm -f); use a container-name prefix unique to your stream id to avoid colliding with sibling streams that may be running concurrently in other worktrees.\n' +
  '- No existing PHP file may be modified. Read the PHP source you are porting/matching in full before writing code — do not guess from memory or file size alone.\n' +
  '- Secrets discipline (GitGuardian scans this repo): no literal credentials/passwords/tokens in tracked files.\n' +
  '- Bilingual Thai/English user-facing text (Thai primary, matching the PHP pages/messages). Asia/Bangkok timezone, Buddhist-era dates via existing helpers where present.\n' +
  '- Reuse established conventions already in the repo (packages/db typed Kysely, packages/auth session/tenant helpers, apps/admin/src/lib/miniapp/{cors,tenant}.ts, packages/contracts zod+fixtures pattern, infra/e2e/lib/harness-common.mjs) — do not fork a new convention where an existing one already fits.\n' +
  '- Final message: concise build report — deliverables (paths), evidence (commands run + results), deferred items.'

function briefSchemaForStream(keys) {
  const props = {}
  for (const k of keys) props[k] = BUILDER_BRIEF_SHAPE
  return { type: 'object', required: keys, properties: props }
}

function buildPrompt(wtPath, branch, brief, extra) {
  return (
    'You are acting per your agent definition. Your repo root for this task is the git worktree at ' +
    wtPath +
    ' (branch ' +
    branch +
    ') — cd there first and do ALL work there (reads, edits, docker, pnpm, git status/diff). Do NOT touch ' +
    REPO_ROOT +
    ' directly (sibling parallel streams may be using other worktrees of the same repo concurrently; touching the shared checkout risks corrupting their work).\n\n' +
    'BRIEF:\nscope: ' +
    brief.scope +
    '\ndeliverables:\n' +
    brief.deliverables.map(function (d) { return '- ' + d }).join('\n') +
    '\nacceptance criteria (mig-verify will execute these):\n' +
    brief.acceptance.map(function (a) { return '- ' + a }).join('\n') +
    '\nallowed paths: ' +
    brief.boundaries +
    '\n' +
    (extra || '') +
    GUARDRAILS
  )
}

async function runStream(spec) {
  const wtPath = WT_ROOT + '/' + spec.worktreeName
  const builderKeys = spec.builders.map(function (b) { return b.key })
  const allKeys = spec.infraAgentType ? builderKeys.concat(['infra']) : builderKeys
  const schema = briefSchemaForStream(allKeys)

  const briefPrompt =
    'You are acting per your agent definition (mig-orchestrator), scoping ONE stream of a larger parallel autopilot round. FIRST, set up your isolated worktree: cd to ' +
    REPO_ROOT +
    ' and run: git worktree add -b ' +
    spec.branch +
    ' ' +
    wtPath +
    ' origin/main (if the worktree/branch already exist from a prior attempt, cd straight into it instead of recreating). All work for this entire stream — yours and every builder\'s — happens inside ' +
    wtPath +
    ' from here on; never touch ' +
    REPO_ROOT +
    ' directly.\n\n' +
    spec.briefContext +
    '\n\nProduce one structured brief per required key: ' +
    allKeys.join(', ') +
    ('. Each brief: scope (1 paragraph), file-level deliverables, acceptance criteria mig-verify can execute in this container, and allowed-path boundaries precise enough that the ' +
      builderKeys.length +
      ' builder(s)' +
      (spec.infraAgentType ? ' + infra agent' : '') +
      ' never edit the same file.')

  const briefs = await agent(briefPrompt, {
    agentType: 'mig-orchestrator',
    model: 'sonnet',
    schema: schema,
    label: 'brief:' + spec.id,
    phase: 'Streams',
  })

  log('Stream ' + spec.id + ': briefs ready — building ' + builderKeys.length + (spec.infraAgentType ? '+infra' : '') + ' agents in parallel in ' + wtPath)

  const builderCalls = spec.builders.map(function (b) {
    return function () {
      return agent(buildPrompt(wtPath, spec.branch, briefs[b.key], spec.coordinationNotes), {
        agentType: b.agentType,
        model: 'sonnet',
        label: 'build:' + spec.id + ':' + b.key,
        phase: 'Streams',
      })
    }
  })
  if (spec.infraAgentType) {
    builderCalls.push(function () {
      return agent(buildPrompt(wtPath, spec.branch, briefs.infra, spec.coordinationNotes), {
        agentType: spec.infraAgentType,
        model: 'sonnet',
        label: 'build:' + spec.id + ':infra',
        phase: 'Streams',
      })
    })
  }

  const reports = await parallel(builderCalls)

  function verifyPrompt(round, extra) {
    let reportsText = ''
    for (let i = 0; i < spec.builders.length; i++) {
      reportsText += '\n--- ' + spec.builders[i].key + ' ---\n' + reports[i]
    }
    if (spec.infraAgentType) reportsText += '\n--- infra ---\n' + reports[reports.length - 1]
    return (
      "You are acting per your agent definition — run the SINGLE gate on stream '" +
      spec.id +
      "'. Your repo root is the worktree at " +
      wtPath +
      ' (branch ' +
      spec.branch +
      '). ' +
      (extra || '') +
      '\n\nBriefs: ' +
      JSON.stringify(briefs) +
      '\n\nBuild reports:' +
      reportsText +
      '\n\nGate checklist (EXECUTE everything in the worktree, trust nothing):\n' +
      '1. Full workspace build/test/lint (turbo run build test lint, or the relevant subset) inside the worktree — green.\n' +
      '2. If this stream touches a live-testable surface (a page, an API endpoint, a worker job), RUN the relevant existing infra/e2e/** harness (or a minimal extension of it matching established patterns) LIVE — do not just trust the build report.\n' +
      '3. Read the diffs against the real PHP/JS source being ported or matched — contract fidelity, quirks preserved not silently fixed.\n' +
      '4. Scope + secrets: git status shows only allowed paths per each builder\'s brief; no literal credentials; no leftover docker containers/processes after any harness run.\n' +
      '5. Every acceptance criterion from every brief in this stream, each with evidence.\n' +
      (spec.highRisk
        ? '6. This is a HIGH-RISK stream per the team doc\'s co-sign list — your PASS authorizes merge of code+tests ONLY, not a production traffic flip; say so explicitly in your summary.\n'
        : '') +
      'Round ' +
      round +
      ' of max 2. pass=true only if all hold. Classify failures with owner (one of: ' +
      allKeys.join(' | ') +
      ') + class + a one-shot-fixable diagnosis.'
    )
  }

  let verdict = await agent(verifyPrompt(1, ''), {
    agentType: 'mig-verify',
    model: 'sonnet',
    schema: VERDICT_SCHEMA,
    label: 'gate:' + spec.id,
    phase: 'Streams',
  })

  if (!verdict.pass && verdict.failures && verdict.failures.length > 0) {
    log('Stream ' + spec.id + ' gate FAIL round 1: ' + verdict.failures.length + ' failure(s) — one fix round')
    const ownerToAgentType = {}
    for (let i = 0; i < spec.builders.length; i++) ownerToAgentType[spec.builders[i].key] = spec.builders[i].agentType
    if (spec.infraAgentType) ownerToAgentType.infra = spec.infraAgentType

    const byOwner = {}
    for (const f of verdict.failures) {
      if (!byOwner[f.owner]) byOwner[f.owner] = []
      byOwner[f.owner].push(f)
    }

    await parallel(
      Object.entries(byOwner).map(function (entry) {
        const owner = entry[0]
        const fails = entry[1]
        return function () {
          const failText = fails.map(function (f) { return '- [' + f.class + '] ' + f.detail }).join('\n')
          const prompt =
            'You are acting per your agent definition. mig-verify FAILED your work (you are "' +
            owner +
            '" in stream \'' +
            spec.id +
            "') — fix ONLY these diagnosed failures in the worktree at " +
            wtPath +
            ' (branch ' +
            spec.branch +
            '). Do not git commit/push.\n' +
            failText +
            '\nRe-run the relevant evidence yourself before finishing. Final message: what changed + evidence output.' +
            GUARDRAILS
          return agent(prompt, { agentType: ownerToAgentType[owner] || 'mig-ui', model: 'sonnet', label: 'fix:' + spec.id + ':' + owner, phase: 'Streams' })
        }
      })
    )

    verdict = await agent(verifyPrompt(2, 'This is the RE-VERIFY after one fix round; a second FAIL escalates to the coordinator (do not attempt a third fix round).'), {
      agentType: 'mig-verify',
      model: 'sonnet',
      schema: VERDICT_SCHEMA,
      label: 'regate:' + spec.id,
      phase: 'Streams',
    })
  }

  let commitInfo = null
  if (verdict.pass) {
    const commitPrompt =
      'You are acting per your agent definition (mig-infra). Your gate PASSED for stream \'' +
      spec.id +
      "'. In the worktree at " +
      wtPath +
      ' (branch ' +
      spec.branch +
      '): run `git status` to review the full diff, `git add -A`, then `git commit` with a Conventional Commits message (feat(migration): ...) summarizing: ' +
      (spec.commitSummary || spec.id) +
      '. Then IMMEDIATELY run `git push -u origin ' +
      spec.branch +
      "` (retry up to 3 times on network failure with a short backoff) — this branch is a scratch worktree on ephemeral disk that can be reclaimed at any time, so the commit is not safe until it exists on GitHub; do not skip this step or leave it for later. Do NOT merge into any other branch, and do NOT open a pull request (the coordinator does that once, after merging all of this round's streams together). Report the resulting commit SHA, `git log --oneline -1`, confirmation the push succeeded (paste the push command's output), and a concise paragraph suitable for inclusion in a PR description."
    commitInfo = await agent(commitPrompt, { agentType: 'mig-infra', model: 'sonnet', label: 'commit:' + spec.id, phase: 'Streams' })
  }

  return {
    id: spec.id,
    worktree: wtPath,
    branch: spec.branch,
    highRisk: !!spec.highRisk,
    verdict: verdict,
    commitInfo: commitInfo,
  }
}

phase('Plan')

// The Workflow tool's `args` param has been observed to arrive stringified (a JSON-encoded
// string) instead of as a live object — defend against that ONE case, but do not paper over
// anything else: if after this, streams still can't be found, THROW with full diagnostics
// rather than silently substituting unrelated work. A coordinator script that can run
// different work every round must never guess at "probably fine" defaults when its actual
// input didn't arrive — that failure mode is worse than crashing (see the block comment
// above ROUND_1_STREAMS_FOR_REFERENCE_ONLY for exactly how this went wrong once already).
let resolvedArgs = args
if (typeof resolvedArgs === 'string') {
  try {
    resolvedArgs = JSON.parse(resolvedArgs)
    log('args arrived as a JSON string, not an object — parsed it defensively.')
  } catch (e) {
    throw new Error(
      'migration-autopilot: args arrived as a string and failed JSON.parse (' +
        e.message +
        '). First 300 chars: ' +
        String(args).slice(0, 300)
    )
  }
}

let streamSpecs = null
if (resolvedArgs && resolvedArgs.streams !== undefined) {
  if (Array.isArray(resolvedArgs.streams) && resolvedArgs.streams.length > 0) {
    streamSpecs = resolvedArgs.streams
  } else if (Array.isArray(resolvedArgs.streams) && resolvedArgs.streams.length === 0) {
    // Explicit empty array = "let the planner decide" — documented, intentional, not a bug.
    log('args.streams was an explicit empty array — falling through to the mig-orchestrator planning agent.')
  } else {
    // Present but not an array at all (string/object/number/etc) — unambiguously a caller
    // bug (e.g. streams double-JSON-encoded, or nested one level too deep). Never guess
    // which one it is; surface exactly what arrived so the caller can fix the real problem.
    throw new Error(
      'migration-autopilot: args.streams was present but is not an array. typeof=' +
        typeof resolvedArgs.streams +
        ' value(first 500 chars)=' +
        JSON.stringify(resolvedArgs.streams).slice(0, 500)
    )
  }
}

if (!streamSpecs) {
  const priorityHint =
    (resolvedArgs && resolvedArgs.priorityHint) ||
    'Get to a demoable, internally-usable prototype as fast as possible. Odoo/WMS/POS/accounting (plan Stream B: phases 8-9 and everything depending on them) are explicitly OUT OF SCOPE for now — do not schedule them. Prefer Stream A (2->3->4->5->6->7) work, sized so each stream is realistically finishable and gate-passable in one round.'

  const planPrompt =
    'You are acting as a program-level scheduler for the PHP -> Next.js migration (mig-autopilot role). Read docs/plans/2026-07-12-nextjs-full-migration-plan.md in full, docs/agents/nextjs-migration-team.md in full, and `git log --oneline -30` to see what is actually merged (not what any stale summary claims). Priority hint from the user: ' +
    priorityHint +
    "\n\nPropose 2-5 parallel, file-disjoint streams for THIS ROUND ONLY. For each stream, decide: a short id (kebab-case), a worktreeName (wt-<id>), a branch (claude/<id>), 1-3 builder agent assignments (agentType must be one of: mig-ui, mig-api, mig-line, mig-ai, mig-worker, mig-kernel, mig-infra — pick per the team doc's phase mapping), whether an infra/parity builder is also needed (almost always yes for anything live-testable), a detailed briefContext (name the exact PHP files + approximate LOC to read, state explicitly what IS and is NOT in scope this round, flag any known coupling hazard with sibling streams or future phases), optional coordinationNotes (only if 2+ builders in this stream could otherwise collide on file boundaries), whether the stream is highRisk (true only for surfaces matching the team doc's explicit list: VPS cutover, checkout endpoint, dispense/document-numbering, LINE webhook, AI SSE), and a one-paragraph commitSummary."

  const planSchema = {
    type: 'object',
    required: ['streams'],
    properties: {
      streams: {
        type: 'array',
        minItems: 1,
        maxItems: 6,
        items: {
          type: 'object',
          required: ['id', 'worktreeName', 'branch', 'builders', 'briefContext', 'commitSummary'],
          properties: {
            id: { type: 'string' },
            worktreeName: { type: 'string' },
            branch: { type: 'string' },
            builders: {
              type: 'array',
              minItems: 1,
              maxItems: 3,
              items: {
                type: 'object',
                required: ['key', 'agentType'],
                properties: { key: { type: 'string' }, agentType: { type: 'string' } },
              },
            },
            infraAgentType: { type: 'string' },
            briefContext: { type: 'string' },
            coordinationNotes: { type: 'string' },
            highRisk: { type: 'boolean' },
            commitSummary: { type: 'string' },
          },
        },
      },
    },
  }

  // 'mig-autopilot' is defined in .claude/agents/mig-autopilot.md but custom agent
  // definitions created mid-session aren't picked up by the Workflow agent registry
  // (snapshotted at session start) — fall back to the registered 'mig-orchestrator'
  // type, briefed with the same program-level-scheduler framing above, until a fresh
  // session picks up mig-autopilot natively.
  const plan = await agent(planPrompt, { agentType: 'mig-orchestrator', model: 'sonnet', schema: planSchema, label: 'plan', phase: 'Plan' })
  streamSpecs = plan.streams
}

log('Autopilot running ' + streamSpecs.length + ' parallel streams: ' + streamSpecs.map(function (s) { return s.id }).join(', '))

phase('Streams')
const results = await parallel(streamSpecs.map(function (s) { return function () { return runStream(s) } }))

return { results: results.filter(Boolean) }
