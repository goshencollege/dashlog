<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260813152636 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add job_run, tracking each scheduled job\'s most recent outcome for the health page.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE job_run (id INT AUTO_INCREMENT NOT NULL, job_name VARCHAR(64) NOT NULL, last_run_at DATETIME NOT NULL, status VARCHAR(20) NOT NULL, last_error LONGTEXT DEFAULT NULL, UNIQUE INDEX UNIQ_94A33751D9EF0847 (job_name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE job_run');
    }
}
