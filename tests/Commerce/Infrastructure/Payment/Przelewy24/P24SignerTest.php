<?php declare(strict_types=1);

namespace App\Tests\Commerce\Infrastructure\Payment\Przelewy24;

use App\Commerce\Infrastructure\Payment\Przelewy24\P24Signer;
use PHPUnit\Framework\TestCase;

/**
 * Locks down the exact JSON (field order, integer types, no slash escaping) that
 * Przelewy24 hashes — a wrong shape yields a wrong signature and rejected
 * transactions, so the expected hashes are computed from hand-written JSON.
 */
final class P24SignerTest extends TestCase
{
    private const string CRC = 'a1b2c3d4e5f6';

    public function testRegisterSignatureMatchesSpecOrdering(): void
    {
        $signer = new P24Signer(self::CRC);

        $expected = hash('sha384', '{"sessionId":"ORDER-1","merchantId":12345,"amount":2990,"currency":"PLN","crc":"a1b2c3d4e5f6"}');

        self::assertSame($expected, $signer->forRegister('ORDER-1', 12345, 2990, 'PLN'));
    }

    public function testVerifySignatureMatchesSpecOrdering(): void
    {
        $signer = new P24Signer(self::CRC);

        $expected = hash('sha384', '{"sessionId":"ORDER-1","orderId":98765,"amount":2990,"currency":"PLN","crc":"a1b2c3d4e5f6"}');

        self::assertSame($expected, $signer->forVerify('ORDER-1', 98765, 2990, 'PLN'));
    }

    public function testNotificationSignatureMatchesSpecOrdering(): void
    {
        $signer = new P24Signer(self::CRC);

        $expected = hash('sha384', '{"merchantId":12345,"posId":12345,"sessionId":"ORDER-1","amount":2990,"originAmount":2990,"currency":"PLN","orderId":98765,"methodId":25,"statement":"payment","crc":"a1b2c3d4e5f6"}');

        self::assertSame($expected, $signer->forNotification(12345, 12345, 'ORDER-1', 2990, 2990, 'PLN', 98765, 25, 'payment'));
    }

    public function testDifferentAmountProducesDifferentSignature(): void
    {
        $signer = new P24Signer(self::CRC);

        self::assertNotSame(
            $signer->forRegister('ORDER-1', 12345, 2990, 'PLN'),
            $signer->forRegister('ORDER-1', 12345, 1990, 'PLN'),
        );
    }
}
