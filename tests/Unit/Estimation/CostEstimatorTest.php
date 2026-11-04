<?php

declare(strict_types=1);

namespace PierreArthur\SyliusUpgradeAnalyzer\Tests\Unit\Estimation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PierreArthur\SyliusUpgradeAnalyzer\Estimation\CostEstimator;

/**
 * Tests unitaires du calcul budgétaire à partir du TJM.
 */
final class CostEstimatorTest extends TestCase
{
    /** Vérifie qu'une charge de 14 h correspond exactement à 2 jours. */
    #[Test]
    public function testConvertsHoursToDaysOnSevenHourBasis(): void
    {
        $estimate = (new CostEstimator())->estimate(14.0, 600);

        self::assertSame(2.0, $estimate->days);
        self::assertSame(1200.0, $estimate->amount);
        self::assertSame(600, $estimate->dailyRate);
        self::assertSame(14.0, $estimate->hours);
    }

    /** Vérifie l'arrondi à la demi-journée supérieure : 8 h = 1,5 jour. */
    #[Test]
    public function testRoundsUpToNextHalfDay(): void
    {
        $estimate = (new CostEstimator())->estimate(8.0, 500);

        self::assertSame(1.5, $estimate->days);
        self::assertSame(750.0, $estimate->amount);
    }

    /** Vérifie qu'un projet sans charge ne coûte rien. */
    #[Test]
    public function testReturnsZeroCostForZeroHours(): void
    {
        $estimate = (new CostEstimator())->estimate(0.0, 650);

        self::assertSame(0.0, $estimate->days);
        self::assertSame(0.0, $estimate->amount);
    }

    /** Vérifie le refus d'un TJM nul ou négatif. */
    #[Test]
    public function testRejectsNonPositiveDailyRate(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new CostEstimator())->estimate(10.0, 0);
    }
}
