<?php

namespace Atwx\ProjectInfo\Tasks;

use SilverStripe\Core\Environment;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

class ImportLivedataTask extends BuildTask
{
    protected string $title = 'Import Livedata';

    protected static string $description = 'Import the latest _livedata/ DB dump and assets into the local environment.';

    protected static string $commandName = 'import-livedata';

    #[\Override]
    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $livedataDir = BASE_PATH . '/_livedata';

        if (!is_dir($livedataDir)) {
            $output->writeln('<error>No _livedata/ directory found. Run tasks:pull-livedata first.</error>');
            return Command::FAILURE;
        }

        $dbResult = $this->importDatabase($livedataDir, $output);
        if ($dbResult !== Command::SUCCESS) {
            return $dbResult;
        }

        $this->importAssets($livedataDir, $output);

        $output->writeln('<info>Import complete. Run sake db:build --flush if needed.</info>');
        return Command::SUCCESS;
    }

    private function importDatabase(string $livedataDir, PolyOutput $output): int
    {
        $dbDir = $livedataDir . '/db';

        if (!is_dir($dbDir)) {
            $output->writeln('<error>No _livedata/db/ directory found.</error>');
            return Command::FAILURE;
        }

        // Find the most recent dump
        $dumps = glob($dbDir . '/*.sql');
        if (empty($dumps)) {
            $output->writeln('<error>No SQL dump found in _livedata/db/.</error>');
            return Command::FAILURE;
        }

        usort($dumps, fn($a, $b) => filemtime($b) - filemtime($a));
        $dumpFile = $dumps[0];
        $output->writeln('Importing database from ' . basename($dumpFile) . '...');

        $host = Environment::getEnv('SS_DATABASE_SERVER') ?: 'localhost';
        $user = Environment::getEnv('SS_DATABASE_USERNAME');
        $pass = Environment::getEnv('SS_DATABASE_PASSWORD');
        $name = Environment::getEnv('SS_DATABASE_NAME');

        if (!$user || !$name) {
            $output->writeln('<error>Database credentials not found in environment.</error>');
            return Command::FAILURE;
        }

        $passArg = $pass ? '-p' . escapeshellarg($pass) : '';
        $cmd = sprintf(
            'mysql -h %s -u %s %s %s < %s 2>&1',
            escapeshellarg($host),
            escapeshellarg($user),
            $passArg,
            escapeshellarg($name),
            escapeshellarg($dumpFile)
        );

        exec($cmd, $cmdOutput, $exitCode);

        if ($exitCode !== 0) {
            $output->writeln('<error>Database import failed:</error>');
            foreach ($cmdOutput as $line) {
                $output->writeln("  $line");
            }
            return Command::FAILURE;
        }

        $output->writeln('Database imported successfully.');
        return Command::SUCCESS;
    }

    private function importAssets(string $livedataDir, PolyOutput $output): void
    {
        $sourceDir = $livedataDir . '/assets';

        if (!is_dir($sourceDir)) {
            $output->writeln('No _livedata/assets/ directory found, skipping assets.');
            return;
        }

        $targetDir = ASSETS_PATH;
        $output->writeln("Copying assets to $targetDir...");

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $copied = 0;
        $skipped = 0;

        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($sourceDir) + 1);
            $targetPath = $targetDir . '/' . $relativePath;

            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
                continue;
            }

            // Skip if identical
            if (is_file($targetPath) && md5_file($item->getPathname()) === md5_file($targetPath)) {
                $skipped++;
                continue;
            }

            $targetFileDir = dirname($targetPath);
            if (!is_dir($targetFileDir)) {
                mkdir($targetFileDir, 0755, true);
            }

            copy($item->getPathname(), $targetPath);
            $copied++;
        }

        $output->writeln("Assets: $copied copied, $skipped skipped (unchanged).");
    }
}
