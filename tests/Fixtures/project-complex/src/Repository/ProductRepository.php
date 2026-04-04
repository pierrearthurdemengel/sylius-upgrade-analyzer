<?php

declare(strict_types=1);

namespace App\Repository;

use Sylius\Bundle\CoreBundle\Doctrine\ORM\ProductRepository as BaseProductRepository;

class ProductRepository extends BaseProductRepository
{
    public function findByCustomCriteria(string $locale): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.translations', 't')
            ->where('t.locale = :locale')
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getResult();
    }
}
