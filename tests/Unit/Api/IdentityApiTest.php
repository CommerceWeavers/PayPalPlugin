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

namespace Tests\Sylius\PayPalPlugin\Unit\Api;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\PayPalPlugin\Api\IdentityApi;
use Sylius\PayPalPlugin\Api\IdentityApiInterface;
use Sylius\PayPalPlugin\Client\PayPalClientInterface;

final class IdentityApiTest extends TestCase
{
    private PayPalClientInterface&MockObject $payPalClient;

    private IdentityApi $identityApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->payPalClient = $this->createMock(PayPalClientInterface::class);
        $this->identityApi = new IdentityApi($this->payPalClient);
    }

    #[Test]
    public function it_implements_identity_api_interface(): void
    {
        self::assertInstanceOf(IdentityApiInterface::class, $this->identityApi);
    }

    #[Test]
    public function it_generates_identity_token(): void
    {
        $this->payPalClient
            ->expects(self::once())
            ->method('post')
            ->with('v1/identity/generate-token', 'TOKEN')
            ->willReturn(['client_token' => 'CLIENT-TOKEN']);

        $result = $this->identityApi->generateToken('TOKEN');

        self::assertEquals('CLIENT-TOKEN', $result);
    }

    #[Test]
    public function it_returns_an_empty_string_when_response_does_not_contain_client_token(): void
    {
        $this->payPalClient
            ->expects(self::once())
            ->method('post')
            ->with('v1/identity/generate-token', 'TOKEN')
            ->willReturn(['foo' => 'bar']);

        $result = $this->identityApi->generateToken('TOKEN');

        self::assertSame('', $result);
    }
}
