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

namespace Tests\Sylius\PayPalPlugin\Unit\Controller;

use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\PayPalPlugin\Controller\CancelLastPayPalPaymentAction;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CancelLastPayPalPaymentActionTest extends TestCase
{
    private ObjectManager $objectManager;

    private StateMachineInterface $stateMachine;

    private OrderProcessorInterface $orderProcessor;

    private OrderRepositoryInterface $orderRepository;

    private CancelLastPayPalPaymentAction $action;

    protected function setUp(): void
    {
        $this->objectManager = $this->createMock(ObjectManager::class);
        $this->stateMachine = $this->createMock(StateMachineInterface::class);
        $this->orderProcessor = $this->createMock(OrderProcessorInterface::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);

        $this->action = new CancelLastPayPalPaymentAction(
            $this->objectManager,
            $this->stateMachine,
            $this->orderProcessor,
            $this->orderRepository,
        );
    }

    public function testReturnNotFoundWhenOrderCannotBeFound(): void
    {
        $request = new Request();
        $request->attributes->set('token', 'order-token');

        $this->orderRepository
            ->expects($this->once())
            ->method('findOneByTokenValue')
            ->with('order-token')
            ->willReturn(null)
        ;

        $this->stateMachine->expects($this->never())->method('can');
        $this->stateMachine->expects($this->never())->method('apply');
        $this->objectManager->expects($this->never())->method('flush');

        $response = ($this->action)($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertEquals('Could not find order by token "order-token".', $response->getContent());
    }

    public function testReturnNoContentWhenOrderHasNoPayment(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $request = new Request();
        $request->attributes->set('token', 'order-token');

        $this->orderRepository
            ->expects($this->once())
            ->method('findOneByTokenValue')
            ->with('order-token')
            ->willReturn($order)
        ;

        $order->expects($this->once())->method('getLastPayment')->willReturn(null);

        $this->stateMachine->expects($this->never())->method('can');
        $this->stateMachine->expects($this->never())->method('apply');
        $this->objectManager->expects($this->never())->method('flush');

        $response = ($this->action)($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        $this->assertEquals('', $response->getContent());
    }

    public function testReturnNoContentWhenPaymentCannotBeCancelled(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $payment = $this->createMock(PaymentInterface::class);
        $request = new Request();
        $request->attributes->set('token', 'order-token');

        $this->orderRepository
            ->expects($this->once())
            ->method('findOneByTokenValue')
            ->with('order-token')
            ->willReturn($order)
        ;

        $order->expects($this->once())->method('getLastPayment')->willReturn($payment);

        $this->stateMachine
            ->expects($this->once())
            ->method('can')
            ->with($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_CANCEL)
            ->willReturn(false)
        ;

        $this->stateMachine->expects($this->never())->method('apply');
        $this->objectManager->expects($this->never())->method('flush');

        $response = ($this->action)($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        $this->assertEquals('', $response->getContent());
    }

    public function testCancelPaymentWhenTransitionIsPossible(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $payment = $this->createMock(PaymentInterface::class);
        $lastPayment = $this->createMock(PaymentInterface::class);
        $request = new Request();
        $request->attributes->set('token', 'order-token');

        $this->orderRepository
            ->expects($this->once())
            ->method('findOneByTokenValue')
            ->with('order-token')
            ->willReturn($order)
        ;

        $order->expects($this->exactly(2))->method('getLastPayment')->willReturn($payment, $lastPayment);

        $this->stateMachine
            ->expects($this->once())
            ->method('can')
            ->with($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_CANCEL)
            ->willReturn(true)
        ;

        $this->stateMachine
            ->expects($this->once())
            ->method('apply')
            ->with($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_CANCEL)
        ;

        $lastPayment->expects($this->once())->method('getState')->willReturn(PaymentInterface::STATE_CART);

        $this->orderProcessor->expects($this->once())->method('process')->with($order);
        $this->objectManager->expects($this->once())->method('flush');

        $response = ($this->action)($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        $this->assertEquals('', $response->getContent());
    }

    public function testSkipOrderProcessingWhenLastPaymentIsNew(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $payment = $this->createMock(PaymentInterface::class);
        $lastPayment = $this->createMock(PaymentInterface::class);
        $request = new Request();
        $request->attributes->set('token', 'order-token');

        $this->orderRepository
            ->expects($this->once())
            ->method('findOneByTokenValue')
            ->with('order-token')
            ->willReturn($order)
        ;

        $order->expects($this->exactly(2))->method('getLastPayment')->willReturn($payment, $lastPayment);

        $this->stateMachine
            ->expects($this->once())
            ->method('can')
            ->with($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_CANCEL)
            ->willReturn(true)
        ;

        $this->stateMachine
            ->expects($this->once())
            ->method('apply')
            ->with($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_CANCEL)
        ;

        $lastPayment->expects($this->once())->method('getState')->willReturn(PaymentInterface::STATE_NEW);

        $this->orderProcessor->expects($this->never())->method('process');
        $this->objectManager->expects($this->once())->method('flush');

        $response = ($this->action)($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        $this->assertEquals('', $response->getContent());
    }
}
