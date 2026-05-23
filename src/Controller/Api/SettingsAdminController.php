<?php

namespace App\Controller\Api;

use App\Settings\SettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
        ['key' => SettingsService::REDDIT_CLIENT_ID,    'label' => 'Reddit client ID',    'group' => 'Reddit',         'secret' => true],
        ['key' => SettingsService::REDDIT_CLIENT_SECRET, 'label' => 'Reddit client secret', 'group' => 'Reddit',        'secret' => true],
        ['key' => SettingsService::OPENAI_API_KEY,     'label' => 'OpenAI API key',      'group' => 'AI',             'secret' => true],
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly HttpClientInterface $http,
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

    /**
     * Probe the provider with the stored key so the admin gets immediate
     * feedback on whether it's valid, without leaving the settings page.
     */
    #[Route('/{key}/test', name: 'api_admin_settings_test', methods: ['POST'])]
    public function test(string $key): JsonResponse
    {
        if (!in_array($key, array_column(self::KNOWN, 'key'), true)) {
            throw new NotFoundHttpException();
        }
        $value = $this->settings->get($key);
        if ($value === null) {
            return new JsonResponse(['ok' => false, 'message' => 'No key saved yet — save one first.']);
        }
        return new JsonResponse($this->probe($key, $value));
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function probe(string $key, string $value): array
    {
        // Reddit needs both halves of the credential, so test them together.
        if ($key === SettingsService::REDDIT_CLIENT_ID || $key === SettingsService::REDDIT_CLIENT_SECRET) {
            return $this->probeReddit();
        }

        [$url, $options] = match ($key) {
            SettingsService::OPENAI_API_KEY => [
                'https://api.openai.com/v1/models',
                ['headers' => ['Authorization' => 'Bearer ' . $value]],
            ],
            SettingsService::FINNHUB_API_KEY => [
                'https://finnhub.io/api/v1/quote',
                ['query' => ['symbol' => 'AAPL', 'token' => $value]],
            ],
            SettingsService::MARKETAUX_API_TOKEN => [
                'https://api.marketaux.com/v1/news/all',
                ['query' => ['symbols' => 'AAPL', 'limit' => 1, 'api_token' => $value]],
            ],
            default => [null, []],
        };
        if ($url === null) {
            return ['ok' => false, 'message' => 'No connection test available for this setting.'];
        }

        try {
            // getStatusCode() does not throw on 4xx/5xx, so we can branch on it.
            $status = $this->http->request('GET', $url, $options + ['timeout' => 10])->getStatusCode();
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Request failed: ' . $e->getMessage()];
        }

        return match (true) {
            $status >= 200 && $status < 300 => ['ok' => true, 'message' => 'Connection successful.'],
            in_array($status, [401, 403], true) => ['ok' => false, 'message' => 'Authentication failed — the key looks invalid.'],
            $status === 402 => ['ok' => false, 'message' => 'Payment/plan limit required for this key.'],
            $status === 429 => ['ok' => true, 'message' => 'Key accepted but currently rate-limited.'],
            default => ['ok' => false, 'message' => "Provider returned HTTP {$status}."],
        };
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function probeReddit(): array
    {
        $id = $this->settings->get(SettingsService::REDDIT_CLIENT_ID);
        $secret = $this->settings->get(SettingsService::REDDIT_CLIENT_SECRET);
        if ($id === null || $secret === null) {
            return ['ok' => false, 'message' => 'Save both the Reddit client ID and secret first.'];
        }
        try {
            $status = $this->http->request('POST', 'https://www.reddit.com/api/v1/access_token', [
                'auth_basic' => [$id, $secret],
                'headers' => ['User-Agent' => 'treasury-news/1.0'],
                'body' => ['grant_type' => 'client_credentials'],
                'timeout' => 10,
            ])->getStatusCode();
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Request failed: ' . $e->getMessage()];
        }
        return match (true) {
            $status >= 200 && $status < 300 => ['ok' => true, 'message' => 'Reddit credentials valid.'],
            in_array($status, [401, 403], true) => ['ok' => false, 'message' => 'Authentication failed — check client ID/secret.'],
            default => ['ok' => false, 'message' => "Reddit returned HTTP {$status}."],
        };
    }

    /** Show only the last 4 characters so admins can recognise a key without exposing it. */
    private function mask(string $value): string
    {
        $tail = substr($value, -4);
        return strlen($value) <= 4 ? str_repeat('•', strlen($value)) : '••••' . $tail;
    }
}
