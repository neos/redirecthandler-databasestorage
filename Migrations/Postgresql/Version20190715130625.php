<?php
namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\Migrations\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

class Version20190715130625 extends AbstractMigration
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
        $this->abortIf($this->connection->getDatabasePlatform()->getName() != 'postgresql', 'Migration can only be executed safely on "postgresql".');

        $this->addSql('ALTER TABLE neos_redirecthandler_databasestorage_domain_model_redirect ADD creator VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE neos_redirecthandler_databasestorage_domain_model_redirect ADD comment VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE neos_redirecthandler_databasestorage_domain_model_redirect ADD type VARCHAR(255) DEFAULT \'generated\' NOT NULL');
        $this->addSql('ALTER TABLE neos_redirecthandler_databasestorage_domain_model_redirect ADD startdatetime TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE neos_redirecthandler_databasestorage_domain_model_redirect ADD enddatetime TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    /**
     * @param Schema $schema
     * @return void
     */
    public function down(Schema $schema): void 
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() != 'postgresql', 'Migration can only be executed safely on "postgresql".');
        $this->addSql('ALTER TABLE neos_redirecthandler_databasestorage_domain_model_redirect DROP creator');
        $this->addSql('ALTER TABLE neos_redirecthandler_databasestorage_domain_model_redirect DROP comment');
        $this->addSql('ALTER TABLE neos_redirecthandler_databasestorage_domain_model_redirect DROP type');
        $this->addSql('ALTER TABLE neos_redirecthandler_databasestorage_domain_model_redirect DROP startdatetime');
        $this->addSql('ALTER TABLE neos_redirecthandler_databasestorage_domain_model_redirect DROP enddatetime');
    }
}
