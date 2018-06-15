<?php
//Borrowed from https://github.com/FriendsOfCake/process-manager until that is available via composer

namespace DelayedJobs\Shell\Task;

use Cake\Console\ConsoleOptionParser;
use Cake\Console\Shell;
use Cake\Event\Event;
use Cake\Event\EventDispatcherTrait;
use Exception;

/**
 * ProcessManager Task
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
class ProcessManagerTask extends Shell
{

    use EventDispatcherTrait;

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
        64 => 'SIGRTMAX'
    ];

    /**
     * Gets the option parser instance and configures it.
     * By overriding this method you can configure the ConsoleOptionParser before returning it.
     *
     * @param \Cake\Console\ConsoleOptionParser $parser
     * @return \Cake\Console\ConsoleOptionParser
     * @link http://book.cakephp.org/2.0/en/console-and-shells.html#Shell::getOptionParser
     */
    public function processOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser->addOption('process_id', [
            'help' => 'A process identifier to make locking work with Supervisor'
        ]);
    }

    /**
     * Register signals and when they are triggered, write a Signal.shutdown property
     *
     * @param mixed $signal Can be any of the SIG* constants or its human name
     * @param mixed $callback Can be anything PHP callable
     * @return void
     * @throws \Exception on invalid (unknown) signals.
     * @throws \Exception if unable to subscribe to signal.
     */
    public function signal($signal, callable $callback)
    {
        if (is_numeric($signal)) {
            if (!array_key_exists($signal, static::$signals)) {
                throw new Exception('Unknown signal: %s', $signal);
            }
            $signo = $signal;
            $signalName = static::$signals[$signal];
        } else {
            if (false === ($pos = array_search($signal, static::$signals))) {
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

    public function handleKillSignals()
    {
        $callback = function ($signo) {
            $event = new Event('CLI.signal', null, compact('signo'));
            $this->getEventManager()->dispatch($event);
        };

        $this->signal('SIGHUP', $callback);
        $this->signal('SIGTERM', $callback);
        $this->signal('SIGINT', $callback);
        $this->signal('SIGQUIT', $callback);
        $this->signal('SIGUSR1', $callback);
        $this->signal('SIGUSR2', $callback);
    }
}
