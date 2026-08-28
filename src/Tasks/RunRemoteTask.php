<?php

namespace Atwx\ProjectInfo\Tasks;

use Atwx\ProjectInfo\Services\RemoteSession;
use Atwx\ProjectInfo\Services\Status;
use GuzzleHttp\Client;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Run a build task on a managed site from here.
 *
 * There is no API for this: the site's own /dev/tasks runner is used, reached
 * with the session RemoteSession sets up. Silverstripe streams task output as
 * it happens, so it is relayed line by line rather than buffered.
 */
class RunRemoteTask extends BuildTask
{
    protected string $title = 'Run Remote Task';

    protected static string $description = 'Run a build task on a remote managed site.';

    protected static string $commandName = 'remote';

    /**
     * Needs a personal access token, so it has no business being triggered from
     * a browser.
     */
    private static bool $can_run_in_browser = false;

    #[\Override]
    public function getOptions(): array
    {
        return [
            new InputArgument('task', InputArgument::REQUIRED, 'Command name of the task to run, e.g. SendMessagesTask'),
            new InputArgument(
                'pass-through',
                InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                'Options forwarded to the remote task, after a --'
            ),
            new InputOption('url', 'u', InputOption::VALUE_REQUIRED, 'Domain of the target site (e.g. www.example.com)'),
            new InputOption(
                'token',
                't',
                InputOption::VALUE_REQUIRED,
                'Personal access token. Omit to authorise through the browser instead.'
            ),
            new InputOption(
                'intranet-url',
                'i',
                InputOption::VALUE_OPTIONAL,
                'Token API URL',
                RemoteSession::DEFAULT_INTRANET_URL
            ),
            new InputOption('http-user', null, InputOption::VALUE_OPTIONAL, 'HTTP Basic Auth username for the target site'),
            new InputOption('http-pass', null, InputOption::VALUE_OPTIONAL, 'HTTP Basic Auth password for the target site'),
        ];
    }

    #[\Override]
    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $taskName = $input->getArgument('task');
        $remoteUrl = $input->getOption('url');
        $token = $input->getOption('token');
        $intranetUrl = $input->getOption('intranet-url') ?: RemoteSession::DEFAULT_INTRANET_URL;
        $httpUser = $input->getOption('http-user');
        $httpPass = $input->getOption('http-pass');
        $httpAuth = ($httpUser && $httpPass) ? [$httpUser, $httpPass] : null;

        if (!$remoteUrl) {
            Status::note($output, 'Fehler: --url ist erforderlich.');
            Status::note($output, 'Aufruf: sake tasks:remote SendMessagesTask -u www.example.com');
            return Command::FAILURE;
        }

        $remoteUrl = RemoteSession::normaliseUrl($remoteUrl);
        $query = $this->parsePassThrough((array) $input->getArgument('pass-through'));

        try {
            $session = RemoteSession::create();

            // Running a task changes things, so the JWT has to carry write scope.
            $auth = $session->authorise(
                parse_url($remoteUrl, PHP_URL_HOST),
                $intranetUrl,
                'write',
                $output,
                $token
            );

            // Follow the manager's spelling of the domain, www or not.
            $remoteUrl = RemoteSession::normaliseUrl($auth['domain']);
            $jwt = $auth['jwt'];

            Status::note($output, 'Authenticating...');
            $cookies = $session->authenticate($jwt, $remoteUrl, $httpAuth);

            Status::note($output, sprintf('Running %s on %s...', $taskName, $remoteUrl));

            return $this->runRemote($remoteUrl, $taskName, $query, $cookies, $httpAuth, $output);
        } catch (\Throwable $e) {
            Status::note($output, 'Fehler: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Turn "--only-db --limit=5" into the query the remote HttpRequestInput reads
     * its options from.
     *
     * @param array<int, string> $arguments
     * @return array<string, string>
     */
    protected function parsePassThrough(array $arguments): array
    {
        $query = [];

        foreach ($arguments as $argument) {
            $argument = ltrim((string) $argument, '-');

            if ($argument === '') {
                continue;
            }

            if (str_contains($argument, '=')) {
                [$key, $value] = explode('=', $argument, 2);
                $query[$key] = $value;
                continue;
            }

            $query[$argument] = '1';
        }

        return $query;
    }

    /**
     * @param array<string, string> $query
     */
    protected function runRemote(
        string $remoteUrl,
        string $taskName,
        array $query,
        $cookies,
        ?array $httpAuth,
        PolyOutput $output
    ): int {
        $clientOptions = [
            'timeout' => 0,
            'cookies' => $cookies,
            'stream' => true,
            'http_errors' => false,
        ];

        if ($httpAuth) {
            $clientOptions['auth'] = $httpAuth;
        }

        $response = (new Client($clientOptions))->get(
            $remoteUrl . '/dev/tasks/' . rawurlencode($taskName),
            ['query' => $query]
        );

        $status = $response->getStatusCode();

        if ($status === 404) {
            Status::note($output, sprintf('Die Seite kennt keinen Task "%s".', $taskName));
            return Command::FAILURE;
        }

        if ($status >= 400) {
            Status::note($output, sprintf('Die Seite antwortete mit %d.', $status));
            return Command::FAILURE;
        }

        // Relay the stream as it arrives so a long task reports progress.
        $body = $response->getBody();
        $buffer = '';
        $sawOutput = false;
        $failed = false;

        $emit = function (string $chunk) use ($output, &$sawOutput, &$failed): void {
            foreach (explode("\n", $chunk) as $line) {
                $text = $this->toPlainText($line);

                if ($text === '') {
                    continue;
                }

                if ($this->isFailureNotice($text)) {
                    $failed = true;
                    Status::note($output, $text);
                    continue;
                }

                $output->writeln($text);
                $sawOutput = true;
            }
        };

        while (!$body->eof()) {
            $buffer .= $body->read(1024);

            // Stop short of a tag split across two reads, so it is not mangled.
            $lastTagEnd = strrpos($buffer, '>');

            if ($lastTagEnd === false) {
                continue;
            }

            $emit($this->breakLines(substr($buffer, 0, $lastTagEnd + 1)));
            $buffer = substr($buffer, $lastTagEnd + 1);
        }

        $emit($this->breakLines($buffer));

        if ($failed) {
            return Command::FAILURE;
        }

        if (!$sawOutput) {
            Status::note($output, 'Der Task hat nichts ausgegeben.');
        }

        return Command::SUCCESS;
    }

    /**
     * The runner answers 200 even when it refuses, and says so only in the body.
     */
    protected function isFailureNotice(string $text): bool
    {
        return str_contains($text, 'could not be found, is disabled')
            || str_contains($text, 'The task is disabled');
    }

    /**
     * Block level markup carries the line breaks in the runner's HTML.
     */
    protected function breakLines(string $html): string
    {
        return (string) preg_replace(
            '#<br\s*/?>|</(?:h[1-6]|p|div|li|tr|pre)>#i',
            "\n",
            $html
        );
    }

    /**
     * The remote runner answers in HTML, so strip it back to something readable
     * on a terminal.
     */
    protected function toPlainText(string $line): string
    {
        $line = strip_tags($line);

        return trim(html_entity_decode($line, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
