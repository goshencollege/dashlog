<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260811131702 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add log_entry structured search index table';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE log_entry (id INT AUTO_INCREMENT NOT NULL, source VARCHAR(255) NOT NULL, timestamp DATETIME NOT NULL, host VARCHAR(255) DEFAULT NULL, app_name VARCHAR(255) DEFAULT NULL, proc_id VARCHAR(255) DEFAULT NULL, severity INT DEFAULT NULL, facility INT DEFAULT NULL, message LONGTEXT NOT NULL, created_at DATETIME NOT NULL, log_object_id INT NOT NULL, INDEX IDX_B5F762D352FCAA5 (log_object_id), INDEX idx_log_entry_source_timestamp (source, timestamp), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE log_entry ADD CONSTRAINT FK_B5F762D352FCAA5 FOREIGN KEY (log_object_id) REFERENCES log_object (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE log_entry DROP FOREIGN KEY FK_B5F762D352FCAA5');
        $this->addSql('DROP TABLE log_entry');
    }
}
