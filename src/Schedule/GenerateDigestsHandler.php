<?php

namespace App\Schedule;

use App\News\DigestService;
use App\Repository\UserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class GenerateDigestsHandler
{
    public function __construct(
        private readonly DigestService $digests,
        private readonly UserRepository $users,
    ) {}

    public function __invoke(GenerateDigestsMessage $message): void
    {
        foreach ($this->users->findAll() as $user) {
            $this->digests->generate($user);
        }
    }
}
