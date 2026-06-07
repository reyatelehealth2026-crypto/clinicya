<?php
/**
 * SlipVerifier Test
 *
 * Unit tests for the GhostX QR slip-verification client + decision logic.
 * The HTTP transport is injected so tests never hit the network.
 *
 * @spec ghostx-slip-verification
 */

require_once __DIR__ . '/../../classes/SlipVerifier.php';

use PHPUnit\Framework\TestCase;

class SlipVerifierTest extends TestCase
{
    /** Build the GhostX example "200 OK" body for a valid slip. */
    private function validSlipBody(float $amount = 500.00, string $toAccountNo = '987-6-54321-0'): string
    {
        return json_encode([
            'type' => 'SLIP',
            'slipVerification' => [
                'transfer' => [
                    'transactionRef' => '202504270001234567',
                    'transactionDateTime' => '2025-04-27T10:30:00+07:00',
                    'fromBankName' => 'SCB',
                    'fromAccountNo' => '123-4-56789-0',
                    'fromAccountName' => 'นาย ตัวอย่าง ทดสอบ',
                    'toBankName' => 'KTB',
                    'toAccountNo' => $toAccountNo,
                    'toAccountName' => 'นาย ปลายทาง ทดสอบ',
                    'amount' => ['amount' => $amount, 'currency' => ['code' => 'THB', 'symbol' => '฿']],
                ],
            ],
            'contact' => ['website' => 'ghostxapi.xyz', 'telegram' => '@ghostx168'],
        ], JSON_UNESCAPED_UNICODE);
    }

    /** A SlipVerifier whose HTTP transport returns a canned response. */
    private function verifierReturning(int $status, string $body): SlipVerifier
    {
        return new SlipVerifier('https://test.invalid/qr/scan', function ($url, $payload) use ($status, $body) {
            return ['status' => $status, 'body' => $body];
        });
    }

    public function testScanReturnsNormalizedTransfer(): void
    {
        $v = $this->verifierReturning(200, $this->validSlipBody());
        $r = $v->scan('QRDATA');

        $this->assertSame('SLIP', $r['type']);
        $this->assertSame('202504270001234567', $r['ref']);
        $this->assertSame(500.00, $r['amount']);
        $this->assertSame('987-6-54321-0', $r['toAccountNo']);
        $this->assertIsArray($r['raw']);
    }

    public function testVerifySucceedsWhenAmountAndAccountMatch(): void
    {
        $v = $this->verifierReturning(200, $this->validSlipBody(500.00, '987-6-54321-0'));
        $r = $v->verify('QRDATA', 500.00, ['9876543210']);

        $this->assertTrue($r['verified']);
        $this->assertSame('ok', $r['reason']);
        $this->assertSame('202504270001234567', $r['ref']);
        $this->assertSame(500.00, $r['amount']);
    }

    public function testVerifyFailsOnAmountMismatch(): void
    {
        $v = $this->verifierReturning(200, $this->validSlipBody(499.00, '987-6-54321-0'));
        $r = $v->verify('QRDATA', 500.00, ['9876543210']);

        $this->assertFalse($r['verified']);
        $this->assertSame('amount_mismatch', $r['reason']);
        // Ref is still surfaced so an admin can review the real slip.
        $this->assertSame('202504270001234567', $r['ref']);
    }

    public function testVerifyFailsOnAccountMismatch(): void
    {
        $v = $this->verifierReturning(200, $this->validSlipBody(500.00, '111-1-11111-1'));
        $r = $v->verify('QRDATA', 500.00, ['9876543210']);

        $this->assertFalse($r['verified']);
        $this->assertSame('account_mismatch', $r['reason']);
    }

    public function testVerifyFailsWhenNotASlip(): void
    {
        $v = $this->verifierReturning(200, json_encode(['type' => 'UNKNOWN']));
        $r = $v->verify('QRDATA', 500.00, ['9876543210']);

        $this->assertFalse($r['verified']);
        $this->assertSame('not_a_slip', $r['reason']);
    }

    public function testVerifyFailsGracefullyOnHttpError(): void
    {
        $v = new SlipVerifier('https://test.invalid/qr/scan', function ($url, $payload) {
            throw new RuntimeException('connection refused');
        });
        $r = $v->verify('QRDATA', 500.00, ['9876543210']);

        $this->assertFalse($r['verified']);
        $this->assertStringStartsWith('scan_error', $r['reason']);
    }

    public function testVerifyFailsGracefullyOnNon200(): void
    {
        $v = $this->verifierReturning(502, 'Bad Gateway');
        $r = $v->verify('QRDATA', 500.00, ['9876543210']);

        $this->assertFalse($r['verified']);
        $this->assertStringStartsWith('scan_error', $r['reason']);
    }

    public function testScanAcceptsAlreadyScanned409WithSlipData(): void
    {
        // GhostX returns 409 when a QR was already scanned, but still includes
        // the slip data — we should use it rather than error out.
        $v = $this->verifierReturning(409, $this->validSlipBody(500.00, '987-6-54321-0'));
        $r = $v->verify('QRDATA', 500.00, ['9876543210']);

        $this->assertTrue($r['verified']);
        $this->assertSame('ok', $r['reason']);
    }

    public function testScanStillThrowsOn409WithoutSlipData(): void
    {
        $v = $this->verifierReturning(409, json_encode(['error' => 'already used']));
        $r = $v->verify('QRDATA', 500.00, ['9876543210']);

        $this->assertFalse($r['verified']);
        $this->assertStringStartsWith('scan_error', $r['reason']);
    }

    public function testVerifyMatchesAnyOfMultipleShopAccounts(): void
    {
        $v = $this->verifierReturning(200, $this->validSlipBody(500.00, '987-6-54321-0'));
        $r = $v->verify('QRDATA', 500.00, ['1112223334', '9876543210', '5556667778']);

        $this->assertTrue($r['verified']);
    }

    // --- pure helpers ---------------------------------------------------

    public function testAccountMatchesIgnoresFormatting(): void
    {
        $this->assertTrue(SlipVerifier::accountMatches('9876543210', '987-6-54321-0'));
        $this->assertTrue(SlipVerifier::accountMatches('987-6-54321-0', '9876543210'));
    }

    public function testAccountMatchesWithMaskedSlip(): void
    {
        // Bank masks leading digits; visible digits align with the real account.
        $this->assertTrue(SlipVerifier::accountMatches('9876543210', 'xxx-x-x4321-0'));
        $this->assertFalse(SlipVerifier::accountMatches('9876543210', 'xxx-x-x4329-9'));
    }

    public function testAccountMatchesRejectsDifferentAccount(): void
    {
        $this->assertFalse(SlipVerifier::accountMatches('9876543210', '1234567890'));
    }

    public function testAmountMatchesToTwoDecimals(): void
    {
        $this->assertTrue(SlipVerifier::amountMatches(500.0, 500.00));
        // Sub-satang float drift (e.g. from JSON parsing) is tolerated.
        $this->assertTrue(SlipVerifier::amountMatches(500.001, 500.00));
        // A full satang difference is a real mismatch.
        $this->assertFalse(SlipVerifier::amountMatches(500.01, 500.00));
        $this->assertFalse(SlipVerifier::amountMatches(499.99, 500.00));
    }

    // --- verifyStored: re-evaluate a saved GhostX response, NO new HTTP call --

    /** A verifier whose HTTP transport always throws — proves no network is used. */
    private function offlineVerifier(): SlipVerifier
    {
        return new SlipVerifier('https://test.invalid/qr/scan', function ($url, $payload) {
            throw new RuntimeException('network must not be called');
        });
    }

    private function storedResponse(float $amount = 500.00, string $toAccountNo = '987-6-54321-0'): array
    {
        return json_decode($this->validSlipBody($amount, $toAccountNo), true);
    }

    public function testVerifyStoredApprovesWithoutHttpCall(): void
    {
        $r = $this->offlineVerifier()->verifyStored($this->storedResponse(500.00, '987-6-54321-0'), 500.00, ['9876543210']);

        $this->assertTrue($r['verified']);
        $this->assertSame('ok', $r['reason']);
        $this->assertSame('202504270001234567', $r['ref']);
    }

    public function testVerifyStoredRejectsAmountMismatchWithoutHttp(): void
    {
        $r = $this->offlineVerifier()->verifyStored($this->storedResponse(499.00, '987-6-54321-0'), 500.00, ['9876543210']);

        $this->assertFalse($r['verified']);
        $this->assertSame('amount_mismatch', $r['reason']);
    }

    public function testVerifyStoredRejectsEmptyOrNonSlipData(): void
    {
        $r = $this->offlineVerifier()->verifyStored([], 500.00, ['9876543210']);

        $this->assertFalse($r['verified']);
        $this->assertSame('not_a_slip', $r['reason']);
    }
}
