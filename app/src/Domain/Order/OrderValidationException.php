<?php
declare(strict_types=1);
namespace App\Domain\Order;
use RuntimeException;
final class OrderValidationException extends RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors){parent::__construct(implode(' ',$errors));}
}
