<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010194300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create shops, delivery tariffs, orders and order items';
    }

    public function isTransactional(): bool
    {
        // MySQL DDL performs implicit commits.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE shops (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                name VARCHAR(255) NOT NULL,
                timezone VARCHAR(64) CHARACTER SET ascii NOT NULL,
                urgent_surcharge_kopecks BIGINT NOT NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                PRIMARY KEY (id),
                UNIQUE KEY uniq_shop_code (code),
                CONSTRAINT chk_shop_surcharge CHECK (urgent_surcharge_kopecks >= 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE delivery_tariffs (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                shop_id BIGINT UNSIGNED NOT NULL,
                district_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                base_price_kopecks BIGINT NOT NULL,
                free_from_kopecks BIGINT DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_tariff_shop_district (shop_id, district_code),
                CONSTRAINT fk_tariff_shop FOREIGN KEY (shop_id) REFERENCES shops (id),
                CONSTRAINT chk_tariff_base CHECK (base_price_kopecks >= 0),
                CONSTRAINT chk_tariff_threshold CHECK (free_from_kopecks IS NULL OR free_from_kopecks >= 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE orders (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                shop_id BIGINT UNSIGNED NOT NULL,
                marketplace_id VARCHAR(128) COLLATE utf8mb4_0900_as_cs NOT NULL,
                status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                customer_name VARCHAR(255) NOT NULL,
                customer_phone VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                marketplace_created_at DATETIME(6) NOT NULL,
                delivery_type VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                district_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                delivery_address VARCHAR(1000) DEFAULT NULL,
                delivery_from DATETIME(6) DEFAULT NULL,
                delivery_to DATETIME(6) DEFAULT NULL,
                timezone VARCHAR(64) CHARACTER SET ascii NOT NULL,
                items_total_kopecks BIGINT NOT NULL,
                marketplace_total_kopecks BIGINT NOT NULL,
                needs_review TINYINT(1) GENERATED ALWAYS AS (items_total_kopecks <> marketplace_total_kopecks) STORED,
                delivery_base_kopecks BIGINT NOT NULL,
                delivery_surcharge_kopecks BIGINT NOT NULL,
                delivery_total_kopecks BIGINT GENERATED ALWAYS AS (delivery_base_kopecks + delivery_surcharge_kopecks) STORED,
                grand_total_kopecks BIGINT GENERATED ALWAYS AS (items_total_kopecks + delivery_base_kopecks + delivery_surcharge_kopecks) STORED,
                imported_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                PRIMARY KEY (id),
                UNIQUE KEY uniq_order_shop_marketplace (shop_id, marketplace_id),
                KEY idx_order_shop_id (shop_id, id),
                KEY idx_order_shop_status_id (shop_id, status, id),
                CONSTRAINT fk_order_shop FOREIGN KEY (shop_id) REFERENCES shops (id),
                CONSTRAINT chk_order_marketplace_id CHECK (CHAR_LENGTH(TRIM(marketplace_id)) > 0),
                CONSTRAINT chk_order_status CHECK (status IN ('new', 'accepted', 'delivering', 'delivered', 'cancelled')),
                CONSTRAINT chk_order_customer_name CHECK (CHAR_LENGTH(TRIM(customer_name)) > 0),
                CONSTRAINT chk_order_customer_phone CHECK (REGEXP_LIKE(customer_phone, '^[+]7[0-9]{10}$', 'c')),
                CONSTRAINT chk_order_money CHECK (
                    items_total_kopecks >= 0 AND marketplace_total_kopecks >= 0
                    AND delivery_base_kopecks >= 0 AND delivery_surcharge_kopecks >= 0
                ),
                CONSTRAINT chk_order_delivery CHECK (
                    (delivery_type = 'pickup' AND district_code IS NULL AND delivery_address IS NULL
                        AND delivery_from IS NULL AND delivery_to IS NULL
                        AND delivery_base_kopecks = 0 AND delivery_surcharge_kopecks = 0)
                    OR
                    (delivery_type = 'delivery' AND district_code IS NOT NULL AND CHAR_LENGTH(TRIM(district_code)) > 0
                        AND delivery_address IS NOT NULL AND CHAR_LENGTH(TRIM(delivery_address)) > 0
                        AND delivery_from IS NOT NULL AND delivery_to IS NOT NULL AND delivery_to > delivery_from)
                )
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE order_items (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                order_id BIGINT UNSIGNED NOT NULL,
                position INT UNSIGNED NOT NULL,
                sku VARCHAR(128) DEFAULT NULL,
                name VARCHAR(255) NOT NULL,
                quantity INT UNSIGNED NOT NULL,
                unit_price_kopecks BIGINT NOT NULL,
                line_total_kopecks BIGINT GENERATED ALWAYS AS (quantity * unit_price_kopecks) STORED,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_item_order_position (order_id, position),
                CONSTRAINT fk_item_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
                CONSTRAINT chk_item_position CHECK (position > 0),
                CONSTRAINT chk_item_quantity CHECK (quantity > 0),
                CONSTRAINT chk_item_price CHECK (unit_price_kopecks >= 0),
                CONSTRAINT chk_item_name CHECK (CHAR_LENGTH(TRIM(name)) > 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE order_items');
        $this->addSql('DROP TABLE orders');
        $this->addSql('DROP TABLE delivery_tariffs');
        $this->addSql('DROP TABLE shops');
    }
}
