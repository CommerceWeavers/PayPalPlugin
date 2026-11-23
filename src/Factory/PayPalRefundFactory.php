<?php

/*
 * This file is part of the Sylius package.
 *
 * (c) Sylius Sp. z o.o.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Sylius\PayPalPlugin\Factory;

use Sylius\PayPalPlugin\Exception\PayPalMissingResponseDataException;
use Sylius\PayPalPlugin\Model\PayPalRefund;

final class PayPalRefundFactory implements PayPalRefundFactoryInterface
{
    private const CAPTURE_STATUS_REFUNDED = 'REFUNDED';

    public function createFromWebhookPayload(array $refundResource, array $payPalOrderDetails): PayPalRefund
    {
        PayPalMissingResponseDataException::assertKeysExist($refundResource, 'id', 'amount');

        $amount = (array) $refundResource['amount'];
        PayPalMissingResponseDataException::assertKeysExist($amount, 'value', 'currency_code');

        return new PayPalRefund(
            (string) $refundResource['id'],
            $this->convertToMinorUnits((string) $amount['value']),
            (string) $amount['currency_code'],
            $this->isCaptureFullyRefunded($payPalOrderDetails),
            new \DateTimeImmutable((string) ($refundResource['create_time'] ?? 'now')),
            isset($refundResource['invoice_id']) ? (string) $refundResource['invoice_id'] : null,
        );
    }

    private function isCaptureFullyRefunded(array $payPalOrderDetails): bool
    {
        PayPalMissingResponseDataException::assertKeysExist($payPalOrderDetails, 'purchase_units');

        $payments = (array) ($payPalOrderDetails['purchase_units'][0]['payments'] ?? []);
        PayPalMissingResponseDataException::assertKeysExist($payments, 'captures');

        $capture = (array) ($payments['captures'][0] ?? []);
        PayPalMissingResponseDataException::assertKeysExist($capture, 'status');

        return self::CAPTURE_STATUS_REFUNDED === $capture['status'];
    }

    private function convertToMinorUnits(string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
