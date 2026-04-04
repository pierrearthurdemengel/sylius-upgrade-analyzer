<?php

declare(strict_types=1);

namespace App\Entity\Customer;

use Sylius\Component\Core\Model\Customer as BaseCustomer;

class Customer extends BaseCustomer
{
    public function getSalt(): ?string
    {
        return null;
    }
}
