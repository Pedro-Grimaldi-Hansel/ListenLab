<?php
declare(strict_types=1);

namespace ListenLab\Tests\Unit;

use DateTimeImmutable;
use ListenLab\Analytics\Period;
use PHPUnit\Framework\TestCase;

final class AnalyticsPeriodTest extends TestCase
{
    public function testPresetsUseLocalCalendarDaysAndAllHistoryHasNoBounds(): void
    {
        $now = new DateTimeImmutable('2026-11-11T01:00:00Z'); // Still Nov 10 in São Paulo.
        $period = Period::fromQuery(['period' => '7d', 'limit' => '20'], 'America/Sao_Paulo', $now);
        self::assertSame(['name' => '7d', 'from' => '2026-11-04', 'to' => '2026-11-10', 'limit' => 20], $period->describe());
        self::assertSame(['2026-11-04T03:00:00+00:00', '2026-11-11T03:00:00+00:00'], $period->selection()[1]);
        self::assertSame(['TRUE', []], Period::fromQuery([], 'America/Sao_Paulo', $now)->selection());
        self::assertSame('2026-10-12', Period::fromQuery(['period' => '30d'], 'America/Sao_Paulo', $now)->describe()['from']);
        self::assertSame('2026-08-13', Period::fromQuery(['period' => '90d'], 'America/Sao_Paulo', $now)->describe()['from']);
    }

    public function testInclusiveDatesBecomeHalfOpenUtcBoundsAcrossDst(): void
    {
        $spring = Period::fromQuery(['period' => 'custom', 'from' => '2018-11-04', 'to' => '2018-11-04'], 'America/Sao_Paulo');
        self::assertSame(['2018-11-04T03:00:00+00:00', '2018-11-05T02:00:00+00:00'], $spring->selection()[1]);
        $fall = Period::fromQuery(['period' => 'custom', 'from' => '2026-11-01', 'to' => '2026-11-01'], 'America/New_York');
        self::assertSame(['2026-11-01T04:00:00+00:00', '2026-11-02T05:00:00+00:00'], $fall->selection()[1]);
    }

    public function testInvalidDatesArraysAndUnboundedRankingSizesAreRejected(): void
    {
        foreach ([
            ['period' => ['all']], ['period' => 'forever'], ['limit' => '500'], ['limit' => ['10']], ['limit' => '1 OR 1=1'],
            ['period' => 'custom'], ['period' => 'all', 'from' => '2026-11-10'], ['extra' => 'value'],
            ['period' => 'custom', 'from' => '2026-02-29', 'to' => '2026-03-01'],
            ['period' => 'custom', 'from' => '2026-11-12', 'to' => '2026-11-10'],
            ['period' => 'custom', 'from' => ['2026-11-10'], 'to' => '2026-11-11'],
            ['period' => 'custom', 'from' => '0000-01-01', 'to' => '2026-11-11'],
        ] as $query) {
            try { Period::fromQuery($query, 'America/Sao_Paulo'); self::fail('An invalid analytics filter was accepted.'); }
            catch (\InvalidArgumentException $error) { self::assertNotSame('', $error->getMessage()); }
        }
    }
}
