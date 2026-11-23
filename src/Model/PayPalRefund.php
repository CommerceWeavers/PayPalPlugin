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

namespace Sylius\PayPalPlugin\Model;

/** A refund made against a PayPal capture, as reported by the PAYMENT.CAPTURE.REFUNDED webhook. */
final readonly class PayPalRefund
{
    public function __construct(
        public string $id,
        public int $amount,
        public string $currencyCode,
        public bool $captureFullyRefunded,
        public \DateTimeImmutable $refundedAt,
        public ?string $invoiceId = null,
    ) {
    }
}
