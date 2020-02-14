<?php
declare(strict_types=1);

namespace DelayedJobs;

use Cake\Console\ConsoleOptionParser;
use Cake\Console\Shell;
use Cake\Event\Event;
use Cake\Event\EventDispatcherTrait;
use Cake\Log\LogTrait;
use Exception;

/**
 * ProcessManager
 *
 * Helps with CLI handling of UNIX signals
 *
 * IMPORTANT:
 *
 * Remember to add the following code at the top of `config/bootstrap.php`;
 * if you do not, signal handling will fail silently.
 *
 * ```
 * if (php_sapi_name() === 'cli') {
 *    declare(ticks = 1);
 * }
 * ```
 */
class ProcessManager
{
    use EventDispatcherTrait;
    use LogTrait;

    /**
     * List of valid UNIX signals
     *
     * Used to map between the signal number in the handler
     * and its real name
     *
     * @var array
     */
    public static $signals = [
        1 => 'SIGHUP',
        2 => 'SIGINT',
        3 => 'SIGQUIT',
        4 => 'SIGILL',
        5 => 'SIGTRAP',
        6 => 'SIGABRT',
        7 => 'SIGBUS',
        8 => 'SIGFPE',
        9 => 'SIGKILL',
        10 => 'SIGUSR1',
        11 => 'SIGSEGV',
        12 => 'SIGUSR2',
        13 => 'SIGPIPE',
        14 => 'SIGALRM',
        15 => 'SIGTERM',
        16 => 'SIG16',
        17 => 'SIGCHLD',
        18 => 'SIGCONT',
        19 => 'SIGSTOP',
        20 => 'SIGTSTP',
        21 => 'SIGTTIN',
        22 => 'SIGTTOU',
        23 => 'SIGURG',
        24 => 'SIGXCPU',
        25 => 'SIGXFSZ',
        26 => 'SIGVTALRM',
        27 => 'SIGPROF',
        28 => 'SIGWINCH',
        29 => 'SIGIO',
        30 => 'SIGPWR',
        31 => 'SIGSYS',
        32 => 'SIG32',
        33 => 'SIG33',
        34 => 'SIGRTMIN',
        35 => 'SIGRTMIN+1',
        36 => 'SIGRTMIN+2',
        37 => 'SIGRTMIN+3',
        38 => 'SIGRTMIN+4',
        39 => 'SIGRTMIN+5',
        40 => 'SIGRTMIN+6',
        41 => 'SIGRTMIN+7',
        42 => 'SIGRTMIN+8',
        43 => 'SIGRTMIN+9',
        44 => 'SIGRTMIN+10',
        45 => 'SIGRTMIN+11',
        46 => 'SIGRTMIN+12',
        47 => 'SIGRTMIN+13',
        48 => 'SIGRTMIN+14',
        49 => 'SIGRTMIN+15',
        50 => 'SIGRTMAX-14',
        51 => 'SIGRTMAX-13',
        52 => 'SIGRTMAX-12',
        53 => 'SIGRTMAX-11',
        54 => 'SIGRTMAX-10',
        55 => 'SIGRTMAX-9',
        56 => 'SIGRTMAX-8',
        57 => 'SIGRTMAX-7',
        58 => 'SIGRTMAX-6',
        59 => 'SIGRTMAX-5',
        60 => 'SIGRTMAX-4',
        61 => 'SIGRTMAX-3',
        62 => 'SIGRTMAX-2',
        63 => 'SIGRTMAX-1',
        64 => 'SIGRTMAX',
    ];

    /**
     * Register signals and when they are triggered, write a Signal.shutdown property
     *
     * @param mixed $signal Can be any of the SIG* constants or its human name
     * @param mixed $callback Can be anything PHP callable
     * @return void
     * @throws \Exception on invalid (unknown) signals.
     * @throws \Exception if unable to subscribe to signal.
     */
    public function signal($signal, callable $callback): void
    {
        if (!extension_loaded('pcntl')) {
            return ;
        }

        if (is_numeric($signal)) {
            if (!array_key_exists($signal, static::$signals)) {
                throw new Exception('Unknown signal: %s', $signal);
            }
            $signo = $signal;
            $signalName = static::$signals[$signal];
        } else {
            $pos = array_search($signal, static::$signals, true);
            if ($pos === false) {
                throw new Exception('Unknown signal: %s', $signal);
            }
            $signo = $pos;
            $signalName = $signal;
        }

        if (!pcntl_signal($signo, $callback)) {
            throw new Exception(sprintf('Could not subscribe to signal %s (%d)', $signalName, $signo));
        }

        $this->log(sprintf('Successfully subscribed to signal %s (%d)', $signalName, $signo), 'debug');
    }

    /**
     * @return void
     * @throws \Exception
     */
    public function handleKillSignals(): void
    {
        $callback = function ($signo) {
            $this->log('Got OS signal "' . static::$_signals[$signo] . '"', 'warning');

            $event = new Event('CLI.signal', compact('signo'));
            $this->getEventManager()
                ->dispatch($event);
        };

        $this->signal('SIGHUP', $callback);
        $this->signal('SIGTERM', $callback);
        $this->signal('SIGINT', $callback);
        $this->signal('SIGQUIT', $callback);
        $this->signal('SIGUSR1', $callback);
        $this->signal('SIGUSR2', $callback);
    }
}
