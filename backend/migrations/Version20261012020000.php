<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012020000 extends AbstractMigration
{
    public function getDescription(): string { return 'Persist import runs, record results and rejected orders'; }
    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE order_imports (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                shop_id BIGINT UNSIGNED NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'processing',
                total INT UNSIGNED NOT NULL,
                summary JSON DEFAULT NULL,
                started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                finished_at DATETIME(6) DEFAULT NULL,
                KEY idx_import_shop_id (shop_id, id),
                CONSTRAINT fk_import_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
                CONSTRAINT chk_import_status CHECK (status IN ('processing', 'completed', 'failed'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE order_import_records (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                import_id BIGINT UNSIGNED NOT NULL,
                position INT UNSIGNED NOT NULL,
                marketplace_id VARCHAR(128) COLLATE utf8mb4_0900_as_cs DEFAULT NULL,
                order_id BIGINT UNSIGNED DEFAULT NULL,
                outcome VARCHAR(16) NOT NULL,
                raw_payload JSON NOT NULL,
                result_payload JSON NOT NULL,
                processed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                UNIQUE KEY uniq_import_position (import_id, position),
                KEY idx_import_record_order (order_id, id),
                CONSTRAINT fk_record_import FOREIGN KEY (import_id) REFERENCES order_imports(id) ON DELETE CASCADE,
                CONSTRAINT fk_record_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
                CONSTRAINT chk_record_position CHECK (position > 0),
                CONSTRAINT chk_record_outcome CHECK (outcome IN ('created', 'updated', 'unchanged', 'duplicate', 'rejected'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE rejected_orders (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                import_record_id BIGINT UNSIGNED NOT NULL,
                reason_code VARCHAR(64) NOT NULL,
                reason_message TEXT NOT NULL,
                raw_payload JSON NOT NULL,
                rejected_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                UNIQUE KEY uniq_rejected_record (import_record_id),
                CONSTRAINT fk_rejected_record FOREIGN KEY (import_record_id) REFERENCES order_import_records(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE rejected_orders');
        $this->addSql('DROP TABLE order_import_records');
        $this->addSql('DROP TABLE order_imports');
    }
}
