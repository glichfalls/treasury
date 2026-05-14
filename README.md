# Treasury

Personal net worth tracker. Imports broker exports, fetches market prices from Yahoo, draws charts.

Single user, self-hosted, runs in Docker.

## What it does

Accounts hold transactions. Transactions can be entered by hand or imported from:

- Degiro Trades export (CSV)
- Degiro Account Statement / Kontoauszug (CSV — the richer one with dividends, fees, FX legs)
- Interactive Brokers Statement of Funds (Flex Query, CSV)

Drop a CSV on the upload zone of any account. The format is detected from the headers.

Holdings are derived from the transaction stream (sum of quantities per ISIN). Prices and FX rates come from Yahoo Finance, refreshed daily, with a backfill command for full history.

The frontend has four charts: net worth over time, per-account value, allocation donut, per-asset price history.

## Stack

PHP 8.4 / Symfony 7, Vue 3 + Vite + Pinia + Tailwind v4 + ECharts, MySQL 8.4. All in Docker.

## Setup

You need Docker. Nothing else.

```
docker compose up -d --build
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php php bin/console app:user:create you@example.com yourpassword
```

Open <http://127.0.0.1:5173> and log in.

The first start takes a few minutes (image pulls, composer install, npm install). Subsequent starts are seconds.

Ports:

- `5173` — Vite (the URL you actually use)
- `8000` — Symfony backend
- `33306` — MySQL on the host (not `3306`, to leave that free for a system-installed MySQL)

## Importing

For IBKR, set up a Flex Query in Client Portal under Performance & Reports. One section: "Statement of Funds", CSV format. Include the standard fields — `TransactionID`, `Date`, `ActivityCode`, `Symbol`, `ISIN`, `Amount`, `CurrencyPrimary`, `TradeQuantity`, `LevelOfDetail`.

Re-imports are idempotent. Duplicate rows are detected by content hash (or by `TransactionID` for IBKR) and skipped. Multi-fill orders with identical-looking rows are handled by an occurrence counter so each fill becomes its own transaction.

After each import the system fetches latest prices and FX for any new assets. For the full historical series:

```
docker compose exec php php bin/console app:prices:backfill --range=max
```

## Common commands

```
docker compose exec php php bin/console <cmd>             # Symfony console
docker compose exec -e APP_ENV=test php php bin/phpunit   # PHPUnit
docker compose exec database mysql -uroot -p'!ChangeMe!' app
docker compose logs -f php
```

The `APP_ENV=test` override on phpunit is needed because compose sets `APP_ENV=dev` on the php service.

## Quirks

Yahoo Finance is unofficial and can break. The provider sits behind `App\Price\PriceProvider`, so swapping to another source is a one-class change.

For London listings (`.L` tickers), Yahoo returns prices in pence. The provider rescales them to GBP.

If Yahoo has no price for an asset on a given historical date, that asset contributes 0 for that date instead of guessing. The line picks up as price coverage grows.

All IDs are UUIDv7. Money is stored as bigint minor units throughout — no floats in the money path.

## Layout

```
src/
  Controller/Api/   JSON endpoints
  Entity/           Doctrine entities
  Import/           CSV importers + ImportService
  Price/            Yahoo provider + PriceFetcher
  Holdings/         Derived positions
  TimeSeries/       Chart series computation
frontend/
  src/components/   Charts, forms, dropzone
  src/views/        Login, home, account
docker/             Container definitions
samples/            (gitignored) your CSV exports
tests/              PHPUnit
```
