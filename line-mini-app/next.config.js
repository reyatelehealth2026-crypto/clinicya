const path = require('path')

/** @type {import('next').NextConfig} */
const nextConfig = {
  // Static export: ship `out/` to any HTTP host (Apache on shared hosting,
  // CDN, etc.). The 3 former proxy routes (api/checkout, api/checkout-slip,
  // api/miniapp-home) now call PHP directly via NEXT_PUBLIC_PHP_API_BASE_URL.
  output: 'export',
  // Monorepo: parent folder has another package-lock.json; trace this app only
  outputFileTracingRoot: path.join(__dirname),
  // next/image optimizer needs a Node server; for static export we serve
  // remote images as-is from `images.remotePatterns`.
  images: {
    unoptimized: true,
    remotePatterns: [
      {
        protocol: 'https',
        hostname: '**'
      }
    ]
  },
  // Emit /shop/index.html etc. so Apache can serve clean URLs without
  // a server-side rewrite (helpful when mounted at /miniapp/).
  trailingSlash: true
}

module.exports = nextConfig
