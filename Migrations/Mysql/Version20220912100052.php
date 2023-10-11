<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20220912100052 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Removes a duplicate index';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySqlPlatform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySqlPlatform'."
        );

        $indexes = $this->sm->listTableIndexes('neos_redirecthandler_databasestorage_domain_model_redirect');
        if (array_key_exists('sourceuripathhash', $indexes)) {
            $this->addSql('DROP INDEX sourceuripathhash ON neos_redirecthandler_databasestorage_domain_model_redirect');
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySqlPlatform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySqlPlatform'."
        );

        $this->addSql('CREATE INDEX sourceuripathhash ON neos_redirecthandler_databasestorage_domain_model_redirect (sourceuripathhash, host)');
    }
}
