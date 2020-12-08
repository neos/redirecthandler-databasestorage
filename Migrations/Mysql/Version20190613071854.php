<?php
namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\Migrations\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

class Version20190613071854 extends AbstractMigration
{

    /**
     * @return string
     */
    public function getDescription(): string 
    {
        return 'Adds additional meta fields for giving a redirect more context';
    }

    /**
     * @param Schema $schema
     * @return void
     */
    public function up(Schema $schema): void 
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() != 'mysql', 'Migration can only be executed safely on "mysql".');

        $this->addSql('ALTER TABLE neos_redirecthandler_databasestorage_domain_model_redirect ADD creator VARCHAR(255) DEFAULT NULL, ADD comment VARCHAR(255) DEFAULT NULL, ADD type VARCHAR(255) DEFAULT \'generated\' NOT NULL, ADD startdatetime DATETIME DEFAULT NULL, ADD enddatetime DATETIME DEFAULT NULL');
    }

    /**
     * @param Schema $schema
     * @return void
     */
    public function down(Schema $schema): void 
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() != 'mysql', 'Migration can only be executed safely on "mysql".');

        $this->addSql('ALTER TABLE neos_redirecthandler_databasestorage_domain_model_redirect DROP creator, DROP comment, DROP type, DROP startdatetime, DROP enddatetime');
    }
}
