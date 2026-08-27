<?php

namespace Atwx\ProjectInfo\Services;

use SilverStripe\PolyExecution\PolyOutput;

/**
 * Progress messages that must not end up in a redirected file.
 *
 * "Fetching JWT..." belongs on the screen, not in the CSV someone captured
 * with a >. On the command line these go to stderr, so stdout carries only
 * what the task actually produced. Anywhere else they join the normal output,
 * because there is no second channel to send them down.
 */
final class Status
{
    public static function note(PolyOutput $output, string $message = ''): void
    {
        if (PHP_SAPI === 'cli' && defined('STDERR')) {
            fwrite(STDERR, strip_tags($message) . PHP_EOL);

            return;
        }

        $output->writeln($message);
    }
}
