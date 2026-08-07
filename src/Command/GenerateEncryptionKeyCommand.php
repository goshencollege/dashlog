<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:generate-encryption-key', description: 'Generate a random APP_ENCRYPTION_KEY value')]
class GenerateEncryptionKeyCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io  = new SymfonyStyle($input, $output);
        $key = sodium_bin2base64(sodium_crypto_secretbox_keygen(), SODIUM_BASE64_VARIANT_ORIGINAL);

        $io->success('Add the following line to your .env.local file:');
        $io->writeln("APP_ENCRYPTION_KEY=$key");
        $io->caution('Store this key securely. Losing it means losing access to all encrypted credentials.');

        return Command::SUCCESS;
    }
}
