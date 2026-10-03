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

use Doctrine\Persistence\ObjectManager;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class CancelLastPayPalPaymentAction
{
    /** @param OrderRepositoryInterface<OrderInterface> $orderRepository */
    public function __construct(
        private ObjectManager $objectManager,
        private StateMachineInterface $stateMachineFactory,
        private OrderProcessorInterface $orderPaymentProcessor,
        private OrderRepositoryInterface $orderRepository,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $token = (string) $request->attributes->get('token');
        /** @var OrderInterface|null $order */
        $order = $this->orderRepository->findOneByTokenValue($token);
        if ($order === null) {
            return new Response(
                sprintf('Could not find order by token "%s".', $token),
                Response::HTTP_NOT_FOUND,
            );
        }

        $payment = $order->getLastPayment();
        if ($payment === null ||
            !$this->stateMachineFactory->can($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_CANCEL)) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $this->stateMachineFactory->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_CANCEL);

        /** @var PaymentInterface $lastPayment */
        $lastPayment = $order->getLastPayment();
        if ($lastPayment->getState() === PaymentInterface::STATE_NEW) {
            $this->objectManager->flush();

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $this->orderPaymentProcessor->process($order);
        $this->objectManager->flush();

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
