<?php declare(strict_types=1);

namespace App\User\Infrastructure\Console;

use App\User\Domain\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Grants a user back-office (ROLE_ADMIN) access by e-mail. Admin is not
 * self-service — it is provisioned from the CLI.
 */
#[AsCommand(name: 'user:promote-admin', description: 'Grant ROLE_ADMIN to a user by e-mail.')]
final class PromoteAdminCommand extends Command
{
    public function __construct(private readonly UserRepository $users)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'E-mail of the user to promote');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');

        $user = $this->users->byEmail($email);
        if (null === $user) {
            $io->error(sprintf('No user with e-mail "%s".', $email));

            return Command::FAILURE;
        }

        $user->promoteToAdmin();
        $this->users->add($user);

        $io->success(sprintf('%s is now an administrator.', $email));

        return Command::SUCCESS;
    }
}
