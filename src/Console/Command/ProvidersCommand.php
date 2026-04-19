<?php

declare(strict_types=1);

namespace yii\scaffold\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use yii\scaffold\Console\SymfonyOutputWriter;
use yii\scaffold\Services\ProvidersService;

/**
 * Lists all scaffold providers recorded in `scaffold-lock.json` with their file counts.
 *
 * Usage example:
 * ```bash
 * vendor/bin/scaffold providers
 * ```
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[AsCommand(
    name: 'providers',
    description: "Lists all scaffold providers recorded in 'scaffold-lock.json' with their file counts.",
)]
final class ProvidersCommand extends AbstractScaffoldCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = $this->resolveProjectRoot($output);

        if ($projectRoot === null) {
            return Command::FAILURE;
        }

        return (new ProvidersService())->run($projectRoot, new SymfonyOutputWriter($output));
    }
}
