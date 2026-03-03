<?php

namespace Atwx\ProjectInfo\Tasks;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

class PullLivedataTask extends BuildTask
{
    protected string $title = 'Pull Livedata';

    protected static string $description = 'Pull database dump and assets from the live server into _livedata/.';

    protected static string $commandName = 'pull-livedata';

    #[\Override]
    public function getOptions(): array
    {
        return [
            new InputOption('personal_token', null, InputOption::VALUE_REQUIRED, 'Personal Access Token for intranet.atw.io'),
            new InputOption('remote_url', null, InputOption::VALUE_REQUIRED, 'Base URL of the live site (e.g. https://docs.atw.io)'),
            new InputOption('intranet_url', null, InputOption::VALUE_OPTIONAL, 'Token API URL', 'https://intra.atw.io/_api/token'),
        ];
    }

    #[\Override]
    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $personalToken = $input->getOption('personal_token');
        $remoteUrl = $input->getOption('remote_url');
        $intranetUrl = $input->getOption('intranet_url') ?: 'https://intra.atw.io/_api/token';

        if (!$personalToken || !$remoteUrl) {
            $output->writeln('<error>Error: personal_token and remote_url are required.</error>');
            $output->writeln('Usage: sake dev/tasks/pull-livedata --personal_token=pat_... --remote_url=https://docs.atw.io');
            return Command::FAILURE;
        }

        $remoteUrl = rtrim($remoteUrl, '/');
        // Ensure URL has a scheme so parse_url works correctly
        $remoteUrlWithScheme = str_starts_with($remoteUrl, 'http') ? $remoteUrl : 'https://' . $remoteUrl;
        $remoteUrl = $remoteUrlWithScheme;

        try {
            $output->writeln('Fetching JWT from intranet...');
            $domain = parse_url($remoteUrl, PHP_URL_HOST);
            $jwt = $this->fetchJwt($personalToken, $domain, $intranetUrl);
            $output->writeln('JWT obtained.');

            $output->writeln('Authenticating with remote site...');
            $cookies = $this->authenticate($jwt, $remoteUrl);
            $output->writeln('Session established.');

            $output->writeln('Downloading database dump...');
            $dbFile = $this->downloadDatabase($remoteUrl, $cookies);
            $output->writeln("Database saved to $dbFile");

            $output->writeln('Fetching asset list...');
            $list = $this->fetchAssetList($remoteUrl, $cookies);
            $output->writeln('Found ' . count($list) . ' assets on remote.');

            $output->writeln('Syncing assets...');
            $this->syncAssets($remoteUrl, $list, $cookies, $output);

            $output->writeln('<info>Done.</info>');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    private function fetchJwt(string $personalToken, string $domain, string $intranetUrl): string
    {
        $client = new Client(['timeout' => 30, 'verify' => false]);
        $response = $client->post($intranetUrl, [
            'form_params' => [
                'token' => $personalToken,
                'domain' => $domain,
            ],
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
        $client = new Client([
            'timeout' => 30,
            'allow_redirects' => true,
            'cookies' => $cookies,
        ]);

        $encodedJwt = urlencode(base64_encode($jwt));
        $client->get($remoteUrl . '/_silvergateclient/token/' . $encodedJwt);

        return $cookies;
    }

    private function downloadDatabase(string $remoteUrl, CookieJar $cookies): string
    {
        $client = new Client([
            'timeout' => 120,
            'cookies' => $cookies,
        ]);

        $response = $client->get($remoteUrl . '/admin/settings/doBackup');

        $dir = BASE_PATH . '/_livedata/db';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = $dir . '/dump-' . date('Y-m-d') . '.sql';
        file_put_contents($filename, (string) $response->getBody());

        return $filename;
    }

    private function fetchAssetList(string $remoteUrl, CookieJar $cookies): array
    {
        $client = new Client([
            'timeout' => 60,
            'cookies' => $cookies,
        ]);

        $response = $client->get($remoteUrl . '/admin/settings/doListAssets');
        $list = json_decode((string) $response->getBody(), true);

        if (!is_array($list)) {
            throw new \RuntimeException('Unexpected response from doListAssets');
        }

        return $list;
    }

    private function syncAssets(string $remoteUrl, array $list, CookieJar $cookies, PolyOutput $output): void
    {
        $client = new Client([
            'timeout' => 60,
            'cookies' => $cookies,
        ]);

        $baseDir = BASE_PATH . '/_livedata/assets';

        foreach ($list as $entry) {
            $relativePath = $entry['path'];
            $remoteMd5 = $entry['md5'];
            $localPath = $baseDir . '/' . $relativePath;

            if (is_file($localPath) && md5_file($localPath) === $remoteMd5) {
                $output->writeln("Skipping (unchanged): $relativePath");
                continue;
            }

            $localDir = dirname($localPath);
            if (!is_dir($localDir)) {
                mkdir($localDir, 0755, true);
            }

            $output->writeln("Downloading: $relativePath");
            $response = $client->get($remoteUrl . '/admin/settings/doDownloadAsset', [
                'query' => ['path' => $relativePath],
            ]);

            file_put_contents($localPath, (string) $response->getBody());
        }
    }
}
