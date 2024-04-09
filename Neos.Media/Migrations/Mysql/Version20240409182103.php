<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240409182103 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MariaDb1027Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MariaDb1027Platform'."
        );

        $this->addSql('ALTER TABLE neos_media_domain_model_image ADD focalpointx INT DEFAULT NULL, ADD focalpointy INT DEFAULT NULL');
        $this->addSql('ALTER TABLE neos_media_domain_model_imagevariant ADD focalpointx INT DEFAULT NULL, ADD focalpointy INT DEFAULT NULL');
        $this->addSql('ALTER TABLE neos_media_domain_model_thumbnail ADD focalpointx INT DEFAULT NULL, ADD focalpointy INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MariaDb1027Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MariaDb1027Platform'."
        );

        $this->addSql('ALTER TABLE neos_media_domain_model_image DROP focalpointx, DROP focalpointy');
        $this->addSql('ALTER TABLE neos_media_domain_model_imagevariant DROP focalpointx, DROP focalpointy');
        $this->addSql('ALTER TABLE neos_media_domain_model_thumbnail DROP focalpointx, DROP focalpointy');
    }
}
