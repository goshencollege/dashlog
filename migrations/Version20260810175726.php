<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260810175726 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE storage_backend (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, type VARCHAR(20) NOT NULL, is_active TINYINT NOT NULL, path VARCHAR(1024) DEFAULT NULL, cifs_host VARCHAR(255) DEFAULT NULL, cifs_share VARCHAR(255) DEFAULT NULL, cifs_remote_path VARCHAR(1024) DEFAULT NULL, cifs_username VARCHAR(255) DEFAULT NULL, cifs_domain VARCHAR(255) DEFAULT NULL, cifs_password LONGTEXT DEFAULT NULL, s3_bucket VARCHAR(255) DEFAULT NULL, s3_region VARCHAR(255) DEFAULT NULL, s3_endpoint VARCHAR(255) DEFAULT NULL, s3_path_prefix VARCHAR(1024) DEFAULT NULL, s3_use_path_style_endpoint TINYINT NOT NULL, s3_access_key_id VARCHAR(255) DEFAULT NULL, s3_secret_access_key LONGTEXT DEFAULT NULL, last_checked_at DATETIME DEFAULT NULL, last_check_status VARCHAR(20) DEFAULT NULL, last_check_message LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, created_by VARCHAR(255) DEFAULT NULL, updated_by VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE storage_backend');
    }
}
