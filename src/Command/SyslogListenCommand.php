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

        $lastVisibilityFlush = null;

        // Runs until killed. stream_select's timeout doubles as the tick that
        // drives flushExpiredWindows even during quiet periods with no traffic.
        while (true) {
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
                        $this->logger->error('Failed to process an incoming syslog datagram.', [
                            'peer' => $peer,
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
                    $this->logger->error('Unexpected error while flushing log entries for visibility.', ['exception' => $e]);
                }
                $lastVisibilityFlush = $now;
            }

            try {
                $this->ingestor->flushExpiredWindows($now);
            } catch (\Throwable $e) {
                $this->logger->error('Unexpected error while flushing log batches.', ['exception' => $e]);
            }
        }
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
