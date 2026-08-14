CREATE TABLE IF NOT EXISTS `document_sequences` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL,
  `doc_type` varchar(10) NOT NULL,
  `year_month` char(4) NOT NULL COMMENT 'YYMM in Buddhist year tail e.g. 2605 = à¸ž.à¸„. 2569',
  `last_seq` int(11) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_seq_tenant_type_month` (`line_account_id`,`doc_type`,`year_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='à¸•à¸±à¸§à¹€à¸¥à¸‚à¸¥à¸³à¸”à¸±à¸šà¹€à¸­à¸à¸ªà¸²à¸£ (atomic counter)';
