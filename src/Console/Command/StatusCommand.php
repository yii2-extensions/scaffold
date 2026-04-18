<?php

declare(strict_types=1);

namespace yii\scaffold\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use yii\scaffold\Console\SymfonyOutputWriter;
use yii\scaffold\Services\StatusService;

use function getcwd;

/**
 * Displays the status of all scaffold-tracked files relative to their recorded hashes.
 *
 * Usage example:
 * ```bash
 * vendor/bin/scaffold status
 * ```
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[AsCommand(
    name: 'status',
    description: 'Displays the status of all scaffold-tracked files relative to their recorded hashes.',
)]
final class StatusCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = (string) getcwd();

        return (new StatusService())->run($projectRoot, new SymfonyOutputWriter($output));
    }
}
