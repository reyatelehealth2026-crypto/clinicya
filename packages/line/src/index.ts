// index.ts — append-only barrel (contractNote point 4-style convention, matching
// packages/contracts/src/index.ts). mig-infra owns and pre-wires this file: both filenames
// (api.ts, flex.ts) are fixed in advance by the Phase 6 task split, so this barrel is written
// before either file exists. lineApi owns src/api.ts and flexTemplates owns src/flex.ts;
// future Phase 6 additions append below, never reorder, never edit another owner's line.
export * from './api';
export * from './flex';
