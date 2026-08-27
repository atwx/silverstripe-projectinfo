<?php

namespace Atwx\ProjectInfo\Services;

use GuzzleHttp\Client;
use SilverStripe\PolyExecution\PolyOutput;
use GuzzleHttp\Cookie\CookieJar;
use SilverStripe\Core\Injector\Injectable;

/**
 * Signs in to a managed site from the command line.
 *
 * The SilverGate manager holds the private keys, so it is asked to mint a JWT
 * for the target domain and the site trades that for an ordinary session. The
 * cookie jar this hands back reaches everything a logged in admin can reach
 * over HTTP.
 */
class RemoteSession
{
    use Injectable;

    public const DEFAULT_INTRANET_URL = 'https://intra.atw.io/_api/token';

    /**
     * Turn a bare host into an absolute base URL and drop any trailing slash.
     */
    public static function normaliseUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');

        if (!str_starts_with($url, 'http')) {
            $url = 'https://' . $url;
        }

        return $url;
    }

    /**
     * Exchange a personal access token for a JWT the target domain will accept.
     */
    public function fetchJwt(string $token, string $domain, string $intranetUrl): string
    {
        return $this->requestJwt($domain, $intranetUrl, ['token' => $token]);
    }

    /**
     * The same exchange, authorised by an OAuth grant instead. The personal
     * access token rotates hourly; a grant is confirmed once in the browser and
     * then renews itself, so this is the friendlier of the two.
     */
    public function fetchJwtWithOAuth(
        string $domain,
        string $intranetUrl,
        string $scope,
        PolyOutput $output
    ): string {
        $managerBase = static::managerBaseFrom($intranetUrl);
        $oauthScope = $scope === 'write' ? OAuthSession::SCOPE_WRITE : OAuthSession::SCOPE_READ;
        $accessToken = OAuthSession::create($managerBase)->accessToken($oauthScope, $output);

        return $this->requestJwt($domain, $intranetUrl, [], $accessToken);
    }

    /**
     * @param array<string, string> $formParams
     */
    private function requestJwt(
        string $domain,
        string $intranetUrl,
        array $formParams,
        ?string $bearer = null
    ): string {
        $options = ['form_params' => $formParams + ['domain' => $domain]];

        if ($bearer) {
            $options['headers'] = ['Authorization' => 'Bearer ' . $bearer];
        }

        $client = new Client(['timeout' => 30, 'verify' => false]);
        $response = $client->post($intranetUrl, $options);

        $data = json_decode((string) $response->getBody(), true);

        if (!isset($data['jwt'])) {
            throw new \RuntimeException('No JWT in intranet response: ' . (string) $response->getBody());
        }

        return $data['jwt'];
    }

    /**
     * The token endpoint sits under the manager's own base URL, which is what
     * the OAuth endpoints hang off too.
     */
    public static function managerBaseFrom(string $intranetUrl): string
    {
        $parts = parse_url($intranetUrl);

        return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '')
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * Trade the JWT for a session on the remote site.
     */
    public function authenticate(string $jwt, string $remoteUrl, ?array $httpAuth = null): CookieJar
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

    /**
     * Both steps at once, for callers that only want the finished session.
     */
    public function connect(
        string $token,
        string $remoteUrl,
        string $intranetUrl = self::DEFAULT_INTRANET_URL,
        ?array $httpAuth = null
    ): CookieJar {
        $jwt = $this->fetchJwt($token, parse_url($remoteUrl, PHP_URL_HOST), $intranetUrl);

        return $this->authenticate($jwt, $remoteUrl, $httpAuth);
    }
}
