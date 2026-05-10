<?php

declare(strict_types=1);

namespace App\Console;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Bootstraps a User from the command line. Used to create the first admin
 * on a fresh install before any web-facing signup exists.
 *
 * Refuses to upsert: an email collision is an error, not a silent overwrite.
 */
#[AsCommand(
    name: 'crashler:user:create',
    description: 'Create a new user account (used to bootstrap the first admin)',
)]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'User email (required)')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Password (prompts hidden if omitted and STDIN is a TTY)')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Grant ROLE_ADMIN to the created user');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = (string) $input->getOption('email');
        if ('' === $email) {
            $io->error('Missing required --email option.');

            return Command::FAILURE;
        }

        $password = $input->getOption('password');
        if (null === $password || '' === $password) {
            if (!$input->isInteractive() || !\function_exists('posix_isatty') || !@posix_isatty(\STDIN)) {
                $io->error('--password is required when STDIN is not a TTY.');

                return Command::FAILURE;
            }

            $question = new Question('Password: ');
            $question->setHidden(true);
            $question->setHiddenFallback(false);
            $password = $this->getHelper('question')->ask($input, $output, $question);
            if (null === $password || '' === $password) {
                $io->error('Password may not be empty.');

                return Command::FAILURE;
            }
        }

        if (null !== $this->users->findOneByEmail($email)) {
            $io->error(\sprintf('A user with email "%s" already exists.', $email));

            return Command::FAILURE;
        }

        $user = new User();
        $user->setEmail($email);
        $user->setRoles($input->getOption('admin') ? ['ROLE_ADMIN'] : []);

        $violations = $this->validator->validate($user, null, ['Default']);
        if (\count($violations) > 0) {
            foreach ($violations as $violation) {
                $io->error((string) $violation->getMessage());
            }

            return Command::FAILURE;
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, (string) $password));

        $this->em->persist($user);
        $this->em->flush();

        $io->success(\sprintf(
            'Created user %s with roles: %s',
            $email,
            implode(', ', $user->getRoles()),
        ));

        return Command::SUCCESS;
    }
}
