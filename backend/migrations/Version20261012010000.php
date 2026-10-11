<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the latest successful import outcome on each order';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        // Previous import results cannot be reconstructed from the order status.
        $this->addSql("ALTER TABLE orders ADD outcome VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL, ADD CONSTRAINT chk_order_outcome CHECK (outcome IN ('created', 'updated', 'unchanged'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE orders DROP CHECK chk_order_outcome, DROP COLUMN outcome');
    }
}
