<?php
/**
 * SlipVerifier — GhostX QR slip-verification client.
 *
 * Verifies a Thai bank-transfer slip by sending the raw QR payload to the
 * GhostX API (https://externalauth.ghostxapi.xyz/qr/scan) and checking the
 * returned transfer against the expected order amount and shop account.
 *
 * The HTTP transport is injectable so the decision logic can be unit-tested
 * without hitting the network.
 *
 * Plain global class (no namespace) — matches the other classes/ services.
 *
 * @spec ghostx-slip-verification
 */
class SlipVerifier
{
    /** @var string */
    private $endpoint;

    /** @var callable|null fn(string $url, array $payload): array{status:int, body:string} */
    private $httpClient;

    /** @var int request timeout in seconds */
    private $timeout;

    public function __construct(
        string $endpoint = 'https://externalauth.ghostxapi.xyz/qr/scan',
        ?callable $httpClient = null,
        int $timeout = 15
    ) {
        $this->endpoint = $endpoint;
        $this->httpClient = $httpClient;
        $this->timeout = $timeout;
    }

    /**
     * Send the QR payload to GhostX and return the normalized transfer.
     *
     * @return array{type:?string, ref:?string, amount:?float, toAccountNo:?string, raw:array}
     * @throws RuntimeException on transport failure, non-200, or unparseable body
     */
    public function scan(string $qrData): array
    {
        $client = $this->httpClient ?? [$this, 'defaultHttpPost'];
        $res = $client($this->endpoint, ['qrData' => $qrData]);

        $status = $res['status'] ?? 0;
        $body = $res['body'] ?? '';
        if ($status !== 200) {
            throw new RuntimeException("GhostX returned HTTP {$status}");
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new RuntimeException('GhostX returned an unparseable body');
        }

        return self::normalize($json);
    }

    /**
     * Normalize a raw GhostX response into the fields the decision logic needs.
     * Pure — used by both scan() and verifyStored() (no HTTP).
     *
     * @return array{type:?string, ref:?string, amount:?float, toAccountNo:?string, raw:array}
     */
    public static function normalize(array $json): array
    {
        $transfer = $json['slipVerification']['transfer'] ?? [];

        return [
            'type' => $json['type'] ?? null,
            'ref' => $transfer['transactionRef'] ?? null,
            'amount' => isset($transfer['amount']['amount']) ? (float) $transfer['amount']['amount'] : null,
            'toAccountNo' => $transfer['toAccountNo'] ?? null,
            'raw' => $json,
        ];
    }

    /**
     * Verify a slip against the expected amount and the shop's account list.
     *
     * Never throws — transport errors degrade to verified=false so the caller
     * can safely fall back to manual admin review.
     *
     * @param string[] $shopAccounts Acceptable destination account numbers (digits; formatting ignored)
     * @return array{verified:bool, reason:string, ref:?string, amount:?float, data:array}
     */
    public function verify(string $qrData, float $expectedAmount, array $shopAccounts): array
    {
        try {
            $s = $this->scan($qrData);
        } catch (\Throwable $e) {
            return [
                'verified' => false,
                'reason' => 'scan_error: ' . $e->getMessage(),
                'ref' => null,
                'amount' => null,
                'data' => [],
            ];
        }

        return $this->evaluate($s, $expectedAmount, $shopAccounts);
    }

    /**
     * Re-evaluate a GhostX response we already stored at upload, WITHOUT calling
     * GhostX again. GhostX rejects re-scans of the same QR with HTTP 409, so the
     * admin "verify" button reuses the saved response instead of re-scanning.
     *
     * @param array $ghostxResponse the raw GhostX payload saved in verify_data
     * @return array{verified:bool, reason:string, ref:?string, amount:?float, data:array}
     */
    public function verifyStored(array $ghostxResponse, float $expectedAmount, array $shopAccounts): array
    {
        return $this->evaluate(self::normalize($ghostxResponse), $expectedAmount, $shopAccounts);
    }

    /**
     * Decision logic over a normalized scan result. Pure — no HTTP.
     *
     * @param array $s normalized result from scan()/normalize()
     * @return array{verified:bool, reason:string, ref:?string, amount:?float, data:array}
     */
    public function evaluate(array $s, float $expectedAmount, array $shopAccounts): array
    {
        $result = [
            'verified' => false,
            'reason' => '',
            'ref' => $s['ref'] ?? null,
            'amount' => $s['amount'] ?? null,
            'data' => $s['raw'] ?? [],
        ];

        if (($s['type'] ?? null) !== 'SLIP' || ($s['ref'] ?? null) === null) {
            $result['reason'] = 'not_a_slip';
            return $result;
        }

        if (!self::amountMatches($expectedAmount, (float) ($s['amount'] ?? 0))) {
            $result['reason'] = 'amount_mismatch';
            return $result;
        }

        $accountOk = false;
        foreach ($shopAccounts as $acct) {
            if (self::accountMatches((string) $acct, (string) ($s['toAccountNo'] ?? ''))) {
                $accountOk = true;
                break;
            }
        }
        if (!$accountOk) {
            $result['reason'] = 'account_mismatch';
            return $result;
        }

        $result['verified'] = true;
        $result['reason'] = 'ok';
        return $result;
    }

    /** Amounts match when equal to the nearest satang (avoids float epsilon traps). */
    public static function amountMatches(float $expected, float $actual): bool
    {
        return (int) round($expected * 100) === (int) round($actual * 100);
    }

    /**
     * Whether a slip's destination account matches the expected account,
     * tolerant of separators and bank-side masking (e.g. "xxx-x-x4321-0").
     */
    public static function accountMatches(string $expected, string $actual): bool
    {
        $e = preg_replace('/\D/', '', $expected);            // expected: digits only
        $a = preg_replace('/[\s\-]/', '', $actual);          // actual: drop separators, keep digits + mask chars
        if ($e === '' || $a === '') {
            return false;
        }

        $hasMask = preg_match('/\D/', $a) === 1;

        if (!$hasMask) {
            if ($e === $a) {
                return true;
            }
            $min = min(strlen($e), strlen($a));
            if ($min < 4) {
                return false;
            }
            return substr($e, -$min) === substr($a, -$min);
        }

        // Masked: align position-by-position when lengths match.
        if (strlen($a) === strlen($e)) {
            $visible = 0;
            for ($i = 0, $n = strlen($a); $i < $n; $i++) {
                if (ctype_digit($a[$i])) {
                    $visible++;
                    if ($a[$i] !== $e[$i]) {
                        return false;
                    }
                }
            }
            return $visible >= 4;
        }

        // Different lengths: compare the trailing run of visible digits.
        if (preg_match('/(\d+)$/', $a, $m)) {
            $vis = $m[1];
            return strlen($vis) >= 4 && substr($e, -strlen($vis)) === $vis;
        }
        return false;
    }

    /** Default cURL transport (used in production when no client is injected). */
    private function defaultHttpPost(string $url, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("cURL error: {$err}");
        }
        return ['status' => $status, 'body' => $body];
    }
}
