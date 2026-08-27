<?php

namespace Atwx\ProjectInfo\Services;

use Atwx\ProjectInfo\Controllers\OAuthCallbackController;
use GuzzleHttp\Client;
use SilverStripe\Control\Director;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\PolyExecution\PolyOutput;

/**
 * Holds an OAuth grant for the SilverGate manager on behalf of the command line.
 *
 * The first call sends the developer through the manager's consent screen once;
 * after that the refresh token renews access quietly until it goes unused for a
 * day. Tokens live in the user's home directory, one file per manager host, so a
 * grant is not tied to whichever project happened to create it.
 */
class OAuthSession
{
    use Configurable;
    use Injectable;

    public const SCOPE_READ = 'mcp';
    public const SCOPE_WRITE = 'mcp:write';

    /**
     * How long to wait for the browser round trip.
     *
     * @config
     */
    private static int $authorisation_timeout = 300;

    public function __construct(private string $managerBase)
    {
        $this->managerBase = rtrim($managerBase, '/');
    }

    /**
     * A usable access token, asking for consent only when nothing else works.
     */
    public function accessToken(string $scope, PolyOutput $output): string
    {
        $store = $this->readStore();

        if ($this->isFresh($store) && $this->covers($store['scope'] ?? '', $scope)) {
            return $store['access_token'];
        }

        if (!empty($store['refresh_token']) && $this->covers($store['scope'] ?? '', $scope)) {
            $refreshed = $this->refresh($store);

            if ($refreshed) {
                return $refreshed['access_token'];
            }

            $output->writeln('<comment>Die gespeicherte Anmeldung ist abgelaufen.</comment>');
        }

        return $this->authorise($scope, $output)['access_token'];
    }

    /**
     * Forget the stored grant, so the next call asks for consent again.
     */
    public function forget(): bool
    {
        $path = $this->storePath();

        return file_exists($path) && unlink($path);
    }

    public function storePath(): string
    {
        $home = Environment::getEnv('HOME') ?: sys_get_temp_dir();
        $dir = $home . DIRECTORY_SEPARATOR . '.silvergate';

        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $host = parse_url($this->managerBase, PHP_URL_HOST) ?: 'manager';

        return $dir . DIRECTORY_SEPARATOR . preg_replace('/[^a-z0-9.-]/i', '_', $host) . '.json';
    }

    /**
     * The site URL a browser can actually reach. Director gives the container's
     * internal name on the command line, which is neither reachable nor https,
     * so the environment is asked first.
     */
    public function callbackBase(): string
    {
        $base = Environment::getEnv('DDEV_PRIMARY_URL')
            ?: Environment::getEnv('SS_BASE_URL')
            ?: Director::absoluteBaseURL();

        return rtrim((string) $base, '/');
    }

    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function authorise(string $scope, PolyOutput $output): array
    {
        $redirectUri = $this->callbackBase() . '/_silvergateauth';

        if (!str_starts_with($redirectUri, 'https://')) {
            throw new \RuntimeException(
                'The callback URL must be https, got ' . $redirectUri . '. Set DDEV_PRIMARY_URL or SS_BASE_URL.'
            );
        }

        $store = $this->readStore();
        $clientId = ($store['redirect_uri'] ?? null) === $redirectUri ? ($store['client_id'] ?? '') : '';

        if (!$clientId) {
            $clientId = $this->registerClient($redirectUri);
        }

        $state = bin2hex(random_bytes(16));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $url = $this->managerBase . '/_silvergatemcp/oauth/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => $scope,
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        $output->writeln('');
        $output->writeln('Einmalige Freigabe nötig. Diesen Link im Browser öffnen:');
        $output->writeln('');
        $output->writeln('  ' . $url);
        $output->writeln('');
        $output->writeln('Warte auf die Bestätigung...');

        $answer = $this->waitForCallback($state);

        if (!empty($answer['error'])) {
            throw new \RuntimeException(trim($answer['error'] . ' ' . ($answer['error_description'] ?? '')));
        }

        $tokens = $this->exchange([
            'grant_type' => 'authorization_code',
            'code' => $answer['code'],
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $verifier,
        ]);

        $output->writeln('<info>Freigabe erteilt.</info>');

        return $this->writeStore($tokens + ['client_id' => $clientId, 'redirect_uri' => $redirectUri]);
    }

    /**
     * @param array<string, mixed> $store
     * @return array<string, mixed>|null
     */
    private function refresh(array $store): ?array
    {
        try {
            $tokens = $this->exchange([
                'grant_type' => 'refresh_token',
                'refresh_token' => $store['refresh_token'],
                'client_id' => $store['client_id'] ?? '',
            ]);
        } catch (\Throwable) {
            return null;
        }

        return $this->writeStore($tokens + [
            'client_id' => $store['client_id'] ?? '',
            'redirect_uri' => $store['redirect_uri'] ?? '',
        ]);
    }

    private function registerClient(string $redirectUri): string
    {
        $response = (new Client(['timeout' => 30, 'verify' => false]))->post(
            $this->managerBase . '/_silvergatemcp/oauth/register',
            ['json' => ['client_name' => 'SilverGate CLI', 'redirect_uris' => [$redirectUri]]]
        );

        $data = json_decode((string) $response->getBody(), true) ?: [];

        if (empty($data['client_id'])) {
            throw new \RuntimeException('The manager did not return a client_id.');
        }

        return (string) $data['client_id'];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function exchange(array $params): array
    {
        $response = (new Client(['timeout' => 30, 'verify' => false, 'http_errors' => false]))->post(
            $this->managerBase . '/_silvergatemcp/oauth/token',
            ['form_params' => $params]
        );

        $data = json_decode((string) $response->getBody(), true) ?: [];

        if ($response->getStatusCode() >= 400 || empty($data['access_token'])) {
            throw new \RuntimeException(
                'Token exchange failed: ' . ($data['error_description'] ?? $data['error'] ?? 'unknown error')
            );
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function waitForCallback(string $state): array
    {
        $path = OAuthCallbackController::pathForState($state);
        $deadline = time() + (int) static::config()->get('authorisation_timeout');

        while (time() < $deadline) {
            if (file_exists($path)) {
                $answer = json_decode((string) file_get_contents($path), true) ?: [];
                unlink($path);

                return $answer;
            }

            usleep(500000);
        }

        throw new \RuntimeException('Timed out waiting for the browser confirmation.');
    }

    /**
     * @param array<string, mixed> $store
     */
    private function isFresh(array $store): bool
    {
        // A small margin, so a token cannot expire between here and the call.
        return !empty($store['access_token']) && ($store['expires_at'] ?? 0) > time() + 30;
    }

    private function covers(string $granted, string $wanted): bool
    {
        return $wanted === self::SCOPE_READ || $granted === self::SCOPE_WRITE;
    }

    /**
     * @return array<string, mixed>
     */
    private function readStore(): array
    {
        $path = $this->storePath();

        return file_exists($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function writeStore(array $data): array
    {
        $store = [
            'client_id' => $data['client_id'] ?? '',
            'redirect_uri' => $data['redirect_uri'] ?? '',
            'access_token' => $data['access_token'] ?? '',
            'refresh_token' => $data['refresh_token'] ?? '',
            'scope' => $data['scope'] ?? '',
            'expires_at' => time() + (int) ($data['expires_in'] ?? 3600),
        ];

        $path = $this->storePath();
        file_put_contents($path, json_encode($store, JSON_PRETTY_PRINT));
        chmod($path, 0600);

        return $store;
    }
}
