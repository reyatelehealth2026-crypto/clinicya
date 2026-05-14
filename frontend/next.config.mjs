import bundleAnalyzer from '@next/bundle-analyzer';
import path from 'node:path';

const withBundleAnalyzer = bundleAnalyzer({
  enabled: process.env.ANALYZE === 'true',
});

/** @type {import('next').NextConfig} */
const nextConfig = {
  // Docker optimization - standalone output
  output: 'standalone',
  typedRoutes: true,
  
  experimental: {
    // Enable optimized package imports
    optimizePackageImports: ['@tanstack/react-query', 'socket.io-client'],
  },
  
  // Image optimization
  images: {
    formats: ['image/webp', 'image/avif'],
    deviceSizes: [640, 750, 828, 1080, 1200, 1920, 2048, 3840],
    imageSizes: [16, 32, 48, 64, 96, 128, 256, 384],
    minimumCacheTTL: 60 * 60 * 24 * 30, // 30 days
    dangerouslyAllowSVG: true,
    contentSecurityPolicy: "default-src 'self'; script-src 'none'; sandbox;",
  },
  
  // Compiler optimizations
  compiler: {
    removeConsole: process.env.NODE_ENV === 'production',
    // Enable SWC minification
    styledComponents: false,
  },
  
  // Bundle optimization
  webpack: (config, { buildId, dev, isServer, defaultLoaders, webpack }) => {
    // Optimize bundle splitting
    if (!dev && !isServer) {
      config.optimization.splitChunks = {
        chunks: 'all',
        cacheGroups: {
          default: false,
          vendors: false,
          // Vendor chunk for stable dependencies
          vendor: {
            name: 'vendor',
            chunks: 'all',
            test: /node_modules/,
            priority: 20,
          },
          // Common chunk for shared code
          common: {
            name: 'common',
            minChunks: 2,
            chunks: 'all',
            priority: 10,
            reuseExistingChunk: true,
            enforce: true,
          },
          // React Query chunk
          reactQuery: {
            name: 'react-query',
            chunks: 'all',
            test: /@tanstack\/react-query/,
            priority: 30,
          },
          // Socket.io chunk
          socketio: {
            name: 'socketio',
            chunks: 'all',
            test: /socket\.io-client/,
            priority: 30,
          },
        },
      };
    }

    // Optimize imports
    config.resolve.alias = {
      ...config.resolve.alias,
      '@': path.resolve(process.cwd(), 'src'),
    };

    return config;
  },
  
  // Security headers
  async headers() {
    return [
      {
        // Apply strict frame-deny everywhere EXCEPT the REYA prototype assets,
        // which the /reya Next.js page legitimately embeds via iframe.
        source: '/((?!reya($|/)).*)',
        headers: [
          {
            key: 'X-Frame-Options',
            value: 'DENY',
          },
          {
            key: 'X-Content-Type-Options',
            value: 'nosniff',
          },
          {
            key: 'Referrer-Policy',
            value: 'strict-origin-when-cross-origin',
          },
          {
            key: 'X-DNS-Prefetch-Control',
            value: 'on',
          },
        ],
      },
      {
        source: '/reya/:path*',
        headers: [
          {
            key: 'X-Frame-Options',
            value: 'SAMEORIGIN',
          },
          {
            key: 'X-Content-Type-Options',
            value: 'nosniff',
          },
          {
            key: 'Referrer-Policy',
            value: 'strict-origin-when-cross-origin',
          },
        ],
      },
      // Cache static assets
      {
        source: '/static/(.*)',
        headers: [
          {
            key: 'Cache-Control',
            value: 'public, max-age=31536000, immutable',
          },
        ],
      },
      // Authenticated API responses should not be cached by browsers or CDNs.
      {
        source: '/api/(.*)',
        headers: [
          {
            key: 'Cache-Control',
            value: 'no-store',
          },
        ],
      },
    ];
  },
  
  // Compression
  compress: true,
  
  // Performance optimizations
  poweredByHeader: false,
  reactStrictMode: true,
  
  // Environment variables
  env: {
    CUSTOM_KEY: process.env.CUSTOM_KEY,
  },
  
  // Redirects for performance
  async redirects() {
    return [
      // Redirect old dashboard URLs
      {
        source: '/old-dashboard',
        destination: '/dashboard',
        permanent: true,
      },
    ];
  },
  
  // Rewrites for API optimization
  async rewrites() {
    return [
      {
        source: '/api/v1/:path*',
        destination: `${process.env.INTERNAL_API_ORIGIN || 'http://backend:4000'}/api/v1/:path*`,
      },
    ];
  },
};

export default withBundleAnalyzer(nextConfig);
