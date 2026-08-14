// products-errors.ts — the create_broadcast validation strings, split out of
// products-actions.ts.
//
// This constant lived in products-actions.ts until it broke `next build`:
// that file carries the "use server" directive, and Next.js requires such a
// module to export ONLY async functions ("A 'use server' file can only export
// async functions, found object"). Exported `interface`/`type` declarations
// are fine there — TypeScript erases them before Next sees the module — but a
// runtime value like this object is not, so it lives here instead. Anything
// else that needs to be shared out of a "use server" file and is not an async
// function belongs in a plain module like this one too.

/** Exact Thai validation error strings, products.php:85-90 — verbatim, not paraphrased. */
export const CREATE_BROADCAST_ERRORS = {
  emptyName: 'กรุณากรอกชื่อ Broadcast',
  noProducts: 'กรุณาเลือกสินค้าอย่างน้อย 1 รายการ',
  tooManyProducts: 'เลือกสินค้าได้สูงสุด 10 รายการ',
} as const;
