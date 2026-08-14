// index.ts — append-only barrel (contractNote point 4-style convention, matching
// packages/contracts/src/index.ts). mig-infra owns and pre-wires this file: all three
// filenames (api.ts, flex.ts, auto-reply.ts) are fixed in advance by the Phase 6 task
// split, so this barrel is written before each file exists. lineApi owns src/api.ts,
// flexTemplates owns src/flex.ts, and autoReplyMatcher owns src/auto-reply.ts (Phase 6
// prep) — pre-wired here the same way api.ts/flex.ts were, before auto-reply.ts exists,
// so that builder never has to touch this barrel; future Phase 6 additions append below,
// never reorder, never edit another owner's line.
export * from './api';
export * from './flex';
export * from './auto-reply';
