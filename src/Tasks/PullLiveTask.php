<?php

namespace Atwx\ProjectInfo\Tasks;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\BuildTask;

class PullLiveTask extends BuildTask
{
    protected $title = 'Pull Live';

    protected $description = 'Pull DB + assets from the live server and import them into the local environment.';

    private static $segment = 'pull-live';

    /**
     * Parameters are passed as GET vars, both on CLI and in the browser:
     *
     *   vendor/bin/sake dev/tasks/pull-live site=docs.atw.io token=xyz
     *   vendor/bin/sake dev/tasks/pull-live site=docs.atw.io token=xyz only-db=1
     *
     * Note that the remote site is passed as `site`, not `url`: on the CLI,
     * CLIRequestBuilder::cleanEnvironment() overwrites the `url` GET var with the
     * route that is being called, so a `url` parameter would never reach us.
     *
     * @param HTTPRequest $request
     */
    public function run($request)
    {
        $token = $request->getVar('token');
        $remoteUrl = $request->getVar('site');
        $intranetUrl = $request->getVar('intranet-url') ?: 'https://intra.atw.io/_api/token';
        $onlyDb = (bool) $request->getVar('only-db');
        $onlyAssets = (bool) $request->getVar('only-assets');
        $httpUser = $request->getVar('http-user');
        $httpPass = $request->getVar('http-pass');
        $httpAuth = ($httpUser && $httpPass) ? [$httpUser, $httpPass] : null;

        if ($onlyDb && $onlyAssets) {
            $this->fail('only-db and only-assets are mutually exclusive.');
            return;
        }

        $doDb = !$onlyAssets;
        $doAssets = !$onlyDb;

        if (!$token) {
            $this->fail('No token provided. Pass token=<personal access token>.');
            return;
        }

        if (!$remoteUrl) {
            $this->fail('site is required. Usage: sake dev/tasks/pull-live site=docs.atw.io token=<token>');
            return;
        }

        $remoteUrl = rtrim($remoteUrl, '/');
        if (!str_starts_with($remoteUrl, 'http')) {
            $remoteUrl = 'https://' . $remoteUrl;
        }

        try {
            // --- Pull ---
            $this->write('Fetching JWT...');
            $jwt = $this->fetchJwt($token, parse_url($remoteUrl, PHP_URL_HOST), $intranetUrl);

            $this->write('Authenticating...');
            $cookies = $this->authenticate($jwt, $remoteUrl, $httpAuth);

            $dumpFile = null;
            if ($doDb) {
                $this->write('Downloading database dump...');
                $dumpFile = $this->downloadDatabase($remoteUrl, $cookies, $httpAuth);
                $this->write("Saved to $dumpFile");
            }

            if ($doAssets) {
                $this->write('Fetching asset list...');
                $list = $this->fetchAssetList($remoteUrl, $cookies, $httpAuth);
                $this->write(count($list) . ' assets on remote.');

                $this->write('Syncing assets...');
                $this->syncAssets($remoteUrl, $list, $cookies, $httpAuth);
            }

            // --- Import ---
            if ($doDb && $dumpFile !== null) {
                $this->write('Importing database...');
                $this->importDatabase($dumpFile);
            }

            if ($doAssets) {
                $this->write('Copying assets...');
                $this->importAssets();
            }

            $this->write('Done. Run sake dev/build flush=1 if needed.');
        } catch (\Throwable $e) {
            $this->fail($e->getMessage());
        }
    }

    /**
     * Write a progress line, working both on CLI and in the browser.
     */
    private function write(string $text): void
    {
        if (Director::is_cli()) {
            echo $text . PHP_EOL;
        } else {
            echo '<p>' . Convert::raw2xml($text) . '</p>' . PHP_EOL;
        }
        flush();
    }

    /**
     * Report a failure. On CLI this exits with a non-zero status so the task can
     * be used in scripts.
     */
    private function fail(string $text): void
    {
        if (Director::is_cli()) {
            fwrite(STDERR, $text . PHP_EOL);
            exit(1);
        }

        echo '<p style="color:#c00">' . Convert::raw2xml($text) . '</p>' . PHP_EOL;
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

    private function authenticate(string $jwt, string $remoteUrl, ?array $httpAuth = null): CookieJar
    {
        $cookies = new CookieJar();
        $clientOptions = ['timeout' => 30, 'allow_redirects' => true, 'cookies' => $cookies];
        if ($httpAuth) {
            $clientOptions['auth'] = $httpAuth;
        }
        $client = new Client($clientOptions);
        $client->get($remoteUrl . '/_silvergateclient/token/' . urlencode(base64_encode($jwt)));
        return $cookies;
    }

    private function downloadDatabase(string $remoteUrl, CookieJar $cookies, ?array $httpAuth = null): string
    {
        $clientOptions = ['timeout' => 120, 'cookies' => $cookies];
        if ($httpAuth) {
            $clientOptions['auth'] = $httpAuth;
        }
        $client = new Client($clientOptions);
        $response = $client->get($remoteUrl . '/admin/settings/doBackup');

        $dir = BASE_PATH . '/_livedata/db';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file = $dir . '/dump-' . date('Y-m-d') . '.sql';
        file_put_contents($file, (string) $response->getBody());
        return $file;
    }

    private function fetchAssetList(string $remoteUrl, CookieJar $cookies, ?array $httpAuth = null): array
    {
        $clientOptions = ['timeout' => 60, 'cookies' => $cookies];
        if ($httpAuth) {
            $clientOptions['auth'] = $httpAuth;
        }
        $client = new Client($clientOptions);
        $response = $client->get($remoteUrl . '/admin/settings/doListAssets');
        $list = json_decode((string) $response->getBody(), true);

        if (!is_array($list)) {
            throw new \RuntimeException('Unexpected response from doListAssets');
        }

        return $list;
    }

    private function syncAssets(string $remoteUrl, array $list, CookieJar $cookies, ?array $httpAuth = null): void
    {
        $clientOptions = ['timeout' => 60, 'cookies' => $cookies];
        if ($httpAuth) {
            $clientOptions['auth'] = $httpAuth;
        }
        $client = new Client($clientOptions);
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
                $this->write('  Failed ' . $relativePath . ': ' . $e->getMessage());
                $errors++;
            }
        }

        $this->write("$downloaded downloaded, $skipped unchanged, $errors errors.");
    }

    private function importDatabase(string $dumpFile): void
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

        $this->write('Database imported from ' . basename($dumpFile) . '.');
    }

    private function importAssets(): void
    {
        $sourceDir = BASE_PATH . '/_livedata/assets';
        if (!is_dir($sourceDir)) {
            $this->write('No _livedata/assets/ found, skipping.');
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

        $this->write("$copied assets copied, $skipped unchanged.");
    }
}
