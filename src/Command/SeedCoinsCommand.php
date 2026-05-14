<?php

namespace App\Command;

use App\Entity\Asset;
use App\Holdings\HoldingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed:coins',
    description: 'Seed the gold-coin catalog: the gold spot reference asset plus common bullion coins',
)]
class SeedCoinsCommand extends Command
{
    /**
     * Each row: [isin, ticker (yahoo), name, currency, unitWeightGrams, premiumPct].
     * The first row is the spot reference; subsequent rows are commodity-backed coins
     * priced relative to it. Premium percentages are typical dealer markups over spot —
     * smaller fractional coins carry higher premiums because of fixed minting cost per
     * piece. Users can tweak them per asset later.
     */
    private const CATALOG = [
        // Yahoo doesn't expose XAUUSD=X via the chart API; GC=F (COMEX gold futures)
        // tracks spot within fractions of a percent and is the most reliable feed.
        [HoldingsService::SPOT_GOLD_ISIN, 'GC=F', 'Gold spot (COMEX GC=F)', 'USD', null, null],

        // Swiss Vreneli (helvetia)
        ['COIN:VRENELI10',     null, 'Swiss Vreneli 10 CHF',     'USD', '2.9032',  '8.0'],
        ['COIN:VRENELI20',     null, 'Swiss Vreneli 20 CHF',     'USD', '5.8060',  '5.0'],

        // Canadian Maple Leaf — full bullion line, .9999 fine
        ['COIN:MAPLE-1OZ',     null, 'Canadian Maple Leaf 1 oz',    'USD', '31.1035', '3.0'],
        ['COIN:MAPLE-1-2OZ',   null, 'Canadian Maple Leaf 1/2 oz',  'USD', '15.5517', '6.0'],
        ['COIN:MAPLE-1-4OZ',   null, 'Canadian Maple Leaf 1/4 oz',  'USD',  '7.7759', '9.0'],
        ['COIN:MAPLE-1-10OZ',  null, 'Canadian Maple Leaf 1/10 oz', 'USD',  '3.1103','14.0'],
        ['COIN:MAPLE-1-20OZ',  null, 'Canadian Maple Leaf 1/20 oz', 'USD',  '1.5552','18.0'],

        // South African Krugerrand
        ['COIN:KRUGER-1OZ',    null, 'Krugerrand 1 oz',     'USD', '31.1035', '3.0'],
        ['COIN:KRUGER-1-2OZ',  null, 'Krugerrand 1/2 oz',   'USD', '15.5517', '6.0'],
        ['COIN:KRUGER-1-4OZ',  null, 'Krugerrand 1/4 oz',   'USD',  '7.7759', '9.0'],
        ['COIN:KRUGER-1-10OZ', null, 'Krugerrand 1/10 oz',  'USD',  '3.1103','14.0'],

        // American Gold Eagle (22-karat, but fine-gold weight is what we care about)
        ['COIN:AGE-1OZ',       null, 'American Gold Eagle 1 oz',    'USD', '31.1035', '4.0'],
        ['COIN:AGE-1-2OZ',     null, 'American Gold Eagle 1/2 oz',  'USD', '15.5517', '7.0'],
        ['COIN:AGE-1-4OZ',     null, 'American Gold Eagle 1/4 oz',  'USD',  '7.7759','10.0'],
        ['COIN:AGE-1-10OZ',    null, 'American Gold Eagle 1/10 oz', 'USD',  '3.1103','15.0'],

        // Austrian Vienna Philharmonic
        ['COIN:PHIL-1OZ',      null, 'Vienna Philharmonic 1 oz',    'USD', '31.1035', '3.0'],
        ['COIN:PHIL-1-2OZ',    null, 'Vienna Philharmonic 1/2 oz',  'USD', '15.5517', '6.0'],
        ['COIN:PHIL-1-4OZ',    null, 'Vienna Philharmonic 1/4 oz',  'USD',  '7.7759', '9.0'],
        ['COIN:PHIL-1-10OZ',   null, 'Vienna Philharmonic 1/10 oz', 'USD',  '3.1103','14.0'],

        // British Britannia
        ['COIN:BRIT-1OZ',      null, 'Britannia 1 oz',     'USD', '31.1035', '4.0'],
        ['COIN:BRIT-1-2OZ',    null, 'Britannia 1/2 oz',   'USD', '15.5517', '7.0'],
        ['COIN:BRIT-1-4OZ',    null, 'Britannia 1/4 oz',   'USD',  '7.7759','10.0'],
        ['COIN:BRIT-1-10OZ',   null, 'Britannia 1/10 oz',  'USD',  '3.1103','15.0'],

        // Raw gold bars / bullion. Premiums approximate typical dealer markup for new
        // bars from major refiners (Argor-Heraeus, Valcambi, PAMP, etc.). For an odd
        // weight (scrap, jewellery, partial bar), pick BAR-1G and set quantity = grams.
        ['BAR:GOLD-1G',        null, 'Gold bar — 1 g',     'USD',    '1.0000', '6.0'],
        ['BAR:GOLD-5G',        null, 'Gold bar — 5 g',     'USD',    '5.0000', '4.0'],
        ['BAR:GOLD-10G',       null, 'Gold bar — 10 g',    'USD',   '10.0000', '3.0'],
        ['BAR:GOLD-20G',       null, 'Gold bar — 20 g',    'USD',   '20.0000', '2.5'],
        ['BAR:GOLD-1OZ',       null, 'Gold bar — 1 oz',    'USD',   '31.1035', '2.0'],
        ['BAR:GOLD-50G',       null, 'Gold bar — 50 g',    'USD',   '50.0000', '1.8'],
        ['BAR:GOLD-100G',      null, 'Gold bar — 100 g',   'USD',  '100.0000', '1.5'],
        ['BAR:GOLD-250G',      null, 'Gold bar — 250 g',   'USD',  '250.0000', '1.2'],
        ['BAR:GOLD-1KG',       null, 'Gold bar — 1 kg',    'USD', '1000.0000', '0.8'],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $repo = $this->em->getRepository(Asset::class);

        $created = 0;
        foreach (self::CATALOG as [$isin, $ticker, $name, $currency, $grams, $premium]) {
            if ($repo->findOneBy(['isin' => $isin]) !== null) {
                continue;
            }
            $asset = (new Asset())
                ->setIsin($isin)
                ->setTicker($ticker)
                ->setName($name)
                ->setCurrency($currency)
                ->setUnitWeightGrams($grams)
                ->setPricePremiumPct($premium);
            $this->em->persist($asset);
            $created++;
        }
        $this->em->flush();

        $io->success(sprintf('Coin catalog: %d new entries, %d already present.', $created, count(self::CATALOG) - $created));
        return Command::SUCCESS;
    }
}
