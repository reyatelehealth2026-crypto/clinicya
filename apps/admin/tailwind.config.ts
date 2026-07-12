import type { Config } from 'tailwindcss';

/**
 * tailwind.config.ts — apps/admin (Phase 2 batch 2, design-token infra).
 *
 * Tailwind major: v3 (matching the monorepo's existing convention —
 * frontend/package.json pins "tailwindcss": "^3.4.1" and
 * line-mini-app/package.json pins "^3.4.17"; both wire it via a classic
 * postcss.config.js `{ tailwindcss: {}, autoprefixer: {} }` plugin object,
 * which is what apps/admin/postcss.config.js mirrors). Tailwind v4's
 * `@tailwindcss/postcss` package was NOT installed — v3's dedicated
 * `tailwindcss` PostCSS plugin package matches the rest of the repo.
 *
 * CRITICAL — color source: every scale below is read verbatim off
 * assets/css/design-tokens.css's real `--color-*` custom-property names
 * (as re-affirmed by assets/css/reya-theme.css's `:root` overlay, loaded
 * second in includes/header.php so its tokens win the cascade — see that
 * file's own header comment). None of this is copied from frontend/'s
 * tailwind.config.ts, whose `primary` scale is a generic placeholder
 * sky-blue (50:#f0f9ff … 500:#0ea5e9 … 900:#0c4a6e) unrelated to the REYA
 * brand — do not resurrect that palette here.
 *
 * `slate` is assembled from two design-tokens.css sources per its own
 * in-file comment: --color-slate-50..400 for the light end, and
 * --color-dark-500..900 for the dark end ("already alias slate-500..900").
 */
const config: Config = {
  content: ['./src/**/*.{ts,tsx}'],
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        primary: {
          50: 'var(--color-primary-50)',
          100: 'var(--color-primary-100)',
          200: 'var(--color-primary-200)',
          300: 'var(--color-primary-300)',
          400: 'var(--color-primary-400)',
          500: 'var(--color-primary-500)',
          600: 'var(--color-primary-600)',
          700: 'var(--color-primary-700)',
          800: 'var(--color-primary-800)',
          900: 'var(--color-primary-900)',
        },
        slate: {
          50: 'var(--color-slate-50)',
          100: 'var(--color-slate-100)',
          200: 'var(--color-slate-200)',
          300: 'var(--color-slate-300)',
          400: 'var(--color-slate-400)',
          500: 'var(--color-dark-500)',
          600: 'var(--color-dark-600)',
          700: 'var(--color-dark-700)',
          800: 'var(--color-dark-800)',
          900: 'var(--color-dark-900)',
        },
        dark: {
          500: 'var(--color-dark-500)',
          600: 'var(--color-dark-600)',
          700: 'var(--color-dark-700)',
          800: 'var(--color-dark-800)',
          900: 'var(--color-dark-900)',
        },
        emerald: {
          50: 'var(--color-emerald-50)',
          100: 'var(--color-emerald-100)',
          200: 'var(--color-emerald-200)',
          300: 'var(--color-emerald-300)',
          400: 'var(--color-emerald-400)',
          500: 'var(--color-emerald-500)',
          600: 'var(--color-emerald-600)',
          700: 'var(--color-emerald-700)',
          800: 'var(--color-emerald-800)',
          900: 'var(--color-emerald-900)',
        },
        amber: {
          50: 'var(--color-amber-50)',
          100: 'var(--color-amber-100)',
          200: 'var(--color-amber-200)',
          300: 'var(--color-amber-300)',
          400: 'var(--color-amber-400)',
          500: 'var(--color-amber-500)',
          600: 'var(--color-amber-600)',
          700: 'var(--color-amber-700)',
          800: 'var(--color-amber-800)',
          900: 'var(--color-amber-900)',
        },
        rose: {
          50: 'var(--color-rose-50)',
          100: 'var(--color-rose-100)',
          200: 'var(--color-rose-200)',
          300: 'var(--color-rose-300)',
          400: 'var(--color-rose-400)',
          500: 'var(--color-rose-500)',
          600: 'var(--color-rose-600)',
          700: 'var(--color-rose-700)',
          800: 'var(--color-rose-800)',
          900: 'var(--color-rose-900)',
        },
      },
      borderRadius: {
        sm: 'var(--radius-sm)',
        md: 'var(--radius-md)',
        lg: 'var(--radius-lg)',
        xl: 'var(--radius-xl)',
        '2xl': 'var(--radius-2xl)',
      },
      fontFamily: {
        sans: 'var(--font-sans)',
        display: 'var(--font-display)',
        mono: 'var(--font-mono)',
      },
      transitionDuration: {
        fast: '150ms',
        base: '250ms',
        slow: '350ms',
      },
    },
  },
  plugins: [],
};

export default config;
