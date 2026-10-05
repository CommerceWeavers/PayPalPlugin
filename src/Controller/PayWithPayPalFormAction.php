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

namespace Sylius\PayPalPlugin\Controller;

use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Sylius\PayPalPlugin\Api\CacheAuthorizeClientApiInterface;
use Sylius\PayPalPlugin\Api\IdentityApiInterface;
use Sylius\PayPalPlugin\Processor\LocaleProcessorInterface;
use Sylius\PayPalPlugin\Provider\AvailableCountriesProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;

final readonly class PayWithPayPalFormAction
{
    /** @param PaymentRepositoryInterface<PaymentInterface> $paymentRepository */
    public function __construct(
        private Environment $twig,
        private PaymentRepositoryInterface $paymentRepository,
        private AvailableCountriesProviderInterface $countriesProvider,
        private CacheAuthorizeClientApiInterface $authorizeClientApi,
        private IdentityApiInterface $identityApi,
        private ?LocaleProcessorInterface $localeProcessor = null,
    ) {
        if (null === $this->localeProcessor) {
            trigger_deprecation(
                'SyliusPayPalPlugin',
                '1.7',
                'Not passing an instance of %s to %s constructor is deprecated and will be required in 3.0.',
                LocaleProcessorInterface::class,
                self::class,
            );
        }
    }

    public function __invoke(Request $request): Response
    {
        $paymentId = (string) $request->attributes->get('paymentId');
        $orderToken = (string) $request->attributes->get('orderToken');

        /** @var PaymentInterface|null $payment */
        $payment = $this->paymentRepository->findOneByOrderToken($paymentId, $orderToken);
        if ($payment === null) {
            throw new NotFoundHttpException();
        }

        /** @var PaymentMethodInterface $paymentMethod */
        $paymentMethod = $payment->getMethod();

        /** @var GatewayConfigInterface $gatewayConfig */
        $gatewayConfig = $paymentMethod->getGatewayConfig();
        /** @var string $clientId */
        $clientId = $gatewayConfig->getConfig()['client_id'];
        /** @var string $partnerAttributionId */
        $partnerAttributionId = $gatewayConfig->getConfig()['partner_attribution_id'];

        /** @var OrderInterface $order */
        $order = $payment->getOrder();

        $token = $this->authorizeClientApi->authorize($paymentMethod);
        $clientToken = $this->identityApi->generateToken($token);
        $locale = $request->getLocale();

        return new Response($this->twig->render('@SyliusPayPalPlugin/pay_with_paypal.html.twig', [
            'available_countries' => $this->countriesProvider->provide(),
            'billing_address' => $order->getBillingAddress(),
            'client_id' => $clientId,
            'client_token' => $clientToken,
            'currency' => $order->getCurrencyCode(),
            'locale' => null !== $this->localeProcessor ? $this->localeProcessor->process($locale) : $locale,
            'merchant_id' => $gatewayConfig->getConfig()['merchant_id'],
            'order_token' => $order->getTokenValue(),
            'partner_attribution_id' => $partnerAttributionId,
        ]));
    }
}
