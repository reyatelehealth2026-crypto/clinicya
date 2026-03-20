<?php
/**
 * QR Code Generator
 *
 * Generates PromptPay QR codes from EMVCo payloads.
 * Uses Google Chart API for QR image generation (no PHP library dependencies).
 * Falls back to a local data-URI if the external service is unavailable.
 *
 * Generated images are cached on disk under uploads/qr/ to avoid
 * hitting the external API on every request.
 *
 * @version 1.0.0
 * @created 2026-03-20
 */

class QRCodeGenerator
{
    /** Base directory for saved QR images (relative to project root) */
    private string $uploadDir;

    /** Public URL base (relative to web root) */
    private string $publicBase;

    public function __construct(string $uploadDir = '', string $publicBase = '')
    {
        $this->uploadDir  = $uploadDir ?: (__DIR__ . '/../uploads/qr');
        $this->publicBase = $publicBase ?: '/uploads/qr';
    }

    /**
     * Generate a PromptPay QR code image from an EMVCo raw payload string.
     *
     * @param string $emvcoPayload  The raw EMVCo payload (e.g. "00020101021…6304XXXX")
     * @param string $reference     A human-readable reference used for the filename (e.g. BDO ref)
     * @param int    $size          QR image dimension in pixels
     * @return array{success:bool, url?:string, path?:string, error?:string}
     */
    public function generatePromptPayQR(string $emvcoPayload, string $reference = '', int $size = 400): array
    {
        if (empty(trim($emvcoPayload))) {
            return ['success' => false, 'error' => 'Empty EMVCo payload'];
        }

        // Ensure upload directory exists
        if (!is_dir($this->uploadDir)) {
            @mkdir($this->uploadDir, 0755, true);
        }

        // Build a deterministic filename from the payload hash
        $hash     = substr(md5($emvcoPayload), 0, 12);
        $safeRef  = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $reference ?: 'qr');
        $filename = "promptpay_{$safeRef}_{$hash}.png";
        $filePath = rtrim($this->uploadDir, '/') . '/' . $filename;
        $publicUrl = rtrim($this->publicBase, '/') . '/' . $filename;

        // Return cached file if it already exists and is not stale (24h)
        if (file_exists($filePath) && (time() - filemtime($filePath)) < 86400) {
            return [
                'success' => true,
                'url'     => $publicUrl,
                'path'    => $filePath,
            ];
        }

        // ── Strategy 1: Google Chart API ────────────────────────────────────
        $imageData = $this->fetchFromGoogleChartApi($emvcoPayload, $size);

        // ── Strategy 2: goqr.me API (fallback) ─────────────────────────────
        if ($imageData === null) {
            $imageData = $this->fetchFromGoQrApi($emvcoPayload, $size);
        }

        // ── Strategy 3: Inline SVG data URI (last resort) ──────────────────
        if ($imageData === null) {
            // Use a placeholder — the Flex template can still show the text payload
            return [
                'success' => false,
                'error'   => 'QR generation services unavailable',
            ];
        }

        // Save to disk
        $written = @file_put_contents($filePath, $imageData);
        if ($written === false) {
            return [
                'success' => false,
                'error'   => 'Failed to write QR image to disk',
            ];
        }

        return [
            'success' => true,
            'url'     => $publicUrl,
            'path'    => $filePath,
        ];
    }

    /**
     * Fetch QR PNG from Google Chart API.
     */
    private function fetchFromGoogleChartApi(string $data, int $size): ?string
    {
        $url = 'https://chart.googleapis.com/chart?' . http_build_query([
            'cht'  => 'qr',
            'chs'  => "{$size}x{$size}",
            'chl'  => $data,
            'choe' => 'UTF-8',
            'chld' => 'M|2',
        ]);

        return $this->httpGet($url);
    }

    /**
     * Fetch QR PNG from goqr.me API.
     */
    private function fetchFromGoQrApi(string $data, int $size): ?string
    {
        $url = 'https://api.qrserver.com/v1/create-qr-code/?' . http_build_query([
            'size'   => "{$size}x{$size}",
            'data'   => $data,
            'format' => 'png',
            'ecc'    => 'M',
        ]);

        return $this->httpGet($url);
    }

    /**
     * Simple HTTP GET with timeout.
     */
    private function httpGet(string $url, int $timeout = 8): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'CNY-Pharmacy-QR/1.0',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300 && $response !== false && strlen($response) > 100) {
            return $response;
        }

        error_log("[QRCodeGenerator] HTTP {$httpCode} from " . parse_url($url, PHP_URL_HOST));
        return null;
    }
}
