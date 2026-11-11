<?php
declare(strict_types=1);

namespace ListenLab\Analytics;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class Period
{
    private function __construct(
        public string $name,
        public DateTimeZone $timezone,
        public ?DateTimeImmutable $from,
        public ?DateTimeImmutable $until,
        public int $limit,
    ) {}

    public static function fromQuery(array $query, string $timezone, ?DateTimeImmutable $now = null): self
    {
        try { $zone = new DateTimeZone($timezone); }
        catch (\Exception) { throw new \RuntimeException('MONITOR_TIMEZONE inválido.'); }
        $name = $query['period'] ?? 'all';
        $limit = $query['limit'] ?? '10';
        if (array_diff(array_keys($query), ['period', 'from', 'to', 'limit'])
            || !is_string($name) || !in_array($name, ['all', '7d', '30d', '90d', 'custom'], true)
            || !is_string($limit) || !in_array($limit, ['10', '20', '50'], true)) {
            throw new InvalidArgumentException('Período ou tamanho do ranking inválido.');
        }
        if ($name === 'custom') {
            $from = self::day($query['from'] ?? null, $zone);
            $to = self::day($query['to'] ?? null, $zone);
            if ($from > $to) throw new InvalidArgumentException('A data inicial deve ser anterior ou igual à final.');
            return new self($name, $zone, $from, $to->modify('+1 day')->setTime(0, 0), (int) $limit);
        }
        if (array_key_exists('from', $query) || array_key_exists('to', $query)) {
            throw new InvalidArgumentException('Use as datas somente no período personalizado.');
        }
        $today = ($now ?? new DateTimeImmutable())->setTimezone($zone)->setTime(0, 0);
        return new self($name, $zone,
            $name === 'all' ? null : $today->modify('-' . ((int) $name - 1) . ' days')->setTime(0, 0),
            $name === 'all' ? null : $today->modify('+1 day')->setTime(0, 0), (int) $limit);
    }

    private static function day(mixed $value, DateTimeZone $zone): DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^(?:[0-9]{4})-[0-9]{2}-[0-9]{2}$/D', $value)
            || substr($value, 0, 4) < '0001' || substr($value, 0, 4) > '9998') {
            throw new InvalidArgumentException('Informe datas válidas no formato AAAA-MM-DD.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
        if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('Data inexistente no calendário.');
        return $date;
    }

    /** A half-open UTC interval keeps the timestamp index usable, including across DST. */
    public function selection(): array
    {
        if ($this->from === null) return ['TRUE', []];
        return ['p.played_at >= ?::timestamptz AND p.played_at < ?::timestamptz', [self::utc($this->from), self::utc($this->until)]];
    }

    public static function utc(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }

    public function describe(): array
    {
        return ['name' => $this->name, 'from' => $this->from?->format('Y-m-d'),
            'to' => $this->until?->modify('-1 day')->format('Y-m-d'), 'limit' => $this->limit];
    }
}
