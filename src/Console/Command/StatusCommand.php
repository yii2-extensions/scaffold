<?php

declare(strict_types=1);

namespace yii\scaffold\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use yii\scaffold\Console\SymfonyOutputWriter;
use yii\scaffold\Services\StatusService;

/**
 * Displays the status of all scaffold-tracked files relative to their recorded hashes.
 *
 * Usage example:
 * ```bash
 * vendor/bin/scaffold status
 * ```
 */
#[AsCommand(
    name: 'status',
    description: 'Displays the status of all scaffold-tracked files relative to their recorded hashes.',
)]
final class StatusCommand extends AbstractScaffoldCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = $this->resolveProjectRoot($output);

        if ($projectRoot === null) {
            return Command::FAILURE;
        }

        return (new StatusService())->run($projectRoot, new SymfonyOutputWriter($output));
    }
}
