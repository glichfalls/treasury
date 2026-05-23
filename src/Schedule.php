<?php

namespace App;

use App\Schedule\MaterializeRecurringMessage;
use App\Schedule\RefreshNewsMessage;
use App\Schedule\RefreshPricesMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
            ->add(RecurringMessage::cron('30 22 * * *', new RefreshPricesMessage()))
            // Materialize recurring transactions just after midnight so today's
            // expected entries appear on the same calendar day.
            ->add(RecurringMessage::cron('5 0 * * *', new MaterializeRecurringMessage()))
            // Aggregate holdings news hourly, on the hour offset, into news_items.
            ->add(RecurringMessage::cron('15 * * * *', new RefreshNewsMessage()));
    }
}
