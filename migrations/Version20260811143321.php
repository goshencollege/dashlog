<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260811143321 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_spool flag to storage_backend for the system write-ahead spool';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE storage_backend ADD is_spool TINYINT DEFAULT NULL');
        $this->addSql('UPDATE storage_backend SET is_spool = 0 WHERE is_spool IS NULL');
        $this->addSql('ALTER TABLE storage_backend CHANGE is_spool is_spool TINYINT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE storage_backend DROP is_spool');
    }
}
