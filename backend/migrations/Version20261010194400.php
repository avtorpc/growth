<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010194400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed the demo shop and delivery tariffs from the assignment';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO shops (id, code, name, timezone, urgent_surcharge_kopecks) VALUES (1, 'demo', 'Тестовый цветочный магазин', 'Europe/Moscow', 20000)");
        $this->addSql(<<<'SQL'
            INSERT INTO delivery_tariffs (shop_id, district_code, base_price_kopecks, free_from_kopecks)
            VALUES (1, 'centre', 30000, 500000),
                   (1, 'north', 40000, 700000),
                   (1, 'south', 40000, 700000),
                   (1, 'suburb', 70000, NULL)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM orders WHERE shop_id = 1') > 0,
            'The demo shop has orders; remove them explicitly before rolling back the seed.');
        $this->addSql('DELETE FROM delivery_tariffs WHERE shop_id = 1');
        $this->addSql("DELETE FROM shops WHERE id = 1 AND code = 'demo'");
    }
}
