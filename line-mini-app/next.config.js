const path = require('path')

/** @type {import('next').NextConfig} */
const nextConfig = {
  // Monorepo: parent folder has another package-lock.json; trace this app only
  outputFileTracingRoot: path.join(__dirname),
  images: {
    remotePatterns: [
      {
        protocol: 'https',
        hostname: '**'
      }
    ]
  }
}

module.exports = nextConfig
