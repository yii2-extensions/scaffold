<?php

declare(strict_types=1);

namespace yii\scaffold\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use yii\scaffold\Console\SymfonyOutputWriter;
use yii\scaffold\Services\EjectService;

use function getcwd;

/**
 * Removes a file entry from `scaffold-lock.json` without deleting the file from disk.
 *
 * Usage example:
 * ```bash
 * vendor/bin/scaffold eject config/params.php --yes
 * ```
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[AsCommand(
    name: 'eject',
    description: "Removes a file entry from 'scaffold-lock.json' without deleting the file from disk.",
)]
final class EjectCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, "Destination path as recorded in 'scaffold-lock.json'.")
            ->addOption('yes', null, InputOption::VALUE_NONE, 'Confirm the ejection without prompting.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = (string) getcwd();

        /** @var string $file */
        $file = $input->getArgument('file');

        return (new EjectService())->run(
            $projectRoot,
            $file,
            (bool) $input->getOption('yes'),
            new SymfonyOutputWriter($output),
        );
    }
}
