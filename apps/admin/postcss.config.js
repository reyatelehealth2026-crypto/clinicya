/**
 * postcss.config.js — wires Tailwind (v3, matching frontend/'s and
 * line-mini-app's monorepo convention — see tailwind.config.ts's header
 * comment for why v3, not v4, was chosen) + autoprefixer into Next's build
 * pipeline. Next.js auto-detects this file; no next.config.ts change needed.
 */
module.exports = {
  plugins: {
    tailwindcss: {},
    autoprefixer: {},
  },
};
