<?php

namespace App\Sync;

use App\Entity\Account;
use App\Entity\TransactionType;
use App\Import\ImportResult;
use App\Import\ImportService;
use App\Import\MoneyParser;
use App\Import\TransactionDraft;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class IbkrFlexService
{
    private const SEND_URL = 'https://ndcdyn.interactivebrokers.com/AccountManagement/FlexWebService/SendRequest';
    private const GET_URL  = 'https://ndcdyn.interactivebrokers.com/AccountManagement/FlexWebService/GetStatement';
    private const MAX_RETRIES = 10;
    private const RETRY_SLEEP_S = 2;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly ImportService $importService,
    ) {}

    public function sync(Account $account): ImportResult
    {
        $config = $account->getProviderConfig() ?? [];
        $token = trim((string) ($config['flexToken'] ?? ''));
        $queryId = trim((string) ($config['flexQueryId'] ?? ''));
        if ($token === '' || $queryId === '') {
            return new ImportResult(0, 0, ['flexToken and flexQueryId must be configured on this account.']);
        }
        $accountCode = trim((string) ($config['accountCode'] ?? '')) ?: null;

        $refCode = $this->sendRequest($token, $queryId);
        $xml = $this->fetchStatement($token, $refCode);
        $drafts = $this->parseFlexXml($xml, $accountCode);

        return $this->importService->importDrafts($account, $drafts, 'ibkr-flex');
    }

    private function sendRequest(string $token, string $queryId): string
    {
        $response = $this->http->request('GET', self::SEND_URL, [
            'query' => ['t' => $token, 'q' => $queryId, 'v' => '3'],
            'timeout' => 30,
        ]);

        $xml = simplexml_load_string($response->getContent());
        if ($xml === false) {
            throw new \RuntimeException('IBKR Flex API returned invalid XML');
        }

        $status = (string) ($xml->Status ?? '');
        if ($status !== 'Success') {
            throw new \RuntimeException('IBKR Flex request failed: ' . (string) ($xml->ErrorMessage ?? 'Unknown error'));
        }

        return (string) $xml->ReferenceCode;
    }

    private function fetchStatement(string $token, string $refCode): \SimpleXMLElement
    {
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            sleep(self::RETRY_SLEEP_S);

            $response = $this->http->request('GET', self::GET_URL, [
                'query' => ['t' => $token, 'q' => $refCode, 'v' => '3'],
                'timeout' => 30,
            ]);

            $xml = simplexml_load_string($response->getContent());
            if ($xml === false) {
                throw new \RuntimeException('IBKR Flex API returned invalid XML on fetch');
            }

            // Success — actual flex query data has a FlexStatements child
            if (isset($xml->FlexStatements)) {
                return $xml;
            }

            // Statement generation still in progress (error code 1019) — retry
            $errorCode = (int) ($xml->ErrorCode ?? '0');
            if ($errorCode === 1019) {
                continue;
            }

            throw new \RuntimeException('IBKR Flex fetch failed: ' . (string) ($xml->ErrorMessage ?? 'Unknown error'));
        }

        throw new \RuntimeException('IBKR Flex statement was not ready after ' . self::MAX_RETRIES . ' retries');
    }

    /** @return list<TransactionDraft> */
    private function parseFlexXml(\SimpleXMLElement $xml, ?string $accountCode): array
    {
        $drafts = [];

        foreach ($xml->FlexStatements->FlexStatement ?? [] as $stmt) {
            if ($accountCode !== null && (string) $stmt['accountId'] !== $accountCode) {
                continue;
            }
            foreach ($stmt->Trades->Trade ?? [] as $trade) {
                $draft = $this->parseTrade($trade);
                if ($draft !== null) {
                    $drafts[] = $draft;
                }
            }
            foreach ($stmt->CashTransactions->CashTransaction ?? [] as $ct) {
                $draft = $this->parseCashTransaction($ct);
                if ($draft !== null) {
                    $drafts[] = $draft;
                }
            }
        }

        return $drafts;
    }

    private function parseTrade(\SimpleXMLElement $trade): ?TransactionDraft
    {
        $transactionId = trim((string) ($trade['transactionID'] ?? ''));
        if ($transactionId === '') {
            return null;
        }

        $dateRaw = trim((string) ($trade['tradeDate'] ?? ''));
        try {
            $occurredAt = $this->parseDate($dateRaw);
        } catch (\Throwable) {
            return null;
        }

        $netCash = trim((string) ($trade['netCash'] ?? '0'));
        $currency = strtoupper(trim((string) ($trade['currency'] ?? 'USD')));
        $buySell = strtoupper(trim((string) ($trade['buySell'] ?? '')));
        $type = match ($buySell) {
            'BUY', 'BUY (OPEN)', 'BUY (CLOSE)' => TransactionType::TradeBuy,
            'SELL', 'SELL (OPEN)', 'SELL (CLOSE)' => TransactionType::TradeSell,
            default => TransactionType::Other,
        };

        $isin = $this->extractIsin($trade);
        $ticker = trim((string) ($trade['symbol'] ?? '')) ?: null;
        $assetName = trim((string) ($trade['description'] ?? '')) ?: null;
        $qty = trim((string) ($trade['quantity'] ?? ''));
        $assetQuantity = ($qty === '' || $qty === '0') ? null : $qty;

        return new TransactionDraft(
            occurredAt: $occurredAt,
            amountMinor: MoneyParser::toMinor($netCash, 2),
            currency: $currency,
            type: $type,
            externalRef: 'ibkr:' . $transactionId,
            description: $assetName,
            assetIsin: $isin,
            assetQuantity: $assetQuantity,
            assetTicker: $ticker,
            assetName: $assetName,
        );
    }

    private function parseCashTransaction(\SimpleXMLElement $ct): ?TransactionDraft
    {
        $transactionId = trim((string) ($ct['transactionID'] ?? ''));
        if ($transactionId === '') {
            return null;
        }

        $dateRaw = trim((string) ($ct['dateTime'] ?? $ct['settleDate'] ?? ''));
        try {
            $occurredAt = $this->parseDate($dateRaw);
        } catch (\Throwable) {
            return null;
        }

        $amount = trim((string) ($ct['amount'] ?? '0'));
        $currency = strtoupper(trim((string) ($ct['currency'] ?? 'USD')));
        $ctType = trim((string) ($ct['type'] ?? ''));
        $description = trim((string) ($ct['description'] ?? '')) ?: null;

        $isin = $this->extractIsin($ct);
        $ticker = trim((string) ($ct['symbol'] ?? '')) ?: null;
        $assetName = trim((string) ($ct['description'] ?? '')) ?: null;

        $type = $this->classifyCashTransaction($ctType, $amount);

        return new TransactionDraft(
            occurredAt: $occurredAt,
            amountMinor: MoneyParser::toMinor($amount, 2),
            currency: $currency,
            type: $type,
            externalRef: 'ibkr:' . $transactionId,
            description: $description,
            assetIsin: $isin,
            assetTicker: $isin !== null ? $ticker : null,
            assetName: $isin !== null ? $assetName : null,
        );
    }

    private function classifyCashTransaction(string $ctType, string $amount): TransactionType
    {
        return match (true) {
            str_contains($ctType, 'Dividend') || $ctType === 'Payment In Lieu Of Dividends' => TransactionType::Dividend,
            str_contains($ctType, 'Withholding Tax') => TransactionType::Fee,
            str_contains($ctType, 'Interest') => TransactionType::Interest,
            str_contains($ctType, 'Fee') || str_contains($ctType, 'Commission') => TransactionType::Fee,
            $ctType === 'Deposits/Withdrawals' || $ctType === 'Electronic Fund Transfer' => (float) $amount >= 0
                ? TransactionType::Deposit
                : TransactionType::Withdrawal,
            str_contains($ctType, 'Forex') => TransactionType::FxConversion,
            default => TransactionType::Other,
        };
    }

    private function extractIsin(\SimpleXMLElement $el): ?string
    {
        $isin = trim((string) ($el['isin'] ?? ''));
        if ($isin !== '') {
            return $isin;
        }
        // Some Flex Query configs put ISIN in securityID when securityIDType=ISIN
        if (strtoupper((string) ($el['securityIDType'] ?? '')) === 'ISIN') {
            $sid = trim((string) ($el['securityID'] ?? ''));
            if ($sid !== '') {
                return $sid;
            }
        }
        return null;
    }

    private function parseDate(string $raw): \DateTimeImmutable
    {
        // IBKR formats: "2024-01-15", "2024-01-15;10:30:00", "20240115"
        $clean = str_replace(';', ' ', trim($raw));
        foreach (['Y-m-d H:i:s', 'Y-m-d', 'Ymd'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat('!' . $format, $clean);
            if ($dt !== false) {
                return $dt;
            }
        }
        throw new \InvalidArgumentException("Cannot parse IBKR date: {$raw}");
    }
}
