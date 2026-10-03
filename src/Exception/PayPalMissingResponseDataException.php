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

namespace Sylius\PayPalPlugin\Exception;

final class PayPalMissingResponseDataException extends \Exception
{
    public function __construct(array $expectedKeys, public readonly array $data)
    {
        parent::__construct(
            sprintf(
                'Expected PayPal response to contain keys: %s, but they were not present. Actual keys: %s',
                implode(', ', $expectedKeys),
                implode(', ', array_keys($this->data)),
            ),
        );
    }

    public static function assertKeysExist(array $data, string ...$keys): void
    {
        $missingKeys = array_diff($keys, array_keys($data));
        if (count($missingKeys) > 0) {
            throw new self($keys, $data);
        }
    }
}
