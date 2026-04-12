import type { Config } from 'tailwindcss'

const config: Config = {
  content: [
    './src/app/**/*.{ts,tsx}',
    './src/components/**/*.{ts,tsx}',
    './src/lib/**/*.{ts,tsx}'
  ],
  theme: {
    extend: {
      colors: {
        line: {
          DEFAULT: '#004aad',
          dark: '#003890',
          soft: '#e6eeff',
          muted: '#b3c9f5'
        },
        brand: {
          50: '#eff4ff',
          100: '#dce8ff',
          200: '#bad4ff',
          300: '#85b3ff',
          400: '#4a87ff',
          500: '#004aad',
          600: '#003890',
          700: '#002f78',
          800: '#002563',
          900: '#001e52'
        },
        surface: {
          DEFAULT: '#ffffff',
          secondary: '#f8fafc',
          tertiary: '#f1f5f9'
        }
      },
      boxShadow: {
        soft: '0 2px 16px rgba(15, 23, 42, 0.06)',
        card: '0 4px 24px rgba(15, 23, 42, 0.08)',
        glow: '0 0 20px rgba(0, 74, 173, 0.15)',
        'inner-soft': 'inset 0 2px 4px rgba(15, 23, 42, 0.04)'
      },
      borderRadius: {
        xl: '1rem',
        '2xl': '1.25rem',
        '3xl': '1.5rem',
        '4xl': '2rem'
      },
      fontFamily: {
        sans: ['var(--font-inter)', 'var(--font-noto-sans-thai)', 'system-ui', 'sans-serif']
      },
      keyframes: {
        'fade-in': {
          from: { opacity: '0', transform: 'translateY(8px)' },
          to: { opacity: '1', transform: 'translateY(0)' }
        },
        'slide-up': {
          from: { opacity: '0', transform: 'translateY(16px)' },
          to: { opacity: '1', transform: 'translateY(0)' }
        },
        'scale-in': {
          from: { opacity: '0', transform: 'scale(0.95)' },
          to: { opacity: '1', transform: 'scale(1)' }
        },
        shimmer: {
          '0%': { backgroundPosition: '-200% 0' },
          '100%': { backgroundPosition: '200% 0' }
        },
        'pulse-soft': {
          '0%, 100%': { opacity: '1' },
          '50%': { opacity: '0.6' }
        }
      },
      animation: {
        'fade-in': 'fade-in 0.4s ease-out both',
        'slide-up': 'slide-up 0.5s ease-out both',
        'scale-in': 'scale-in 0.3s ease-out both',
        shimmer: 'shimmer 2s linear infinite',
        'pulse-soft': 'pulse-soft 2s ease-in-out infinite'
      }
    }
  },
  plugins: []
}

export default config
