<?php

namespace App\Controller\Api;

use App\Settings\SettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/settings')]
#[IsGranted('ROLE_ADMIN')]
class SettingsAdminController extends AbstractController
{
    /**
     * Settings exposed in the admin UI. `secret` keys are never echoed back in
     * full — only a masked hint and a configured flag, so the page is safe to
     * load without leaking credentials.
     *
     * @var list<array{key: string, label: string, group: string, secret: bool}>
     */
    private const KNOWN = [
        ['key' => SettingsService::FINNHUB_API_KEY,    'label' => 'Finnhub API key',     'group' => 'News providers', 'secret' => true],
        ['key' => SettingsService::MARKETAUX_API_TOKEN, 'label' => 'Marketaux API token', 'group' => 'News providers', 'secret' => true],
        ['key' => SettingsService::OPENAI_API_KEY,     'label' => 'OpenAI API key',      'group' => 'AI',             'secret' => true],
    ];

    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    #[Route('', name: 'api_admin_settings_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return new JsonResponse(array_map(function (array $def) {
            $value = $this->settings->get($def['key']);
            return [
                'key' => $def['key'],
                'label' => $def['label'],
                'group' => $def['group'],
                'secret' => $def['secret'],
                'configured' => $value !== null,
                'hint' => $value !== null ? $this->mask($value) : null,
            ];
        }, self::KNOWN));
    }

    #[Route('', name: 'api_admin_settings_update', methods: ['PATCH'])]
    public function update(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($body)) {
            return new JsonResponse(['error' => 'Invalid body'], 422);
        }

        $known = array_column(self::KNOWN, 'key');
        foreach ($body as $key => $value) {
            if (!in_array($key, $known, true)) {
                continue; // ignore unknown keys
            }
            if ($value !== null && !is_string($value)) {
                return new JsonResponse(['error' => "Value for {$key} must be a string or null"], 422);
            }
            // An empty string from the form means "leave unchanged"; null clears.
            if ($value === '') {
                continue;
            }
            $this->settings->set($key, $value);
        }

        return $this->list();
    }

    /** Show only the last 4 characters so admins can recognise a key without exposing it. */
    private function mask(string $value): string
    {
        $tail = substr($value, -4);
        return strlen($value) <= 4 ? str_repeat('•', strlen($value)) : '••••' . $tail;
    }
}
