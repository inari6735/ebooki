<?php declare(strict_types=1);

namespace App\Tests\Commerce\Infrastructure\Payment\PayU;

use App\Commerce\Infrastructure\Payment\PayU\PayUConfig;
use App\Commerce\Infrastructure\Payment\PayU\PayUSignatureVerifier;
use PHPUnit\Framework\TestCase;

final class PayUSignatureVerifierTest extends TestCase
{
    private const string SECOND_KEY = 's3cond-k3y';

    private function verifier(): PayUSignatureVerifier
    {
        return new PayUSignatureVerifier(new PayUConfig(0, '', '', self::SECOND_KEY, true));
    }

    public function testValidMd5Signature(): void
    {
        $body = '{"order":{"orderId":"ABC","status":"COMPLETED"}}';
        $sig = md5($body . self::SECOND_KEY);

        self::assertTrue($this->verifier()->isValid($body, "sender=checkout;signature={$sig};algorithm=MD5;content=DOCUMENT"));
    }

    public function testValidSha256Signature(): void
    {
        $body = '{"order":{"orderId":"ABC"}}';
        $sig = hash('sha256', $body . self::SECOND_KEY);

        self::assertTrue($this->verifier()->isValid($body, "signature={$sig};algorithm=SHA-256"));
    }

    public function testTamperedSignatureIsRejected(): void
    {
        $body = '{"order":{"orderId":"ABC"}}';

        self::assertFalse($this->verifier()->isValid($body, 'signature=deadbeef;algorithm=MD5'));
    }

    public function testTamperedBodyIsRejected(): void
    {
        $sig = md5('{"a":1}' . self::SECOND_KEY);

        self::assertFalse($this->verifier()->isValid('{"a":2}', "signature={$sig};algorithm=MD5"));
    }

    public function testMissingHeaderIsRejected(): void
    {
        self::assertFalse($this->verifier()->isValid('{}', null));
        self::assertFalse($this->verifier()->isValid('{}', ''));
    }
}
