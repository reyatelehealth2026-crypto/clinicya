// index.ts — append-only barrel (contractNote point 4, Phase 3 batch 1).
//
// mig-api-reads owns this scaffold and this file's first 5 export lines
// (envelope + resolve-line-account + points-history + shop-products +
// health-profile). mig-api-writes appends its 3 lines (member, rewards,
// wishlist) at the end and never reorders or edits the block above.
export * from './envelope';
export * from './resolve-line-account';
export * from './points-history';
export * from './shop-products';
export * from './health-profile';

// writes' 3 lines (mig-api, Phase 3 batch 1: member, rewards, wishlist) — appended at the end, never
// reordered, never touching the block above.
export * from './member';
export * from './rewards';
export * from './wishlist';
export * from './appointments';

// mig-api, Phase 3 batch 2: addresses (no PHP source — see addresses.ts's doc comment), consent
// (action=save only), data-rights (all 3 actions), medication-reminders (list/add/delete/mark_taken).
// Appended at the end, never reordered, never touching the lines above.
export * from './addresses';
export * from './consent';
export * from './data-rights';
export * from './medication-reminders';
export * from './checkout-cart';
export * from './checkout-order';
