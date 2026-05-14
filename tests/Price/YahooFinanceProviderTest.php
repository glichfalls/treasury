<?php

namespace App\Tests\Price;

use App\Price\YahooFinanceProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class YahooFinanceProviderTest extends TestCase
{
    public function testResolveTickerByIsin(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode([
                'quotes' => [
                    ['symbol' => 'AAPL', 'shortname' => 'Apple Inc'],
                    ['symbol' => 'AAPL.MX'],
                ],
            ])),
        ]);

        $ticker = (new YahooFinanceProvider($http))->resolveTickerByIsin('US0378331005');
        $this->assertSame('AAPL', $ticker);
    }

    public function testResolveTickerReturnsNullWhenNoQuotes(): void
    {
        $http = new MockHttpClient([new MockResponse(json_encode(['quotes' => []]))]);
        $this->assertNull((new YahooFinanceProvider($http))->resolveTickerByIsin('XX0000000000'));
    }

    public function testResolveTickerReturnsNullOnHttpError(): void
    {
        $http = new MockHttpClient([new MockResponse('boom', ['http_code' => 500])]);
        $this->assertNull((new YahooFinanceProvider($http))->resolveTickerByIsin('US0378331005'));
    }

    public function testFetchLatestPrice(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode([
                'chart' => [
                    'result' => [[
                        'meta' => [
                            'symbol' => 'AAPL',
                            'currency' => 'USD',
                            'regularMarketPrice' => 234.56,
                            'regularMarketTime' => 1715000000,
                        ],
                    ]],
                ],
            ])),
        ]);

        $quote = (new YahooFinanceProvider($http))->fetchLatestPrice('AAPL');
        $this->assertNotNull($quote);
        $this->assertSame(234.56, $quote->price);
        $this->assertSame('USD', $quote->currency);
        $this->assertSame('AAPL', $quote->resolvedTicker);
    }

    public function testFetchLatestPriceMissingMeta(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['chart' => ['result' => [[]]]])),
        ]);
        $this->assertNull((new YahooFinanceProvider($http))->fetchLatestPrice('AAPL'));
    }

    public function testFxRateSameCurrency(): void
    {
        $http = new MockHttpClient([]); // No HTTP call expected.
        $this->assertSame(1.0, (new YahooFinanceProvider($http))->fetchLatestFx('CHF', 'CHF'));
    }

    public function testFxRateCrossCurrency(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode([
                'chart' => [
                    'result' => [[
                        'meta' => ['regularMarketPrice' => 0.8821],
                    ]],
                ],
            ])),
        ]);

        $this->assertSame(0.8821, (new YahooFinanceProvider($http))->fetchLatestFx('USD', 'CHF'));
    }

    public function testFxRateReturnsNullOnError(): void
    {
        $http = new MockHttpClient([new MockResponse('', ['http_code' => 502])]);
        $this->assertNull((new YahooFinanceProvider($http))->fetchLatestFx('USD', 'CHF'));
    }

    public function testFetchPriceHistory(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode([
                'chart' => [
                    'result' => [[
                        'meta' => ['symbol' => 'AAPL', 'currency' => 'USD'],
                        'timestamp' => [1710000000, 1710086400, 1710172800],
                        'indicators' => [
                            'quote' => [[
                                'close' => [170.00, null, 172.50],
                            ]],
                        ],
                    ]],
                ],
            ])),
        ]);

        $history = (new YahooFinanceProvider($http))->fetchPriceHistory('AAPL', '1y');

        // null close (non-trading day) skipped → 2 entries returned.
        $this->assertCount(2, $history);
        $this->assertSame(170.00, $history[0]->price);
        $this->assertSame('USD', $history[0]->currency);
        $this->assertSame(172.50, $history[1]->price);
    }

    public function testFetchPriceHistoryEmptyOnMalformed(): void
    {
        $http = new MockHttpClient([new MockResponse(json_encode(['chart' => ['result' => [[]]]]))]);
        $this->assertSame([], (new YahooFinanceProvider($http))->fetchPriceHistory('AAPL'));
    }

    public function testFetchFxHistory(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode([
                'chart' => [
                    'result' => [[
                        'meta' => [],
                        'timestamp' => [1710000000, 1710086400],
                        'indicators' => [
                            'quote' => [[
                                'close' => [0.88, 0.881],
                            ]],
                        ],
                    ]],
                ],
            ])),
        ]);

        $history = (new YahooFinanceProvider($http))->fetchFxHistory('USD', 'CHF', '1y');

        $this->assertCount(2, $history);
        $this->assertSame(0.88, $history[0]['rate']);
        $this->assertInstanceOf(\DateTimeImmutable::class, $history[0]['date']);
    }

    public function testFetchFxHistoryEmptyWhenSameCurrency(): void
    {
        $http = new MockHttpClient([]); // No HTTP call expected.
        $this->assertSame([], (new YahooFinanceProvider($http))->fetchFxHistory('CHF', 'CHF'));
    }
}
