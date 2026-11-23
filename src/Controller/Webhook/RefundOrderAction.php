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

namespace Sylius\PayPalPlugin\Controller\Webhook;

use Doctrine\Persistence\ObjectManager;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\PayPalPlugin\Api\CacheAuthorizeClientApiInterface;
use Sylius\PayPalPlugin\Api\WebhookSignatureVerifierInterface;
use Sylius\PayPalPlugin\Event\PaymentRefundedInPayPal;
use Sylius\PayPalPlugin\Exception\PaymentNotFoundException;
use Sylius\PayPalPlugin\Exception\PayPalWrongDataException;
use Sylius\PayPalPlugin\Factory\PayPalRefundFactoryInterface;
use Sylius\PayPalPlugin\Provider\PaymentProviderInterface;
use Sylius\PayPalPlugin\Provider\PayPalPaymentMethodProviderInterface;
use Sylius\PayPalPlugin\Provider\PayPalRefundDataProviderInterface;
use Sylius\PayPalPlugin\Provider\WebhookIdProviderInterface;
use Sylius\PayPalPlugin\Repository\Query\PaypalPaymentQueryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Webmozart\Assert\Assert;

final readonly class RefundOrderAction
{
    public function __construct(
        private StateMachineInterface $stateMachineFactory,
        private ?PaymentProviderInterface $paymentProvider,
        private ObjectManager $paymentManager,
        private PayPalRefundDataProviderInterface $payPalRefundDataProvider,
        private PayPalPaymentMethodProviderInterface $payPalPaymentMethodProvider,
        private CacheAuthorizeClientApiInterface $authorizeClientApi,
        private WebhookSignatureVerifierInterface $webhookSignatureVerifier,
        private WebhookIdProviderInterface $webhookIdProvider,
        private PayPalRefundFactoryInterface $payPalRefundFactory,
        private EventDispatcherInterface $eventDispatcher,
        private ?PaypalPaymentQueryInterface $paypalPaymentQuery = null,
    ) {
        if (null !== $this->paymentProvider) {
            trigger_deprecation(
                'sylius/paypal-plugin',
                '1.7',
                sprintf(
                    'Passing an instance of "%s" as the second argument is deprecated and will be prohibited in 3.0',
                    PaymentProviderInterface::class,
                ),
            );
        }
        if (null === $this->paypalPaymentQuery) {
            trigger_deprecation(
                'sylius/paypal-plugin',
                '1.7',
                sprintf(
                    'Not passing an instance of "%s" is deprecated and will be prohibited in 3.0',
                    PaypalPaymentQueryInterface::class,
                ),
            );
        }
    }

    public function __invoke(Request $request): Response
    {
        try {
            $paymentMethod = $this->payPalPaymentMethodProvider->provide();
            $webhookId = $this->webhookIdProvider->provide($paymentMethod);
            $token = $this->authorizeClientApi->authorize($paymentMethod);

            $verified = null !== $webhookId && $this->webhookSignatureVerifier->verify($request, $webhookId, $token);

            if (!$verified) {
                $freshWebhookId = $this->webhookIdProvider->refresh($paymentMethod);
                if (null !== $freshWebhookId && $freshWebhookId !== $webhookId) {
                    $verified = $this->webhookSignatureVerifier->verify($request, $freshWebhookId, $token);
                }
            }
        } catch (\Throwable) {
            $verified = false;
        }

        if (!$verified) {
            return new JsonResponse(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        $refundResource = $this->getRefundResource($request);
        $refundData = $this->payPalRefundDataProvider->provide($this->getPayPalPaymentUrl($refundResource));

        try {
            if (null !== $this->paypalPaymentQuery) {
                $payment = $this->paypalPaymentQuery->getForRefundingByOrderId((string) $refundData['id']);
            } else {
                $payment = $this->paymentProvider->getByPayPalOrderId((string) $refundData['id']);
            }
        } catch (PaymentNotFoundException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_NOT_FOUND);
        }

        $refund = $this->payPalRefundFactory->createFromWebhookPayload($refundResource, $refundData);

        if (
            $refund->captureFullyRefunded &&
            $this->stateMachineFactory->can($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_REFUND)
        ) {
            $this->stateMachineFactory->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_REFUND);
        }

        $this->paymentManager->flush();

        $this->eventDispatcher->dispatch(new PaymentRefundedInPayPal($payment, $refund));

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    private function getRefundResource(Request $request): array
    {
        /**
         * @var string $content
         */
        $content = $request->getContent();

        $content = (array) json_decode($content, true);
        Assert::keyExists($content, 'resource');

        return (array) $content['resource'];
    }

    private function getPayPalPaymentUrl(array $refundResource): string
    {
        Assert::keyExists($refundResource, 'links');

        /** @var string[] $link */
        foreach ($refundResource['links'] as $link) {
            if ($link['rel'] === 'up') {
                return $link['href'];
            }
        }

        throw new PayPalWrongDataException();
    }
}
