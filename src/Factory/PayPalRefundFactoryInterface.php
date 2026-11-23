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

interface PayPalRefundFactoryInterface
{
    /**
     * @param array $refundResource the "resource" of a PAYMENT.CAPTURE.REFUNDED webhook payload
     * @param array $payPalOrderDetails the PayPal order the refunded capture belongs to
     *
     * @throws PayPalMissingResponseDataException
     */
    public function createFromWebhookPayload(array $refundResource, array $payPalOrderDetails): PayPalRefund;
}
