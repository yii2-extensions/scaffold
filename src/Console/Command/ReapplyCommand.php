<?php

declare(strict_types=1);

namespace yii\scaffold\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use yii\scaffold\Console\SymfonyOutputWriter;
use yii\scaffold\Services\ReapplyService;

use function getcwd;

/**
 * Re-applies scaffold stubs to the project, optionally overwriting user-modified files.
 *
 * Usage example:
 * ```bash
 * vendor/bin/scaffold reapply
 * vendor/bin/scaffold reapply config/params.php
 * vendor/bin/scaffold reapply config/params.php --force
 * vendor/bin/scaffold reapply --provider=yii2-extensions/app-base
 * ```
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[AsCommand(
    name: 'reapply',
    description: 'Re-applies scaffold stubs to the project, optionally overwriting user-modified files.',
)]
final class ReapplyCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument(
                'file',
                InputArgument::OPTIONAL,
                'Destination path to reapply. When empty, all tracked files are processed.',
                '',
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Overwrite user-modified files without prompting.',
            )
            ->addOption(
                'provider',
                null,
                InputOption::VALUE_REQUIRED,
                "Restrict reapply to a provider package name (for example, 'yii2-extensions/app-base').",
                '',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = (string) getcwd();
        $vendorDir = $projectRoot . '/vendor';

        /** @var string $file */
        $file = $input->getArgument('file');

        /** @var string $provider */
        $provider = $input->getOption('provider');

        return (new ReapplyService())->run(
            $projectRoot,
            $vendorDir,
            $file,
            $provider,
            (bool) $input->getOption('force'),
            new SymfonyOutputWriter($output),
        );
    }
}
