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
      '. Do NOT push. Do NOT merge into any other branch. Report the resulting commit SHA, `git log --oneline -1`, and a concise paragraph suitable for inclusion in a PR description.'
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

let streamSpecs = args && args.streams ? args.streams : null

if (!streamSpecs) {
  const priorityHint =
    (args && args.priorityHint) ||
    'Get to a demoable, internally-usable prototype as fast as possible. Odoo/WMS/POS/accounting (plan Stream B: phases 8-9 and everything depending on them) are explicitly OUT OF SCOPE for now — do not schedule them. Prefer Stream A (2->3->4->5->6->7) work, sized so each stream is realistically finishable and gate-passable in one round.'

  const planPrompt =
    'You are acting per your agent definition (mig-autopilot). Read docs/plans/2026-07-12-nextjs-full-migration-plan.md in full, docs/agents/nextjs-migration-team.md in full, and `git log --oneline -30` to see what is actually merged (not what any stale summary claims). Priority hint from the user: ' +
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

  const plan = await agent(planPrompt, { agentType: 'mig-autopilot', model: 'sonnet', schema: planSchema, label: 'plan', phase: 'Plan' })
  streamSpecs = plan.streams
}

log('Autopilot running ' + streamSpecs.length + ' parallel streams: ' + streamSpecs.map(function (s) { return s.id }).join(', '))

phase('Streams')
const results = await parallel(streamSpecs.map(function (s) { return function () { return runStream(s) } }))

return { results: results.filter(Boolean) }
