<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:user:change-password', description: 'Change a user password')]
class ChangePasswordCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED)
            ->addArgument('password', InputArgument::OPTIONAL, 'Omit to be prompted (hidden input)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = $input->getArgument('email');

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($user === null) {
            $io->error(sprintf('User %s not found', $email));
            return Command::FAILURE;
        }

        $password = $input->getArgument('password');
        if ($password === null) {
            $question = (new Question('New password: '))->setHidden(true)->setHiddenFallback(false);
            $password = $io->askQuestion($question);
            if ($password === null || $password === '') {
                $io->error('Password cannot be empty');
                return Command::FAILURE;
            }
        }

        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->flush();

        $io->success(sprintf('Password changed for %s', $email));
        return Command::SUCCESS;
    }
}
