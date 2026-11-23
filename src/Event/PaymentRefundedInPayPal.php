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

namespace Sylius\PayPalPlugin\Event;

use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\PayPalPlugin\Model\PayPalRefund;

/**
 * Dispatched when PayPal reports that a capture has been refunded, whether in full or in part,
 * and whether the refund was made in the PayPal panel or through this plugin.
 */
final readonly class PaymentRefundedInPayPal
{
    public function __construct(
        public PaymentInterface $payment,
        public PayPalRefund $refund,
    ) {
    }
}
