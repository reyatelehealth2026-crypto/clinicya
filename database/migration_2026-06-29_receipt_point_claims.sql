-- Receipt point claims: tracks every receipt submitted for loyalty points
-- Unique key (line_account_id, claim_key) prevents duplicate awards.
-- claim_key = receipt_number when available, else MD5(shop|amount|date).

CREATE TABLE IF NOT EXISTS receipt_point_claims (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    line_account_id INT          DEFAULT NULL,
    user_id         INT          NOT NULL,
    claim_key       VARCHAR(255) NOT NULL,
    receipt_number  VARCHAR(100) DEFAULT NULL,
    shop_name       VARCHAR(255) DEFAULT NULL,
    total_amount    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    points_awarded  INT          NOT NULL DEFAULT 0,
    created_at      DATETIME     DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_claim (line_account_id, claim_key),
    KEY idx_user    (user_id),
    KEY idx_account (line_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Auto receipt-scan loyalty point claims';
