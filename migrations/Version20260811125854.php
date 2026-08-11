<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260811125854 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add log_object catalog table';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE log_object (id INT AUTO_INCREMENT NOT NULL, object_key VARCHAR(512) NOT NULL, source VARCHAR(255) NOT NULL, tier VARCHAR(20) NOT NULL, window_start DATETIME NOT NULL, window_end DATETIME NOT NULL, size_bytes INT NOT NULL, checksum_sha256 VARCHAR(64) DEFAULT NULL, entry_count INT DEFAULT NULL, status VARCHAR(20) NOT NULL, last_error LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, created_by VARCHAR(255) DEFAULT NULL, updated_by VARCHAR(255) DEFAULT NULL, storage_backend_id INT NOT NULL, INDEX IDX_BD57F84E697DBBA6 (storage_backend_id), INDEX idx_source_window_start (source, window_start), UNIQUE INDEX uniq_backend_object_key (storage_backend_id, object_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE log_object ADD CONSTRAINT FK_BD57F84E697DBBA6 FOREIGN KEY (storage_backend_id) REFERENCES storage_backend (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE log_object DROP FOREIGN KEY FK_BD57F84E697DBBA6');
        $this->addSql('DROP TABLE log_object');
    }
}
