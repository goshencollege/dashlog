<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260807204912 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE saml_provider (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, is_active TINYINT NOT NULL, sp_entity_id VARCHAR(255) NOT NULL, sp_acs_url VARCHAR(255) NOT NULL, sp_slo_url VARCHAR(255) NOT NULL, sp_cert LONGTEXT NOT NULL, sp_private_key LONGTEXT NOT NULL, idp_entity_id VARCHAR(255) NOT NULL, idp_sso_url VARCHAR(255) NOT NULL, idp_cert LONGTEXT NOT NULL, session_lifetime_minutes INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, created_by VARCHAR(255) DEFAULT NULL, updated_by VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user_preference (id INT AUTO_INCREMENT NOT NULL, user_identifier VARCHAR(255) NOT NULL, theme VARCHAR(16) NOT NULL, UNIQUE INDEX UNIQ_FA0E76BFD0494586 (user_identifier), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sessions (sess_id VARBINARY(128) NOT NULL, sess_data LONGBLOB NOT NULL, sess_lifetime INT UNSIGNED NOT NULL, sess_time INT UNSIGNED NOT NULL, INDEX sess_lifetime_idx (sess_lifetime), PRIMARY KEY (sess_id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE lock_keys (key_id VARCHAR(64) NOT NULL, key_token VARCHAR(44) NOT NULL, key_expiration INT UNSIGNED NOT NULL, PRIMARY KEY (key_id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE saml_provider');
        $this->addSql('DROP TABLE user_preference');
        $this->addSql('DROP TABLE sessions');
        $this->addSql('DROP TABLE lock_keys');
    }
}
