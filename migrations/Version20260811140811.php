<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260811140811 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tiering config to storage_backend and switch log_object.tier to a numeric tier_rank snapshot';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE log_object ADD tier_rank INT NOT NULL, DROP tier');
        $this->addSql('ALTER TABLE storage_backend ADD tier_rank INT NOT NULL, ADD max_age_days INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE log_object ADD tier VARCHAR(20) NOT NULL, DROP tier_rank');
        $this->addSql('ALTER TABLE storage_backend DROP tier_rank, DROP max_age_days');
    }
}
