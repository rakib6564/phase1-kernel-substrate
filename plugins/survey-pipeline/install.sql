-- Survey Pipeline plugin — install schema.
-- All tables carry the `surveypipeline_` prefix, enforced by the loader.

CREATE TABLE IF NOT EXISTS `surveypipeline_connections` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`    INT UNSIGNED NOT NULL DEFAULT 1,
    `form_id`      INT UNSIGNED NOT NULL,
    `form_title`   VARCHAR(200) NOT NULL DEFAULT '',
    `survey_type`  VARCHAR(80)  NOT NULL DEFAULT 'general',
    `field_map`    TEXT         NOT NULL DEFAULT '{}',
    `connected_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `connected_by` INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_form` (`tenant_id`, `form_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `surveypipeline_orders` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`      INT UNSIGNED NOT NULL DEFAULT 1,
    `submission_id`  INT UNSIGNED NOT NULL,
    `form_id`        INT UNSIGNED NOT NULL,
    `order_ref`      VARCHAR(20)  NOT NULL,
    `stage`          ENUM('new','quoted','scheduled','active','delivered','cancelled')
                     NOT NULL DEFAULT 'new',
    `survey_type`    VARCHAR(80)  NOT NULL DEFAULT 'general',
    `vessel_name`    VARCHAR(190) NULL,
    `client_name`    VARCHAR(190) NULL,
    `client_email`   VARCHAR(190) NULL,
    `client_phone`   VARCHAR(60)  NULL,
    `survey_locale`  VARCHAR(255) NULL,
    `loa_ft`         VARCHAR(40)  NULL,
    `quoted_amount`  DECIMAL(10,2) NULL,
    `scheduled_at`   DATETIME NULL,
    `notes`          TEXT NULL,
    `assigned_to`    INT UNSIGNED NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_submission` (`tenant_id`, `submission_id`),
    UNIQUE KEY `tenant_ref`        (`tenant_id`, `order_ref`),
    KEY `tenant_stage`   (`tenant_id`, `stage`),
    KEY `tenant_form`    (`tenant_id`, `form_id`),
    KEY `tenant_created` (`tenant_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `surveypipeline_events` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`  INT UNSIGNED NOT NULL DEFAULT 1,
    `order_id`   INT UNSIGNED NOT NULL,
    `event_type` VARCHAR(60)  NOT NULL,
    `from_stage` VARCHAR(30)  NULL,
    `to_stage`   VARCHAR(30)  NULL,
    `note`       TEXT         NULL,
    `actor_id`   INT UNSIGNED NOT NULL DEFAULT 0,
    `actor_name` VARCHAR(190) NOT NULL DEFAULT '',
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `order_time`  (`order_id`, `created_at`),
    KEY `tenant_time` (`tenant_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default permission grants (role_id=2 = Manager)
INSERT IGNORE INTO `role_permissions` (`role_id`, `perm_key`, `granted`)
VALUES
    (2, 'surveypipeline.view',   1),
    (2, 'surveypipeline.manage', 1);
