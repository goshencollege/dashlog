<?php

namespace App\Command;

use App\Service\LogIngestor;
use App\Service\Syslog\SyslogMessageParser;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;

#[AsCommand(
    name: 'app:syslog:listen',
    description: 'Listen for incoming syslog messages over UDP and hand them off for batching/storage',
)]
class SyslogListenCommand extends Command
{
    public function __construct(
        private readonly SyslogMessageParser $parser,
        private readonly LogIngestor $ingestor,
        private readonly LoggerInterface $logger,
        private readonly int $visibilityFlushSeconds,
        private readonly BacktraceDebugDataHolder $debugDataHolder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Address to bind', '0.0.0.0')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'UDP port to listen on', '514')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $host = $input->getOption('host');
        $port = (int) $input->getOption('port');

        $socket = @stream_socket_server("udp://{$host}:{$port}", $errno, $errstr, STREAM_SERVER_BIND);
        if ($socket === false) {
            $io->error("Failed to bind udp://{$host}:{$port}: {$errstr} ({$errno})");

            return Command::FAILURE;
        }
        stream_set_blocking($socket, false);

        $io->success("Listening for syslog messages on udp://{$host}:{$port}");

        // LogIngestor's window buffer is in-process only (see its docblock)
        // — a graceful stop must flush whatever's still open before exiting,
        // or every window that hadn't yet closed is orphaned 'pending'
        // forever. This image's STOPSIGNAL is SIGQUIT (inherited from the
        // php-fpm base image, correct for the app container that shares it)
        // rather than SIGTERM, so `docker stop`/restart send SIGQUIT here
        // too — listen for both, plus SIGINT for interactive Ctrl-C. Guarded
        // by function_exists() since pcntl isn't available in every PHP
        // build; without it this just degrades to the old, ungraceful
        // behavior.
        $shouldStop = false;
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $stop = function () use (&$shouldStop): void { $shouldStop = true; };
            pcntl_signal(SIGTERM, $stop);
            pcntl_signal(SIGINT, $stop);
            pcntl_signal(SIGQUIT, $stop);
        }

        $lastVisibilityFlush = null;

        // Runs until stopped. stream_select's timeout doubles as the tick
        // that drives flushExpiredWindows even during quiet periods with no
        // traffic.
        while (!$shouldStop) {
            $read = [$socket];
            $write = $except = [];

            if (@stream_select($read, $write, $except, 1) > 0) {
                while (($data = stream_socket_recvfrom($socket, 65535, 0, $peer)) !== false) {
                    // A single malformed/unexpected datagram must never take
                    // down the whole listener — log it and keep serving
                    // every other source.
                    try {
                        $line = $this->parser->parse($data, $this->peerAddress($peer), new \DateTimeImmutable());
                        $this->ingestor->ingest($line);
                    } catch (\Throwable $e) {
                        $this->logger->error('Failed to process an incoming syslog datagram from {peer}: {error}', [
                            'peer' => $peer,
                            'error' => $e->getMessage(),
                            'exception' => $e,
                        ]);
                    }
                }
            }

            $now = new \DateTimeImmutable();

            // Make recently-arrived lines browsable well before their window
            // closes, on a coarser interval than the per-second select tick
            // so this doesn't turn into a write per line.
            if ($lastVisibilityFlush === null || $now >= $lastVisibilityFlush->modify("+{$this->visibilityFlushSeconds} seconds")) {
                try {
                    $this->ingestor->flushForVisibility();
                } catch (\Throwable $e) {
                    $this->logger->error('Unexpected error while flushing log entries for visibility: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
                }

                // Doctrine's debug/profiler middleware logs every query plus
                // a full backtrace and never clears it on its own — fine for
                // one HTTP request, fatal for a process that runs forever
                // and executes thousands of queries. This is what was
                // exhausting the listener's memory limit every 40-90
                // minutes. (Symfony's own services_resetter would also
                // reset here, but that clears the whole EntityManager's
                // identity map too, which would detach the LogObject
                // references LogIngestor's buffer holds across cycles —
                // reset just this one leaking service instead.)
                $this->debugDataHolder->reset();
                $lastVisibilityFlush = $now;
            }

            try {
                $this->ingestor->flushExpiredWindows($now);
            } catch (\Throwable $e) {
                $this->logger->error('Unexpected error while flushing log batches: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
            }
        }

        $io->writeln('Stop signal received, flushing open windows before exit…');
        try {
            $this->ingestor->flushAll(new \DateTimeImmutable());
        } catch (\Throwable $e) {
            $this->logger->error('Failed to flush open windows during graceful shutdown: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
        }

        return Command::SUCCESS;
    }

    private function peerAddress(string $peer): string
    {
        // $peer is "ip:port" for IPv4, or "[ipv6]:port" for IPv6 — the last
        // colon always precedes the port either way.
        $pos = strrpos($peer, ':');
        $ip = $pos !== false ? substr($peer, 0, $pos) : $peer;

        return trim($ip, '[]');
    }
}
