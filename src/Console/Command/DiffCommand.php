<?php

declare(strict_types=1);

namespace yii\scaffold\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface};
use Symfony\Component\Console\Output\OutputInterface;
use yii\scaffold\Console\SymfonyOutputWriter;
use yii\scaffold\Services\DiffService;

use function getcwd;

/**
 * Shows a line-by-line diff between the provider stub and the current on-disk file.
 *
 * Usage example:
 * ```bash
 * vendor/bin/scaffold diff config/params.php
 * ```
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[AsCommand(
    name: 'diff',
    description: 'Shows a line-by-line diff between the provider stub and the current on-disk file.',
)]
final class DiffCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument(
            'file',
            InputArgument::REQUIRED,
            "Destination path as recorded in 'scaffold-lock.json'.",
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = (string) getcwd();
        $vendorDir = $projectRoot . '/vendor';

        /** @var string $file */
        $file = $input->getArgument('file');

        return (new DiffService())->run($projectRoot, $vendorDir, $file, new SymfonyOutputWriter($output));
    }
}
