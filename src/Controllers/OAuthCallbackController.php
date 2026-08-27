<?php

namespace Atwx\ProjectInfo\Controllers;

use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;

/**
 * Catches the OAuth redirect for the command line tools.
 *
 * A CLI process cannot receive a browser redirect itself, and a loopback port
 * inside a container is not reachable from the host's browser. The development
 * site is, so the code lands here and is left in the temp directory for the
 * waiting process to pick up. Both run as the same user in the same container,
 * so the file is all the two of them need to talk.
 *
 * The code alone is worthless: exchanging it needs the PKCE verifier, which
 * never leaves the CLI, and the state ties the answer to one waiting call.
 * Development only regardless -- see routes.yml.
 */
class OAuthCallbackController extends Controller
{
    private static string $url_segment = '_silvergateauth';

    private static array $allowed_actions = [
        'index',
    ];

    /**
     * State is used to build a filename, so nothing but a plain hex handle is
     * accepted.
     */
    private const STATE_PATTERN = '/^[a-f0-9]{16,64}$/';

    protected function init(): void
    {
        // No session or login check: the waiting CLI is the only consumer.
        Controller::init();
    }

    public function index(HTTPRequest $request): HTTPResponse
    {
        if (!Director::isDev()) {
            return $this->httpError(404);
        }

        $state = (string) $request->getVar('state');

        if (!preg_match(self::STATE_PATTERN, $state)) {
            return $this->page('Ungültige Anfrage', 'Der state-Parameter fehlt oder ist unbrauchbar.');
        }

        $error = (string) $request->getVar('error');
        $code = (string) $request->getVar('code');

        if (!$error && !$code) {
            return $this->page('Ungültige Anfrage', 'Es wurde weder ein Code noch ein Fehler übergeben.');
        }

        file_put_contents(
            static::pathForState($state),
            json_encode([
                'code' => $code,
                'error' => $error,
                'error_description' => (string) $request->getVar('error_description'),
            ])
        );
        chmod(static::pathForState($state), 0600);

        if ($error) {
            return $this->page('Abgelehnt', 'Die Freigabe wurde nicht erteilt. Zurück zur Kommandozeile.');
        }

        return $this->page('Fertig', 'Die Kommandozeile macht weiter. Dieses Fenster kann geschlossen werden.');
    }

    /**
     * Where the CLI and this controller meet. Shared by both because they run
     * as the same user in the same container.
     */
    public static function pathForState(string $state): string
    {
        return TEMP_PATH . DIRECTORY_SEPARATOR . 'silvergate-oauth-' . $state . '.json';
    }

    private function page(string $heading, string $text): HTTPResponse
    {
        $body = sprintf(
            '<!doctype html><html lang="de"><head><meta charset="utf-8">'
            . '<title>%1$s</title><style>'
            . 'body{font:16px/1.5 system-ui,sans-serif;margin:0;display:grid;place-items:center;'
            . 'min-height:100vh;background:#f5f5f5;color:#222}'
            . 'div{background:#fff;padding:2.5rem 3rem;border-radius:.5rem;text-align:center;'
            . 'box-shadow:0 1px 3px rgba(0,0,0,.12)}h1{margin:0 0 .5rem;font-size:1.25rem}'
            . 'p{margin:0;color:#666}</style></head>'
            . '<body><div><h1>%1$s</h1><p>%2$s</p></div></body></html>',
            htmlspecialchars($heading, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
        );

        $response = HTTPResponse::create($body, 200);
        $response->addHeader('Content-Type', 'text/html; charset=utf-8');

        return $response;
    }
}
