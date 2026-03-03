<?php

namespace Atwx\ProjectInfo\Tasks;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

class PullLiveTask extends BuildTask
{
    protected string $title = 'Pull Live';

    protected static string $description = 'Pull DB + assets from the live server and import them into the local environment.';

    protected static string $commandName = 'pull-live';

    #[\Override]
    public function getOptions(): array
    {
        return [
            new InputOption('token', 't', InputOption::VALUE_OPTIONAL, 'Personal Access Token (default: $ATW_PERSONAL_TOKEN)'),
            new InputOption('url', 'u', InputOption::VALUE_REQUIRED, 'Base URL of the live site (e.g. https://docs.atw.io)'),
            new InputOption('intranet-url', 'i', InputOption::VALUE_OPTIONAL, 'Token API URL', 'https://intra.atw.io/_api/token'),
        ];
    }

    #[\Override]
    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $token = $input->getOption('token') ?: getenv('ATW_PERSONAL_TOKEN');
        $remoteUrl = $input->getOption('url');
        $intranetUrl = $input->getOption('intranet-url') ?: 'https://intra.atw.io/_api/token';

        if (!$token) {
            $output->writeln('<error>No token provided. Pass --token/-t or set ATW_PERSONAL_TOKEN in your shell.</error>');
            return Command::FAILURE;
        }

        if (!$remoteUrl) {
            $output->writeln('<error>--url is required.</error>');
            $output->writeln('Usage: sake tasks:pull-live -u docs.atw.io');
            return Command::FAILURE;
        }

        $remoteUrl = rtrim($remoteUrl, '/');
        if (!str_starts_with($remoteUrl, 'http')) {
            $remoteUrl = 'https://' . $remoteUrl;
        }

        try {
            // --- Pull ---
            $output->writeln('Fetching JWT...');
            $jwt = $this->fetchJwt($token, parse_url($remoteUrl, PHP_URL_HOST), $intranetUrl);

            $output->writeln('Authenticating...');
            $cookies = $this->authenticate($jwt, $remoteUrl);

            $output->writeln('Downloading database dump...');
            $dumpFile = $this->downloadDatabase($remoteUrl, $cookies);
            $output->writeln("Saved to $dumpFile");

            $output->writeln('Fetching asset list...');
            $list = $this->fetchAssetList($remoteUrl, $cookies);
            $output->writeln(count($list) . ' assets on remote.');

            $output->writeln('Syncing assets...');
            $this->syncAssets($remoteUrl, $list, $cookies, $output);

            // --- Import ---
            $output->writeln('Importing database...');
            $this->importDatabase($dumpFile, $output);

            $output->writeln('Copying assets...');
            $this->importAssets($output);

            $output->writeln('<info>Done. Run sake db:build --flush if needed.</info>');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    private function fetchJwt(string $token, string $domain, string $intranetUrl): string
    {
        $client = new Client(['timeout' => 30, 'verify' => false]);
        $response = $client->post($intranetUrl, [
            'form_params' => ['token' => $token, 'domain' => $domain],
        ]);

        $data = json_decode((string) $response->getBody(), true);

        if (!isset($data['jwt'])) {
            throw new \RuntimeException('No JWT in intranet response: ' . (string) $response->getBody());
        }

        return $data['jwt'];
    }

    private function authenticate(string $jwt, string $remoteUrl): CookieJar
    {
        $cookies = new CookieJar();
        $client = new Client(['timeout' => 30, 'allow_redirects' => true, 'cookies' => $cookies]);
        $client->get($remoteUrl . '/_silvergateclient/token/' . urlencode(base64_encode($jwt)));
        return $cookies;
    }

    private function downloadDatabase(string $remoteUrl, CookieJar $cookies): string
    {
        $client = new Client(['timeout' => 120, 'cookies' => $cookies]);
        $response = $client->get($remoteUrl . '/admin/settings/doBackup');

        $dir = BASE_PATH . '/_livedata/db';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file = $dir . '/dump-' . date('Y-m-d') . '.sql';
        file_put_contents($file, (string) $response->getBody());
        return $file;
    }

    private function fetchAssetList(string $remoteUrl, CookieJar $cookies): array
    {
        $client = new Client(['timeout' => 60, 'cookies' => $cookies]);
        $response = $client->get($remoteUrl . '/admin/settings/doListAssets');
        $list = json_decode((string) $response->getBody(), true);

        if (!is_array($list)) {
            throw new \RuntimeException('Unexpected response from doListAssets');
        }

        return $list;
    }

    private function syncAssets(string $remoteUrl, array $list, CookieJar $cookies, PolyOutput $output): void
    {
        $client = new Client(['timeout' => 60, 'cookies' => $cookies]);
        $baseDir = BASE_PATH . '/_livedata/assets';
        $downloaded = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($list as $entry) {
            $relativePath = $entry['path'] ?? '';
            if ($relativePath === '') {
                continue;
            }

            $localPath = $baseDir . '/' . $relativePath;

            if (is_file($localPath) && md5_file($localPath) === ($entry['md5'] ?? '')) {
                $skipped++;
                continue;
            }

            $localDir = dirname($localPath);
            if (!is_dir($localDir)) {
                mkdir($localDir, 0755, true);
            }

            try {
                $response = $client->get($remoteUrl . '/admin/settings/doDownloadAsset', [
                    'query' => ['path' => $relativePath],
                ]);
                file_put_contents($localPath, (string) $response->getBody());
                $downloaded++;
            } catch (\Throwable $e) {
                $output->writeln('<error>  Failed ' . $relativePath . ': ' . $e->getMessage() . '</error>');
                $errors++;
            }
        }

        $output->writeln("$downloaded downloaded, $skipped unchanged, $errors errors.");
    }

    private function importDatabase(string $dumpFile, PolyOutput $output): void
    {
        $host = Environment::getEnv('SS_DATABASE_SERVER') ?: 'localhost';
        $user = Environment::getEnv('SS_DATABASE_USERNAME');
        $pass = Environment::getEnv('SS_DATABASE_PASSWORD');
        $name = Environment::getEnv('SS_DATABASE_NAME');

        if (!$user || !$name) {
            throw new \RuntimeException('Database credentials not found in environment.');
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
            throw new \RuntimeException('Database import failed: ' . implode("\n", $cmdOutput));
        }

        $output->writeln('Database imported from ' . basename($dumpFile) . '.');
    }

    private function importAssets(PolyOutput $output): void
    {
        $sourceDir = BASE_PATH . '/_livedata/assets';
        if (!is_dir($sourceDir)) {
            $output->writeln('No _livedata/assets/ found, skipping.');
            return;
        }

        $targetDir = ASSETS_PATH;
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

        $output->writeln("$copied assets copied, $skipped unchanged.");
    }
}
