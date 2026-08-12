<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260812130221 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add LogObject.entriesCached, tracking whether a batch still has LogEntry rows in the DB.';
    }

    public function up(Schema $schema): void
    {
        // Existing rows all still have their LogEntry rows (pruning didn't exist before this), so default to true.
        $this->addSql('ALTER TABLE log_object ADD entries_cached TINYINT(1) NOT NULL DEFAULT 1');
        $this->addSql('CREATE INDEX idx_entries_cached_window ON log_object (entries_cached, window_start)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_entries_cached_window ON log_object');
        $this->addSql('ALTER TABLE log_object DROP entries_cached');
    }
}
