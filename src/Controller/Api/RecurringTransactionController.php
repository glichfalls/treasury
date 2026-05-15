<?php

namespace App\Controller\Api;

use App\Entity\RecurringFrequency;
use App\Entity\RecurringTransaction;
use App\Entity\TransactionCategory;
use App\Entity\TransactionType;
use App\Entity\User;
use App\Recurring\RecurringMaterializer;
use App\Repository\AccountRepository;
use App\Repository\RecurringTransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

class RecurringTransactionController extends AbstractController
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly RecurringTransactionRepository $rules,
        private readonly RecurringMaterializer $materializer,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/api/accounts/{accountId}/recurring', name: 'api_recurring_list', methods: ['GET'])]
    public function list(string $accountId, #[CurrentUser] User $user): JsonResponse
    {
        $account = $this->accounts->findOneOwnedBy($accountId, $user);
        if ($account === null) {
            throw new NotFoundHttpException();
        }
        return new JsonResponse(array_map(
            fn(RecurringTransaction $r) => $this->serialize($r),
            $this->rules->findByAccount($account),
        ));
    }

    #[Route('/api/accounts/{accountId}/recurring', name: 'api_recurring_create', methods: ['POST'])]
    public function create(string $accountId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $account = $this->accounts->findOneOwnedBy($accountId, $user);
        if ($account === null) {
            throw new NotFoundHttpException();
        }

        $body = $this->parseBody($request);
        $error = $this->validatePayload($body);
        if ($error !== null) {
            return new JsonResponse(['error' => $error], 422);
        }

        $rule = new RecurringTransaction();
        $rule->setAccount($account);
        $rule->setCurrency($account->getCurrency());
        $this->applyPayload($rule, $body);

        $this->em->persist($rule);
        $this->em->flush();

        return new JsonResponse($this->serialize($rule), 201);
    }

    #[Route('/api/recurring/{id}', name: 'api_recurring_update', methods: ['PATCH'])]
    public function update(string $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $rule = $this->resolveOwned($id, $user);

        $body = $this->parseBody($request);
        $error = $this->validatePayload($body, partial: true);
        if ($error !== null) {
            return new JsonResponse(['error' => $error], 422);
        }
        $this->applyPayload($rule, $body, partial: true);

        $this->em->flush();
        return new JsonResponse($this->serialize($rule));
    }

    #[Route('/api/recurring/{id}', name: 'api_recurring_delete', methods: ['DELETE'])]
    public function delete(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $rule = $this->resolveOwned($id, $user);
        $this->em->remove($rule);
        $this->em->flush();
        return new JsonResponse(null, 204);
    }

    #[Route('/api/recurring/{id}/run', name: 'api_recurring_run', methods: ['POST'])]
    public function run(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $rule = $this->resolveOwned($id, $user);
        $count = $this->materializer->materializeOne($rule);
        return new JsonResponse(['created' => $count, 'rule' => $this->serialize($rule)]);
    }

    private function resolveOwned(string $id, User $user): RecurringTransaction
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException();
        }
        $rule = $this->em->find(RecurringTransaction::class, $uuid);
        if ($rule === null || !$rule->getAccount()->getOwner()->getId()->equals($user->getId())) {
            throw new NotFoundHttpException();
        }
        return $rule;
    }

    private function parseBody(Request $request): array
    {
        try {
            $body = json_decode($request->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        return is_array($body) ? $body : [];
    }

    /** Returns an error string or null. */
    private function validatePayload(array $body, bool $partial = false): ?string
    {
        $check = function (string $key) use ($body, $partial): bool {
            return $partial ? array_key_exists($key, $body) : true;
        };

        if (!$partial || $check('description')) {
            $d = trim((string) ($body['description'] ?? ''));
            if ($d === '') return 'description is required';
        }
        if (!$partial || $check('amountMinor')) {
            if (!isset($body['amountMinor']) || !preg_match('/^-?\d+$/', (string) $body['amountMinor'])) {
                return 'amountMinor must be an integer string';
            }
        }
        if (!$partial || $check('frequency')) {
            if (!isset($body['frequency']) || RecurringFrequency::tryFrom((string) $body['frequency']) === null) {
                return 'Invalid frequency';
            }
        }
        if ($check('type') && isset($body['type']) && TransactionType::tryFrom((string) $body['type']) === null) {
            return 'Invalid type';
        }
        if ($check('category') && !empty($body['category']) && TransactionCategory::tryFrom((string) $body['category']) === null) {
            return 'Invalid category';
        }
        return null;
    }

    private function applyPayload(RecurringTransaction $rule, array $body, bool $partial = false): void
    {
        if (!$partial || array_key_exists('description', $body)) {
            $rule->setDescription(trim((string) $body['description']));
        }
        if (!$partial || array_key_exists('amountMinor', $body)) {
            $rule->setAmountMinor((string) $body['amountMinor']);
        }
        if (array_key_exists('currency', $body) && is_string($body['currency']) && preg_match('/^[A-Za-z]{3}$/', $body['currency'])) {
            $rule->setCurrency($body['currency']);
        }
        if (!$partial || array_key_exists('frequency', $body)) {
            $rule->setFrequency(RecurringFrequency::from((string) $body['frequency']));
        }
        if (array_key_exists('dayOfMonth', $body)) {
            $rule->setDayOfMonth($body['dayOfMonth'] === null ? null : max(1, min(31, (int) $body['dayOfMonth'])));
        }
        if (array_key_exists('dayOfWeek', $body)) {
            $rule->setDayOfWeek($body['dayOfWeek'] === null ? null : max(1, min(7, (int) $body['dayOfWeek'])));
        }
        if (array_key_exists('monthOfYear', $body)) {
            $rule->setMonthOfYear($body['monthOfYear'] === null ? null : max(1, min(12, (int) $body['monthOfYear'])));
        }
        if (array_key_exists('startsAt', $body) && $body['startsAt']) {
            $rule->setStartsAt(new \DateTimeImmutable((string) $body['startsAt']));
        }
        if (array_key_exists('endsAt', $body)) {
            $rule->setEndsAt($body['endsAt'] ? new \DateTimeImmutable((string) $body['endsAt']) : null);
        }
        if (array_key_exists('active', $body) && is_bool($body['active'])) {
            $rule->setActive($body['active']);
        }
        if (array_key_exists('type', $body) && isset($body['type'])) {
            $rule->setType(TransactionType::from((string) $body['type']));
        }
        if (array_key_exists('category', $body)) {
            $rule->setCategory(empty($body['category']) ? null : TransactionCategory::from((string) $body['category']));
        }
    }

    private function serialize(RecurringTransaction $r): array
    {
        $next = $r->isActive()
            ? $r->nextOccurrenceAfter($r->getLastGeneratedAt() ?? (new \DateTimeImmutable('yesterday')))
            : null;

        return [
            'id' => $r->getId()->toRfc4122(),
            'accountId' => $r->getAccount()->getId()->toRfc4122(),
            'description' => $r->getDescription(),
            'amountMinor' => $r->getAmountMinor(),
            'currency' => $r->getCurrency(),
            'type' => $r->getType()->value,
            'category' => $r->getCategory()?->value,
            'frequency' => $r->getFrequency()->value,
            'dayOfMonth' => $r->getDayOfMonth(),
            'dayOfWeek' => $r->getDayOfWeek(),
            'monthOfYear' => $r->getMonthOfYear(),
            'startsAt' => $r->getStartsAt()->format('Y-m-d'),
            'endsAt' => $r->getEndsAt()?->format('Y-m-d'),
            'active' => $r->isActive(),
            'lastGeneratedAt' => $r->getLastGeneratedAt()?->format('Y-m-d'),
            'nextOccurrenceAt' => $next?->format('Y-m-d'),
        ];
    }
}
