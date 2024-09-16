<?php

namespace Jobby;

use Cron\CronExpression;
use DateTime;
use DateTimeImmutable;

class ScheduleChecker
{
    private DateTimeImmutable $now;

    public function __construct(DateTimeImmutable $now = null)
    {
        $this->now = $now instanceof DateTimeImmutable ? $now : new DateTimeImmutable("now");
    }

    public function isDue(callable|string $schedule): bool
    {
        if (is_callable($schedule)) {
            return $schedule($this->now);
        }

        $dateTime = DateTime::createFromFormat('Y-m-d H:i:s', $schedule);
        if ($dateTime !== false) {
            return $dateTime->format('Y-m-d H:i') === $this->now->format('Y-m-d H:i');
        }

        return (new CronExpression((string)$schedule))->isDue($this->now);
    }
}
