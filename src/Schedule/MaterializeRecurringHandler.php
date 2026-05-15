<?php

namespace App\Schedule;

use App\Recurring\RecurringMaterializer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class MaterializeRecurringHandler
{
    public function __construct(
        private readonly RecurringMaterializer $materializer,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(MaterializeRecurringMessage $message): void
    {
        $created = $this->materializer->materializeAll();
        if ($created > 0) {
            $this->logger->info('Recurring materializer created transactions', ['count' => $created]);
        }
    }
}
