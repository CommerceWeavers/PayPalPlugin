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

namespace Tests\Sylius\PayPalPlugin\Unit\Factory;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sylius\PayPalPlugin\Exception\PayPalMissingResponseDataException;
use Sylius\PayPalPlugin\Factory\PayPalRefundFactory;
use Sylius\PayPalPlugin\Factory\PayPalRefundFactoryInterface;

final class PayPalRefundFactoryTest extends TestCase
{
    private PayPalRefundFactory $payPalRefundFactory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->payPalRefundFactory = new PayPalRefundFactory();
    }

    #[Test]
    public function it_implements_pay_pal_refund_factory_interface(): void
    {
        self::assertInstanceOf(PayPalRefundFactoryInterface::class, $this->payPalRefundFactory);
    }

    #[Test]
    public function it_creates_a_refund_from_a_webhook_payload(): void
    {
        $refund = $this->payPalRefundFactory->createFromWebhookPayload(
            $this->refundResource('6.74', 'refund of overpayment 6.74'),
            $this->payPalOrderDetails('PARTIALLY_REFUNDED'),
        );

        self::assertSame('7WN32115836141000', $refund->id);
        self::assertSame(674, $refund->amount);
        self::assertSame('EUR', $refund->currencyCode);
        self::assertSame('refund of overpayment 6.74', $refund->invoiceId);
        self::assertSame('2026-11-19T10:30:40+00:00', $refund->refundedAt->format(\DateTimeInterface::ATOM));
    }

    #[Test]
    public function it_marks_the_capture_as_fully_refunded_only_when_paypal_reports_it_so(): void
    {
        $partial = $this->payPalRefundFactory->createFromWebhookPayload(
            $this->refundResource('6.74'),
            $this->payPalOrderDetails('PARTIALLY_REFUNDED'),
        );
        $full = $this->payPalRefundFactory->createFromWebhookPayload(
            $this->refundResource('42.26'),
            $this->payPalOrderDetails('REFUNDED'),
        );

        self::assertFalse($partial->captureFullyRefunded);
        self::assertTrue($full->captureFullyRefunded);
    }

    #[Test]
    public function it_creates_a_refund_without_an_invoice_id(): void
    {
        $refund = $this->payPalRefundFactory->createFromWebhookPayload(
            $this->refundResource('6.74'),
            $this->payPalOrderDetails('PARTIALLY_REFUNDED'),
        );

        self::assertNull($refund->invoiceId);
    }

    #[Test]
    public function it_throws_an_exception_when_the_refund_resource_does_not_contain_expected_keys(): void
    {
        $this->expectException(PayPalMissingResponseDataException::class);

        $this->payPalRefundFactory->createFromWebhookPayload(['id' => '7WN32115836141000'], $this->payPalOrderDetails('REFUNDED'));
    }

    #[Test]
    public function it_throws_an_exception_when_the_order_details_do_not_contain_the_capture(): void
    {
        $this->expectException(PayPalMissingResponseDataException::class);

        $this->payPalRefundFactory->createFromWebhookPayload($this->refundResource('6.74'), ['purchase_units' => [[]]]);
    }

    private function refundResource(string $amount, ?string $invoiceId = null): array
    {
        $resource = [
            'id' => '7WN32115836141000',
            'amount' => ['value' => $amount, 'currency_code' => 'EUR'],
            'create_time' => '2026-11-19T10:30:40Z',
        ];

        if (null !== $invoiceId) {
            $resource['invoice_id'] = $invoiceId;
        }

        return $resource;
    }

    private function payPalOrderDetails(string $captureStatus): array
    {
        return ['purchase_units' => [['payments' => ['captures' => [['id' => '555', 'status' => $captureStatus]]]]]];
    }
}
