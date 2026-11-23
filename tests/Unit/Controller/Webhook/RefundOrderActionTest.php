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

namespace Tests\Sylius\PayPalPlugin\Unit\Controller\Webhook;

use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\PayPalPlugin\Api\CacheAuthorizeClientApiInterface;
use Sylius\PayPalPlugin\Api\WebhookSignatureVerifierInterface;
use Sylius\PayPalPlugin\Controller\Webhook\RefundOrderAction;
use Sylius\PayPalPlugin\Event\PaymentRefundedInPayPal;
use Sylius\PayPalPlugin\Exception\PaymentNotFoundException;
use Sylius\PayPalPlugin\Factory\PayPalRefundFactory;
use Sylius\PayPalPlugin\Provider\PayPalPaymentMethodProviderInterface;
use Sylius\PayPalPlugin\Provider\PayPalRefundDataProviderInterface;
use Sylius\PayPalPlugin\Provider\WebhookIdProviderInterface;
use Sylius\PayPalPlugin\Repository\Query\PaypalPaymentQueryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class RefundOrderActionTest extends TestCase
{
    private const CAPTURE_URL = 'https://api.paypal.com/v2/payments/captures/555';

    private RefundOrderAction $refundOrderAction;

    private StateMachineInterface&MockObject $stateMachine;

    private ObjectManager&MockObject $paymentManager;

    private PayPalRefundDataProviderInterface&MockObject $payPalRefundDataProvider;

    private WebhookSignatureVerifierInterface&MockObject $webhookSignatureVerifier;

    private EventDispatcherInterface&MockObject $eventDispatcher;

    private PaypalPaymentQueryInterface&MockObject $paypalPaymentQuery;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stateMachine = $this->createMock(StateMachineInterface::class);
        $this->paymentManager = $this->createMock(ObjectManager::class);
        $this->payPalRefundDataProvider = $this->createMock(PayPalRefundDataProviderInterface::class);
        $this->webhookSignatureVerifier = $this->createMock(WebhookSignatureVerifierInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->paypalPaymentQuery = $this->createMock(PaypalPaymentQueryInterface::class);

        $payPalPaymentMethodProvider = $this->createMock(PayPalPaymentMethodProviderInterface::class);
        $payPalPaymentMethodProvider->method('provide')->willReturn($this->createMock(PaymentMethodInterface::class));

        $webhookIdProvider = $this->createMock(WebhookIdProviderInterface::class);
        $webhookIdProvider->method('provide')->willReturn('WEBHOOK-ID');

        $authorizeClientApi = $this->createMock(CacheAuthorizeClientApiInterface::class);
        $authorizeClientApi->method('authorize')->willReturn('TOKEN');

        $this->refundOrderAction = new RefundOrderAction(
            $this->stateMachine,
            null,
            $this->paymentManager,
            $this->payPalRefundDataProvider,
            $payPalPaymentMethodProvider,
            $authorizeClientApi,
            $this->webhookSignatureVerifier,
            $webhookIdProvider,
            new PayPalRefundFactory(),
            $this->eventDispatcher,
            $this->paypalPaymentQuery,
        );
    }

    #[Test]
    public function it_does_not_refund_the_payment_when_only_a_part_of_the_capture_was_refunded(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $this->webhookSignatureVerifier->method('verify')->willReturn(true);
        $this->payPalRefundDataProvider->method('provide')->with(self::CAPTURE_URL)->willReturn($this->payPalOrderDetails('PARTIALLY_REFUNDED'));
        $this->paypalPaymentQuery->method('getForRefundingByOrderId')->with('123123')->willReturn($payment);

        $this->stateMachine->expects(self::never())->method('apply');
        $this->eventDispatcher
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(function (PaymentRefundedInPayPal $event) use ($payment): bool {
                return $event->payment === $payment &&
                    674 === $event->refund->amount &&
                    'EUR' === $event->refund->currencyCode &&
                    !$event->refund->captureFullyRefunded;
            }))
            ->willReturnArgument(0)
        ;

        $response = ($this->refundOrderAction)($this->webhookRequest('6.74'));

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
    }

    #[Test]
    public function it_refunds_the_payment_when_the_whole_capture_was_refunded(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $this->webhookSignatureVerifier->method('verify')->willReturn(true);
        $this->payPalRefundDataProvider->method('provide')->with(self::CAPTURE_URL)->willReturn($this->payPalOrderDetails('REFUNDED'));
        $this->paypalPaymentQuery->method('getForRefundingByOrderId')->with('123123')->willReturn($payment);
        $this->stateMachine->method('can')->with($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_REFUND)->willReturn(true);

        $this->stateMachine
            ->expects(self::once())
            ->method('apply')
            ->with($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_REFUND)
        ;
        $this->eventDispatcher
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(fn (PaymentRefundedInPayPal $event): bool => $event->refund->captureFullyRefunded))
            ->willReturnArgument(0)
        ;

        $response = ($this->refundOrderAction)($this->webhookRequest('42.26'));

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
    }

    #[Test]
    public function it_returns_not_found_when_the_webhook_signature_cannot_be_verified(): void
    {
        $this->webhookSignatureVerifier->method('verify')->willReturn(false);

        $this->eventDispatcher->expects(self::never())->method('dispatch');
        $this->stateMachine->expects(self::never())->method('apply');

        $response = ($this->refundOrderAction)($this->webhookRequest('6.74'));

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    #[Test]
    public function it_returns_not_found_when_there_is_no_refundable_payment_for_the_paypal_order(): void
    {
        $this->webhookSignatureVerifier->method('verify')->willReturn(true);
        $this->payPalRefundDataProvider->method('provide')->with(self::CAPTURE_URL)->willReturn($this->payPalOrderDetails('REFUNDED'));
        $this->paypalPaymentQuery->method('getForRefundingByOrderId')->willThrowException(new PaymentNotFoundException());

        $this->eventDispatcher->expects(self::never())->method('dispatch');
        $this->stateMachine->expects(self::never())->method('apply');

        $response = ($this->refundOrderAction)($this->webhookRequest('42.26'));

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    private function webhookRequest(string $amount): Request
    {
        return Request::create('/paypal-webhook/api/', Request::METHOD_POST, [], [], [], [], (string) json_encode([
            'event_type' => 'PAYMENT.CAPTURE.REFUNDED',
            'resource' => [
                'id' => '7WN32115836141000',
                'amount' => ['value' => $amount, 'currency_code' => 'EUR'],
                'create_time' => '2026-11-19T10:30:40Z',
                'links' => [
                    ['rel' => 'self', 'href' => 'https://api.paypal.com/v2/payments/refunds/7WN32115836141000'],
                    ['rel' => 'up', 'href' => self::CAPTURE_URL],
                ],
            ],
        ]));
    }

    private function payPalOrderDetails(string $captureStatus): array
    {
        return [
            'id' => '123123',
            'purchase_units' => [['payments' => ['captures' => [['id' => '555', 'status' => $captureStatus]]]]],
        ];
    }
}
