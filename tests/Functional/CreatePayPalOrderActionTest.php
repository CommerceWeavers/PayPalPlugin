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

namespace Tests\Sylius\PayPalPlugin\Functional;

use ApiTestCase\JsonApiTestCase;
use Symfony\Component\HttpFoundation\Response;

final class CreatePayPalOrderActionTest extends JsonApiTestCase
{
    /** @test */
    public function it_returns_conflict_if_order_has_no_payment_with_state_new(): void
    {
        $this->loadFixturesFromFiles(['resources/shop.yaml', 'resources/processing_order.yaml']);

        $this->client->request('POST', '/en_US/create-pay-pal-order/TOKEN');

        $response = $this->client->getResponse();
        $content = (array) json_decode($response->getContent(), true);

        $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        $this->assertSame($content['error'], 'Order with token "TOKEN" has no payment with state "new"');
    }

    /** @test */
    public function it_creates_paypal_order_and_returns_its_data(): void
    {
        $this->loadFixturesFromFiles(['resources/shop.yaml', 'resources/new_order.yaml']);

        $this->client->request('POST', '/en_US/create-pay-pal-order/TOKEN');

        $response = $this->client->getResponse();
        $content = (array) json_decode($response->getContent(), true);

        $this->assertSame($content['orderID'], 'PAYPAL_ORDER_ID');
        $this->assertSame($content['status'], 'processing');
    }
}
