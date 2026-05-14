<?php

namespace App\Tests\Import;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\Transaction;
use App\Entity\TransactionSource;
use App\Entity\TransactionType;
use App\Entity\User;
use App\Import\ImportService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ImportServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ImportService $service;
    private Account $degiroAccount;
    private Account $ibkrAccount;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->service = $container->get(ImportService::class);

        $this->resetSchema();
        [$this->degiroAccount, $this->ibkrAccount] = $this->seedAccounts(
            $container->get(UserPasswordHasherInterface::class)
        );
    }

    public function testImportDegiroFixture(): void
    {
        $path = dirname(__DIR__) . '/Fixtures/degiro_small.csv';
        $result = $this->service->importFromFile($this->degiroAccount, $path);

        $this->assertSame('degiro_trades', $result->importer);
        $this->assertSame(3, $result->imported);
        $this->assertSame(0, $result->skipped);
        $this->assertSame([], $result->errors);

        $rows = $this->em->getRepository(Transaction::class)->findBy(['account' => $this->degiroAccount]);
        $this->assertCount(3, $rows);

        $sell = array_filter($rows, fn(Transaction $t) => $t->getType() === TransactionType::TradeSell);
        $this->assertCount(1, $sell);
        $this->assertSame('495970', reset($sell)->getAmountMinor());
        $this->assertSame('-75', reset($sell)->getAssetQuantity());
        $this->assertSame('US7731211089', reset($sell)->getAssetIsin());
        $this->assertSame(TransactionSource::Import, reset($sell)->getSource());

        // Asset upserted by ISIN.
        $rocket = $this->em->getRepository(\App\Entity\Asset::class)->findOneBy(['isin' => 'US7731211089']);
        $this->assertNotNull($rocket);
        $this->assertSame('ROCKET LAB CORP', $rocket->getName());
    }

    public function testImportIbkrFixture(): void
    {
        $path = dirname(__DIR__) . '/Fixtures/ibkr_small.csv';
        $result = $this->service->importFromFile($this->ibkrAccount, $path);

        $this->assertSame('ibkr', $result->importer);
        $this->assertSame(4, $result->imported);
        $this->assertSame(0, $result->skipped);

        $rows = $this->em->getRepository(Transaction::class)->findBy(['account' => $this->ibkrAccount]);
        $types = array_map(fn(Transaction $t) => $t->getType(), $rows);
        $this->assertContains(TransactionType::Deposit, $types);
        $this->assertContains(TransactionType::TradeBuy, $types);
        $this->assertContains(TransactionType::TradeSell, $types);
        $this->assertContains(TransactionType::Dividend, $types);
    }

    public function testReimportSkipsAllRows(): void
    {
        $path = dirname(__DIR__) . '/Fixtures/ibkr_small.csv';
        $first = $this->service->importFromFile($this->ibkrAccount, $path);
        $this->assertSame(4, $first->imported);

        $second = $this->service->importFromFile($this->ibkrAccount, $path);
        $this->assertSame(0, $second->imported);
        $this->assertSame(4, $second->skipped);
        $this->assertSame([], $second->errors);

        // DB still has exactly 4 rows.
        $this->assertCount(4, $this->em->getRepository(Transaction::class)->findBy(['account' => $this->ibkrAccount]));
    }

    public function testOverlappingFilesOnlyImportNewRows(): void
    {
        // First import the small fixture, then re-import the same file with one extra row appended.
        $base = dirname(__DIR__) . '/Fixtures/ibkr_small.csv';
        $this->service->importFromFile($this->ibkrAccount, $base);

        $extraRow = '"U1","","","CHF","1","","","","","","","","","","","","","","","","","","0","","","","","20240501","20240501","20240501","DEP","Top-up","","","","","0","0","0","0","0","","500","500","","1641.43","BaseCurrency","27999999999","","","","","","","0.0","0.0"';
        $merged = tempnam(sys_get_temp_dir(), 'ibkr');
        file_put_contents($merged, file_get_contents($base) . $extraRow . "\n");

        try {
            $result = $this->service->importFromFile($this->ibkrAccount, $merged);
            $this->assertSame(1, $result->imported);
            $this->assertSame(4, $result->skipped);
            $this->assertCount(5, $this->em->getRepository(Transaction::class)->findBy(['account' => $this->ibkrAccount]));
        } finally {
            @unlink($merged);
        }
    }

    public function testUnknownFormatReturnsError(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($tmp, "Foo,Bar,Baz\n1,2,3\n");

        try {
            $result = $this->service->importFromFile($this->degiroAccount, $tmp);
            $this->assertSame(0, $result->imported);
            $this->assertSame(0, $result->skipped);
            $this->assertNotEmpty($result->errors);
            $this->assertStringContainsString('Unrecognized', $result->errors[0]);
        } finally {
            @unlink($tmp);
        }
    }

    private function resetSchema(): void
    {
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    /** @return array{0: Account, 1: Account} */
    private function seedAccounts(UserPasswordHasherInterface $hasher): array
    {
        $user = new User();
        $user->setEmail('test@example.com');
        $user->setPassword($hasher->hashPassword($user, 'pw'));
        $this->em->persist($user);

        $degiro = new Account();
        $degiro->setOwner($user);
        $degiro->setName('Degiro');
        $degiro->setType(AccountType::Brokerage);
        $degiro->setCurrency('CHF');

        $ibkr = new Account();
        $ibkr->setOwner($user);
        $ibkr->setName('IBKR');
        $ibkr->setType(AccountType::Brokerage);
        $ibkr->setCurrency('CHF');

        $this->em->persist($degiro);
        $this->em->persist($ibkr);
        $this->em->flush();

        return [$degiro, $ibkr];
    }
}
