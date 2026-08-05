-- DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
-- Archivo: database/schema.sql
-- Propósito: Define la estructura inicial de la base de datos de SIVI.
CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sedes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identificador VARCHAR(100) NOT NULL UNIQUE,
    cod_dd VARCHAR(10) NULL,
    departamento VARCHAR(120) NULL,
    cod_mm VARCHAR(10) NULL,
    municipio VARCHAR(120) NULL,
    tipo_sede VARCHAR(120) NULL,
    nombre_sede VARCHAR(255) NULL,
    direccion_original VARCHAR(500) NULL,
    direccion_actual VARCHAR(500) NULL,
    direccion_observacion TEXT NULL,
    email_contacto VARCHAR(255) NULL,
    email_institucional VARCHAR(255) NULL,
    telefono_contacto VARCHAR(80) NULL,
    directorio_fuente VARCHAR(255) NULL,
    directorio_sincronizado_en DATETIME NULL,
    directorio_estado ENUM('sin_revisar','coincide','diferencias','no_encontrada') NOT NULL DEFAULT 'sin_revisar',
    direccion_actualizada_por BIGINT UNSIGNED NULL,
    direccion_actualizada_en DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sedes_cod_dd (cod_dd),
    INDEX idx_sedes_municipio (municipio),
    INDEX idx_sedes_tipo (tipo_sede)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(180) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    default_password_hash VARCHAR(255) NULL,
    role ENUM('registrador','formador','admin_gi','superadmin') NOT NULL,
    sede_id BIGINT UNSIGNED NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_sede FOREIGN KEY (sede_id) REFERENCES sedes(id) ON DELETE SET NULL,
    INDEX idx_users_role (role),
    INDEX idx_users_sede (sede_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_departments (
    user_id BIGINT UNSIGNED NOT NULL,
    cod_dd VARCHAR(10) NOT NULL,
    PRIMARY KEY (user_id, cod_dd),
    CONSTRAINT fk_ud_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_ud_cod_dd (cod_dd)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS imports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    file_hash VARCHAR(64) NULL,
    source_kind ENUM('base','computadores','monitores','impresoras','mixto') NOT NULL DEFAULT 'base',
    rows_sedes INT NOT NULL DEFAULT 0,
    rows_equipment INT NOT NULL DEFAULT 0,
    assigned_equipment INT NOT NULL DEFAULT 0,
    unassigned_equipment INT NOT NULL DEFAULT 0,
    association_summary_json LONGTEXT NULL,
    review_required_equipment INT NOT NULL DEFAULT 0,
    status ENUM('procesando','completado','error') NOT NULL DEFAULT 'procesando',
    error_message TEXT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    CONSTRAINT fk_import_user FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_import_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS equipment (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    import_id BIGINT UNSIGNED NULL,
    source_key VARCHAR(64) NULL,
    name VARCHAR(255) NULL,
    alternate_user VARCHAR(255) NULL,
    last_contact DATETIME NULL,
    source_state VARCHAR(150) NULL,
    manufacturer VARCHAR(255) NULL,
    serial_number VARCHAR(255) NULL,
    equipment_type VARCHAR(180) NULL,
    asset_category ENUM('cpu','portatil','pc_todo_en_uno','monitor','impresora','escaner','ups','otro') NOT NULL DEFAULT 'cpu',
    category_source ENUM('almacen','glpi','manual','pendiente') NOT NULL DEFAULT 'pendiente',
    source_origin ENUM('glpi','almacen','manual') NOT NULL DEFAULT 'glpi',
    ownership_type ENUM('propio','comodato','donado_sin_legalizar','desconocido') NOT NULL DEFAULT 'desconocido',
    model VARCHAR(255) NULL,
    screen_size VARCHAR(80) NULL,
    connection_type VARCHAR(120) NULL,
    print_technology VARCHAR(120) NULL,
    os_version VARCHAR(120) NULL,
    architecture VARCHAR(80) NULL,
    os_name VARCHAR(255) NULL,
    processor TEXT NULL,
    last_update DATETIME NULL,
    memory VARCHAR(120) NULL,
    storage TEXT NULL,
    source_location TEXT NULL,
    ip_address VARCHAR(120) NULL,
    placa_rnec VARCHAR(120) NULL,
    original_sede_id BIGINT UNSIGNED NULL,
    current_sede_id BIGINT UNSIGNED NULL,
    association_method ENUM('hostname','usuario','location','fallback_distrital','fallback_delegacion','warehouse','manual','unassigned') NOT NULL DEFAULT 'unassigned',
    association_confidence ENUM('alta','media','baja','sin_asignar') NOT NULL DEFAULT 'sin_asignar',
    association_evidence LONGTEXT NULL,
    association_review_required TINYINT(1) NOT NULL DEFAULT 0,
    inventory_status ENUM('activo','inactivo','para_baja','dado_baja','en_almacen','en_mantenimiento','trasladado','desconocido') NOT NULL DEFAULT 'desconocido',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_eq_import FOREIGN KEY (import_id) REFERENCES imports(id) ON DELETE SET NULL,
    CONSTRAINT fk_eq_original_sede FOREIGN KEY (original_sede_id) REFERENCES sedes(id) ON DELETE SET NULL,
    CONSTRAINT fk_eq_current_sede FOREIGN KEY (current_sede_id) REFERENCES sedes(id) ON DELETE SET NULL,
    UNIQUE KEY uq_eq_source_key (source_key),
    INDEX idx_eq_name (name),
    INDEX idx_eq_serial (serial_number),
    INDEX idx_eq_placa (placa_rnec),
    INDEX idx_eq_serial_active (serial_number,active),
    INDEX idx_eq_placa_active (placa_rnec,active),
    INDEX idx_eq_sede (current_sede_id),
    INDEX idx_eq_type (equipment_type),
    INDEX idx_eq_category (asset_category),
    INDEX idx_eq_assoc (association_method)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaigns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    cutoff_date DATE NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    status ENUM('borrador','publicada','cerrada') NOT NULL DEFAULT 'borrador',
    scope_type ENUM('nacional','departamental') NOT NULL DEFAULT 'nacional',
    instructions TEXT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_campaign_user FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_campaign_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaign_departments (
    campaign_id BIGINT UNSIGNED NOT NULL,
    cod_dd VARCHAR(10) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (campaign_id,cod_dd),
    CONSTRAINT fk_cd_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    INDEX idx_cd_department (cod_dd)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaign_sedes (
    campaign_id BIGINT UNSIGNED NOT NULL,
    sede_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pendiente','en_diligenciamiento','enviado','en_revision','devuelto','aprobado','cerrado') NOT NULL DEFAULT 'pendiente',
    notified_at DATETIME NULL,
    notification_email VARCHAR(255) NULL,
    notification_status ENUM('pendiente','enviada','error','sin_correo') NOT NULL DEFAULT 'pendiente',
    notification_error TEXT NULL,
    submitted_at DATETIME NULL,
    approved_at DATETIME NULL,
    reopened_at DATETIME NULL,
    responsible_name VARCHAR(180) NULL,
    responsible_role VARCHAR(120) NULL,
    responsible_email VARCHAR(255) NULL,
    responsible_phone VARCHAR(80) NULL,
    contact_confirmed_by BIGINT UNSIGNED NULL,
    contact_confirmed_at DATETIME NULL,
    PRIMARY KEY (campaign_id, sede_id),
    CONSTRAINT fk_cs_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_cs_sede FOREIGN KEY (sede_id) REFERENCES sedes(id) ON DELETE CASCADE,
    INDEX idx_cs_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS equipment_validations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id BIGINT UNSIGNED NOT NULL,
    equipment_id BIGINT UNSIGNED NOT NULL,
    reported_by_sede_id BIGINT UNSIGNED NOT NULL,
    validation_status ENUM('pendiente','confirmado','con_correccion','no_encontrado','trasladado','reparacion','almacenado','pendiente_baja','dado_baja') NOT NULL DEFAULT 'pendiente',
    physical_condition ENUM('pendiente','activo','inactivo','para_baja','dado_baja','en_almacen','en_mantenimiento','trasladado','bueno','regular','malo','inoperativo','no_localizado') NOT NULL DEFAULT 'pendiente',
    ownership_type ENUM('propio','comodato','donado_sin_legalizar','desconocido') NOT NULL DEFAULT 'desconocido',
    belongs_status ENUM('pertenece','no_pertenece','otro_usuario','desconocido') NOT NULL DEFAULT 'desconocido',
    belongs_reason ENUM('trasladado','asignacion_incorrecta','prestamo','reparacion','baja','no_localizado','otro') NULL,
    placa_original VARCHAR(120) NULL,
    placa_reported VARCHAR(120) NULL,
    serial_original VARCHAR(255) NULL,
    serial_reported VARCHAR(255) NULL,
    serial_status ENUM('confirmado','corregido','sin_serial','ilegible','pendiente') NOT NULL DEFAULT 'pendiente',
    placa_status ENUM('confirmada','corregida','sin_placa','ilegible','pendiente') NOT NULL DEFAULT 'pendiente',
    destination_sede_id BIGINT UNSIGNED NULL,
    destination_text VARCHAR(500) NULL,
    disposal_date DATE NULL,
    disposal_document VARCHAR(255) NULL,
    notes TEXT NULL,
    evidence_path VARCHAR(500) NULL,
    submitted_by BIGINT UNSIGNED NULL,
    submitted_at DATETIME NULL,
    review_status ENUM('pendiente','aprobada','devuelta','reasignada','cerrada') NOT NULL DEFAULT 'pendiente',
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    review_notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_validation (campaign_id, equipment_id, reported_by_sede_id),
    CONSTRAINT fk_ev_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_ev_equipment FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE CASCADE,
    CONSTRAINT fk_ev_report_sede FOREIGN KEY (reported_by_sede_id) REFERENCES sedes(id),
    CONSTRAINT fk_ev_dest_sede FOREIGN KEY (destination_sede_id) REFERENCES sedes(id) ON DELETE SET NULL,
    CONSTRAINT fk_ev_submit_user FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_ev_review_user FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_ev_campaign (campaign_id),
    INDEX idx_ev_status (validation_status),
    INDEX idx_ev_belongs (belongs_status),
    INDEX idx_ev_review (review_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS additional_equipment (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id BIGINT UNSIGNED NOT NULL,
    sede_id BIGINT UNSIGNED NOT NULL,
    equipment_type VARCHAR(180) NULL,
    asset_category ENUM('cpu','portatil','pc_todo_en_uno','monitor','impresora','escaner','ups','otro') NOT NULL DEFAULT 'otro',
    name VARCHAR(255) NULL,
    manufacturer VARCHAR(255) NULL,
    model VARCHAR(255) NULL,
    screen_size VARCHAR(80) NULL,
    connection_type VARCHAR(120) NULL,
    print_technology VARCHAR(120) NULL,
    serial_number VARCHAR(255) NULL,
    placa_rnec VARCHAR(120) NULL,
    os_name VARCHAR(255) NULL,
    os_version VARCHAR(120) NULL,
    processor TEXT NULL,
    memory VARCHAR(120) NULL,
    assigned_user VARCHAR(255) NULL,
    equipment_state VARCHAR(150) NULL,
    physical_location VARCHAR(255) NULL,
    notes TEXT NULL,
    evidence_path VARCHAR(500) NULL,
    review_status ENUM('pendiente','aprobado','rechazado') NOT NULL DEFAULT 'pendiente',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    CONSTRAINT fk_ae_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_ae_sede FOREIGN KEY (sede_id) REFERENCES sedes(id),
    CONSTRAINT fk_ae_user FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_ae_review_user FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_ae_sede (sede_id),
    INDEX idx_ae_review (review_status),
    INDEX idx_ae_serial_review (serial_number,review_status),
    INDEX idx_ae_placa_review (placa_rnec,review_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE IF NOT EXISTS mobile_scan_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token_hash CHAR(64) NOT NULL UNIQUE,
    pairing_code CHAR(6) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    campaign_id BIGINT UNSIGNED NULL,
    sede_id BIGINT UNSIGNED NULL,
    purpose VARCHAR(60) NOT NULL DEFAULT 'inventory_capture',
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    scan_sequence INT UNSIGNED NOT NULL DEFAULT 0,
    ack_sequence INT UNSIGNED NOT NULL DEFAULT 0,
    last_target VARCHAR(30) NULL,
    last_value VARCHAR(255) NULL,
    last_format VARCHAR(80) NULL,
    last_request_id VARCHAR(80) NULL,
    last_scanned_at DATETIME NULL,
    last_acknowledged_at DATETIME NULL,
    mobile_last_seen_at DATETIME NULL,
    renewed_at DATETIME NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_mobile_scan_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_mobile_scan_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_mobile_scan_sede FOREIGN KEY (sede_id) REFERENCES sedes(id) ON DELETE CASCADE,
    INDEX idx_mobile_scan_user_status (user_id,status),
    INDEX idx_mobile_scan_expiry (status,expires_at),
    INDEX idx_mobile_scan_code (pairing_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Las columnas de control móvil ya forman parte de la definición anterior.
-- No se ejecutan ALTER TABLE adicionales para evitar duplicados en una instalación limpia.

CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id BIGINT UNSIGNED NULL,
    sede_id BIGINT UNSIGNED NULL,
    recipient VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    status ENUM('pendiente','enviado','error','registrado') NOT NULL DEFAULT 'pendiente',
    error_message TEXT NULL,
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_not_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL,
    CONSTRAINT fk_not_sede FOREIGN KEY (sede_id) REFERENCES sedes(id) ON DELETE SET NULL,
    INDEX idx_not_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(120) NOT NULL,
    entity_type VARCHAR(120) NULL,
    entity_id BIGINT UNSIGNED NULL,
    old_values JSON NULL,
    new_values JSON NULL,
    ip_address VARCHAR(80) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS warehouse_imports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    file_hash VARCHAR(64) NULL,
    rows_assets INT NOT NULL DEFAULT 0,
    rows_with_internal_serial INT NOT NULL DEFAULT 0,
    rows_invalid_plates INT NOT NULL DEFAULT 0,
    rows_selected_categories INT NOT NULL DEFAULT 0,
    warehouse_only_equipment INT NOT NULL DEFAULT 0,
    warehouse_only_assigned INT NOT NULL DEFAULT 0,
    matched_equipment INT NOT NULL DEFAULT 0,
    ambiguous_equipment INT NOT NULL DEFAULT 0,
    unmatched_equipment INT NOT NULL DEFAULT 0,
    status ENUM('procesando','completado','error') NOT NULL DEFAULT 'procesando',
    error_message TEXT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    CONSTRAINT fk_warehouse_import_user FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_warehouse_import_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS warehouse_assets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    import_id BIGINT UNSIGNED NOT NULL,
    warehouse_key CHAR(64) NOT NULL,
    placa_raw VARCHAR(120) NULL,
    placa_rnec VARCHAR(120) NULL,
    description VARCHAR(255) NULL,
    product_name VARCHAR(255) NULL,
    asset_category ENUM('cpu','portatil','pc_todo_en_uno','monitor','impresora','escaner','ups','otro') NULL,
    warehouse_serial VARCHAR(255) NULL,
    serial_internal VARCHAR(255) NULL,
    serial_internal_normalized VARCHAR(255) NULL,
    brand VARCHAR(255) NULL,
    reference VARCHAR(255) NULL,
    reference_normalized VARCHAR(255) NULL,
    state_code VARCHAR(80) NULL,
    current_state VARCHAR(180) NULL,
    branch VARCHAR(255) NULL,
    cost_center VARCHAR(255) NULL,
    responsible VARCHAR(255) NULL,
    holder VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_warehouse_key (warehouse_key),
    CONSTRAINT fk_warehouse_asset_import FOREIGN KEY (import_id) REFERENCES warehouse_imports(id) ON DELETE CASCADE,
    INDEX idx_warehouse_serial_internal (serial_internal_normalized),
    INDEX idx_warehouse_reference (reference_normalized),
    INDEX idx_warehouse_description (description),
    INDEX idx_warehouse_category (asset_category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE imports ADD COLUMN IF NOT EXISTS source_kind ENUM('base','computadores','monitores','impresoras','mixto') NOT NULL DEFAULT 'base' AFTER file_hash;
ALTER TABLE equipment ADD COLUMN IF NOT EXISTS asset_category ENUM('cpu','portatil','pc_todo_en_uno','monitor','impresora','escaner','ups','otro') NOT NULL DEFAULT 'cpu' AFTER equipment_type;
ALTER TABLE equipment ADD COLUMN IF NOT EXISTS screen_size VARCHAR(80) NULL AFTER model;
ALTER TABLE equipment ADD COLUMN IF NOT EXISTS connection_type VARCHAR(120) NULL AFTER screen_size;
ALTER TABLE equipment ADD COLUMN IF NOT EXISTS print_technology VARCHAR(120) NULL AFTER connection_type;
ALTER TABLE equipment ADD INDEX IF NOT EXISTS idx_eq_category (asset_category);
ALTER TABLE additional_equipment ADD COLUMN IF NOT EXISTS asset_category ENUM('cpu','portatil','pc_todo_en_uno','monitor','impresora','escaner','ups','otro') NOT NULL DEFAULT 'otro' AFTER equipment_type;
ALTER TABLE additional_equipment ADD COLUMN IF NOT EXISTS screen_size VARCHAR(80) NULL AFTER model;
ALTER TABLE additional_equipment ADD COLUMN IF NOT EXISTS connection_type VARCHAR(120) NULL AFTER screen_size;
ALTER TABLE additional_equipment ADD COLUMN IF NOT EXISTS print_technology VARCHAR(120) NULL AFTER connection_type;

ALTER TABLE equipment ADD COLUMN IF NOT EXISTS placa_almacen VARCHAR(120) NULL AFTER placa_rnec;
ALTER TABLE equipment ADD COLUMN IF NOT EXISTS warehouse_asset_id BIGINT UNSIGNED NULL AFTER placa_almacen;
ALTER TABLE equipment ADD COLUMN IF NOT EXISTS warehouse_match_status ENUM('pendiente','coincidencia_serial','coincidencia_referencia','origen_almacen','ambigua','no_encontrada','sin_serial') NOT NULL DEFAULT 'pendiente' AFTER warehouse_asset_id;
ALTER TABLE equipment ADD COLUMN IF NOT EXISTS warehouse_match_count INT NOT NULL DEFAULT 0 AFTER warehouse_match_status;
ALTER TABLE equipment ADD COLUMN IF NOT EXISTS warehouse_matched_at DATETIME NULL AFTER warehouse_match_count;
ALTER TABLE equipment ADD INDEX IF NOT EXISTS idx_eq_placa_almacen (placa_almacen);
ALTER TABLE equipment ADD INDEX IF NOT EXISTS idx_eq_warehouse_status (warehouse_match_status);

ALTER TABLE additional_equipment ADD COLUMN IF NOT EXISTS os_name VARCHAR(255) NULL AFTER placa_rnec;
ALTER TABLE additional_equipment ADD COLUMN IF NOT EXISTS os_version VARCHAR(120) NULL AFTER os_name;
ALTER TABLE additional_equipment ADD COLUMN IF NOT EXISTS processor TEXT NULL AFTER os_version;
ALTER TABLE additional_equipment ADD COLUMN IF NOT EXISTS memory VARCHAR(120) NULL AFTER processor;

ALTER TABLE warehouse_imports ADD COLUMN IF NOT EXISTS rows_invalid_plates INT NOT NULL DEFAULT 0 AFTER rows_with_internal_serial;

-- Normalización compatible con datos históricos que traían ocho dígitos sin guion o con guion bajo.
UPDATE equipment SET placa_rnec=CONCAT(SUBSTRING(REPLACE(placa_rnec,'_',''),1,3),'-',SUBSTRING(REPLACE(placa_rnec,'_',''),4,5)) WHERE placa_rnec REGEXP '^000[_]?[0-9]{5}$';
UPDATE equipment SET placa_almacen=CONCAT(SUBSTRING(REPLACE(placa_almacen,'_',''),1,3),'-',SUBSTRING(REPLACE(placa_almacen,'_',''),4,5)) WHERE placa_almacen REGEXP '^000[_]?[0-9]{5}$';
UPDATE equipment_validations SET placa_original=CONCAT(SUBSTRING(REPLACE(placa_original,'_',''),1,3),'-',SUBSTRING(REPLACE(placa_original,'_',''),4,5)) WHERE placa_original REGEXP '^000[_]?[0-9]{5}$';
UPDATE equipment_validations SET placa_reported=CONCAT(SUBSTRING(REPLACE(placa_reported,'_',''),1,3),'-',SUBSTRING(REPLACE(placa_reported,'_',''),4,5)) WHERE placa_reported REGEXP '^000[_]?[0-9]{5}$';
UPDATE additional_equipment SET placa_rnec=CONCAT(SUBSTRING(REPLACE(placa_rnec,'_',''),1,3),'-',SUBSTRING(REPLACE(placa_rnec,'_',''),4,5)) WHERE placa_rnec REGEXP '^000[_]?[0-9]{5}$';

CREATE TABLE IF NOT EXISTS login_throttles (
    throttle_type ENUM('email','ip') NOT NULL,
    throttle_key CHAR(64) NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    first_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_until DATETIME NULL,
    PRIMARY KEY (throttle_type, throttle_key),
    INDEX idx_login_throttles_locked (locked_until),
    INDEX idx_login_throttles_last (last_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE equipment ADD COLUMN IF NOT EXISTS source_key VARCHAR(64) NULL AFTER import_id;
ALTER TABLE equipment MODIFY COLUMN association_method ENUM('hostname','usuario','location','fallback_distrital','fallback_delegacion','warehouse','manual','unassigned') NOT NULL DEFAULT 'unassigned';
ALTER TABLE equipment ADD UNIQUE INDEX IF NOT EXISTS uq_eq_source_key (source_key);
ALTER TABLE equipment_validations ADD COLUMN IF NOT EXISTS serial_original VARCHAR(255) NULL AFTER placa_reported;
ALTER TABLE equipment_validations ADD COLUMN IF NOT EXISTS serial_reported VARCHAR(255) NULL AFTER serial_original;
ALTER TABLE equipment_validations ADD COLUMN IF NOT EXISTS serial_status ENUM('confirmado','corregido','sin_serial','ilegible','pendiente') NOT NULL DEFAULT 'pendiente' AFTER serial_reported;


-- Categorías institucionales tomadas del inventario de Almacén.
-- La transición incluye temporalmente el valor anterior "computador" para preservar datos existentes.
ALTER TABLE equipment MODIFY COLUMN asset_category ENUM('computador','cpu','portatil','pc_todo_en_uno','monitor','impresora','escaner','ups','otro') NOT NULL DEFAULT 'cpu';
ALTER TABLE additional_equipment MODIFY COLUMN asset_category ENUM('computador','cpu','portatil','pc_todo_en_uno','monitor','impresora','escaner','ups','otro') NOT NULL DEFAULT 'otro';
ALTER TABLE equipment MODIFY COLUMN association_method ENUM('hostname','usuario','location','fallback_distrital','fallback_delegacion','warehouse','manual','unassigned') NOT NULL DEFAULT 'unassigned';
ALTER TABLE equipment ADD COLUMN IF NOT EXISTS category_source ENUM('almacen','glpi','manual','pendiente') NOT NULL DEFAULT 'pendiente' AFTER asset_category;
ALTER TABLE equipment ADD COLUMN IF NOT EXISTS source_origin ENUM('glpi','almacen','manual') NOT NULL DEFAULT 'glpi' AFTER category_source;
ALTER TABLE warehouse_assets ADD COLUMN IF NOT EXISTS warehouse_key CHAR(64) NULL AFTER import_id;
ALTER TABLE warehouse_assets ADD COLUMN IF NOT EXISTS placa_raw VARCHAR(120) NULL AFTER warehouse_key;
UPDATE warehouse_assets SET placa_raw=placa_rnec WHERE placa_raw IS NULL;
UPDATE warehouse_assets SET warehouse_key=SHA2(CONCAT('legacy|',id,'|',COALESCE(placa_rnec,'')),256) WHERE warehouse_key IS NULL OR warehouse_key='';
ALTER TABLE warehouse_assets MODIFY COLUMN warehouse_key CHAR(64) NOT NULL;
ALTER TABLE warehouse_assets MODIFY COLUMN placa_rnec VARCHAR(120) NULL;
DROP INDEX IF EXISTS uq_warehouse_plate ON warehouse_assets;
ALTER TABLE warehouse_assets ADD UNIQUE INDEX IF NOT EXISTS uq_warehouse_key (warehouse_key);
ALTER TABLE warehouse_assets ADD COLUMN IF NOT EXISTS asset_category ENUM('cpu','portatil','pc_todo_en_uno','monitor','impresora','escaner','ups','otro') NULL AFTER product_name;
ALTER TABLE warehouse_assets ADD INDEX IF NOT EXISTS idx_warehouse_category (asset_category);
ALTER TABLE warehouse_imports ADD COLUMN IF NOT EXISTS rows_selected_categories INT NOT NULL DEFAULT 0 AFTER rows_invalid_plates;
ALTER TABLE warehouse_imports ADD COLUMN IF NOT EXISTS warehouse_only_equipment INT NOT NULL DEFAULT 0 AFTER rows_selected_categories;
ALTER TABLE warehouse_imports ADD COLUMN IF NOT EXISTS warehouse_only_assigned INT NOT NULL DEFAULT 0 AFTER warehouse_only_equipment;
ALTER TABLE equipment MODIFY COLUMN warehouse_match_status ENUM('pendiente','coincidencia_serial','coincidencia_referencia','origen_almacen','ambigua','no_encontrada','sin_serial') NOT NULL DEFAULT 'pendiente';

-- Migración de la categoría genérica anterior de computadores.
UPDATE equipment
SET asset_category = CASE
    WHEN LOWER(CONCAT_WS(' ',equipment_type,name,model)) LIKE '%todo en uno%'
      OR LOWER(CONCAT_WS(' ',equipment_type,name,model)) LIKE '%all in one%'
      OR LOWER(CONCAT_WS(' ',equipment_type,name,model)) LIKE '%all-in-one%'
      OR LOWER(CONCAT_WS(' ',equipment_type,name,model)) LIKE '% imac%'
      OR LOWER(CONCAT_WS(' ',equipment_type,name,model)) LIKE 'imac%'
      THEN 'pc_todo_en_uno'
    WHEN LOWER(CONCAT_WS(' ',equipment_type,name,model)) LIKE '%portatil%'
      OR LOWER(CONCAT_WS(' ',equipment_type,name,model)) LIKE '%portátil%'
      OR LOWER(CONCAT_WS(' ',equipment_type,name,model)) LIKE '%laptop%'
      OR LOWER(CONCAT_WS(' ',equipment_type,name,model)) LIKE '%notebook%'
      THEN 'portatil'
    ELSE 'cpu'
END,
category_source = CASE WHEN category_source='almacen' THEN 'almacen' ELSE 'glpi' END
WHERE asset_category = 'computador';

UPDATE additional_equipment
SET asset_category = CASE
    WHEN LOWER(CONCAT_WS(' ',equipment_type,name,model)) LIKE '%todo en uno%'
      OR LOWER(CONCAT_WS(' ',equipment_type,name,model)) LIKE '%all in one%'
      OR LOWER(CONCAT_WS(' ',equipment_type,name,model)) LIKE '%all-in-one%'
      OR LOWER(CONCAT_WS(' ',equipment_type,name,model)) LIKE '% imac%'
      OR LOWER(CONCAT_WS(' ',equipment_type,name,model)) LIKE 'imac%'
      THEN 'pc_todo_en_uno'
    WHEN LOWER(CONCAT_WS(' ',equipment_type,name,model)) LIKE '%portatil%'
      OR LOWER(CONCAT_WS(' ',equipment_type,name,model)) LIKE '%portátil%'
      OR LOWER(CONCAT_WS(' ',equipment_type,name,model)) LIKE '%laptop%'
      OR LOWER(CONCAT_WS(' ',equipment_type,name,model)) LIKE '%notebook%'
      THEN 'portatil'
    ELSE 'cpu'
END
WHERE asset_category = 'computador';

UPDATE equipment SET category_source='glpi' WHERE category_source='pendiente' AND source_origin='glpi';

-- Cierre de la transición: solo permanecen las categorías institucionales.
ALTER TABLE equipment MODIFY COLUMN asset_category ENUM('cpu','portatil','pc_todo_en_uno','monitor','impresora','escaner','ups','otro') NOT NULL DEFAULT 'cpu';
ALTER TABLE additional_equipment MODIFY COLUMN asset_category ENUM('cpu','portatil','pc_todo_en_uno','monitor','impresora','escaner','ups','otro') NOT NULL DEFAULT 'otro';

-- Mejoras de control, trazabilidad y gestión de novedades.
CREATE TABLE IF NOT EXISTS campaign_status_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id BIGINT UNSIGNED NOT NULL,
    sede_id BIGINT UNSIGNED NOT NULL,
    previous_status VARCHAR(40) NULL,
    new_status VARCHAR(40) NOT NULL,
    changed_by BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_csh_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_csh_sede FOREIGN KEY (sede_id) REFERENCES sedes(id) ON DELETE CASCADE,
    CONSTRAINT fk_csh_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_csh_campaign_sede (campaign_id,sede_id),
    INDEX idx_csh_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS incidents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id BIGINT UNSIGNED NOT NULL,
    sede_id BIGINT UNSIGNED NOT NULL,
    equipment_id BIGINT UNSIGNED NULL,
    incident_type ENUM('equipo_no_encontrado','no_pertenece','placa','serial','traslado','reparacion','baja','duplicado','datos','otro') NOT NULL,
    priority ENUM('baja','media','alta','critica') NOT NULL DEFAULT 'media',
    status ENUM('abierta','en_gestion','resuelta','cerrada') NOT NULL DEFAULT 'abierta',
    description TEXT NOT NULL,
    reported_by BIGINT UNSIGNED NOT NULL,
    assigned_to BIGINT UNSIGNED NULL,
    resolved_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_inc_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_inc_sede FOREIGN KEY (sede_id) REFERENCES sedes(id) ON DELETE CASCADE,
    CONSTRAINT fk_inc_equipment FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE SET NULL,
    CONSTRAINT fk_inc_reporter FOREIGN KEY (reported_by) REFERENCES users(id),
    CONSTRAINT fk_inc_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_inc_scope (campaign_id,sede_id,status),
    INDEX idx_inc_priority (priority,status),
    INDEX idx_inc_equipment (equipment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS incident_comments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    incident_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    comment TEXT NOT NULL,
    new_status VARCHAR(40) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ic_incident FOREIGN KEY (incident_id) REFERENCES incidents(id) ON DELETE CASCADE,
    CONSTRAINT fk_ic_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_ic_incident_created (incident_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS equipment_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    equipment_id BIGINT UNSIGNED NOT NULL,
    campaign_id BIGINT UNSIGNED NULL,
    event_type VARCHAR(80) NOT NULL,
    origin_sede_id BIGINT UNSIGNED NULL,
    destination_sede_id BIGINT UNSIGNED NULL,
    description TEXT NULL,
    old_values JSON NULL,
    new_values JSON NULL,
    changed_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_eh_equipment FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE CASCADE,
    CONSTRAINT fk_eh_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL,
    CONSTRAINT fk_eh_origin FOREIGN KEY (origin_sede_id) REFERENCES sedes(id) ON DELETE SET NULL,
    CONSTRAINT fk_eh_destination FOREIGN KEY (destination_sede_id) REFERENCES sedes(id) ON DELETE SET NULL,
    CONSTRAINT fk_eh_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_eh_equipment_created (equipment_id,created_at),
    INDEX idx_eh_campaign (campaign_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE equipment ADD INDEX IF NOT EXISTS idx_eq_dashboard (current_sede_id,active,asset_category);
ALTER TABLE equipment_validations ADD INDEX IF NOT EXISTS idx_ev_dashboard (campaign_id,reported_by_sede_id,validation_status);
ALTER TABLE campaign_sedes ADD INDEX IF NOT EXISTS idx_cs_dashboard (campaign_id,status,sede_id);

-- Superadministrador y cambio obligatorio de contraseña
ALTER TABLE users MODIFY COLUMN role ENUM('registrador','formador','admin_gi','superadmin') NOT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS default_password_hash VARCHAR(255) NULL AFTER password_hash;
ALTER TABLE users ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER active;
CREATE INDEX IF NOT EXISTS idx_users_must_change_password ON users (must_change_password);

ALTER TABLE equipment_validations ADD COLUMN IF NOT EXISTS physical_condition ENUM('pendiente','bueno','regular','malo','inoperativo','no_localizado') NOT NULL DEFAULT 'pendiente' AFTER validation_status;
ALTER TABLE equipment_validations ADD COLUMN IF NOT EXISTS belongs_reason ENUM('trasladado','asignacion_incorrecta','prestamo','reparacion','baja','no_localizado','otro') NULL AFTER belongs_status;

-- Integración con Directorio Institucional y trazabilidad de notificaciones
ALTER TABLE sedes ADD COLUMN IF NOT EXISTS email_institucional VARCHAR(255) NULL AFTER email_contacto;
ALTER TABLE sedes ADD COLUMN IF NOT EXISTS directorio_fuente VARCHAR(255) NULL AFTER telefono_contacto;
ALTER TABLE sedes ADD COLUMN IF NOT EXISTS directorio_sincronizado_en DATETIME NULL AFTER directorio_fuente;
ALTER TABLE sedes ADD COLUMN IF NOT EXISTS directorio_estado ENUM('sin_revisar','coincide','diferencias','no_encontrada') NOT NULL DEFAULT 'sin_revisar' AFTER directorio_sincronizado_en;
ALTER TABLE campaign_sedes ADD COLUMN IF NOT EXISTS notification_email VARCHAR(255) NULL AFTER notified_at;
ALTER TABLE campaign_sedes ADD COLUMN IF NOT EXISTS notification_status ENUM('pendiente','enviada','error','sin_correo') NOT NULL DEFAULT 'pendiente' AFTER notification_email;
ALTER TABLE campaign_sedes ADD COLUMN IF NOT EXISTS notification_error TEXT NULL AFTER notification_status;
ALTER TABLE campaign_sedes ADD COLUMN IF NOT EXISTS responsible_name VARCHAR(180) NULL AFTER reopened_at;
ALTER TABLE campaign_sedes ADD COLUMN IF NOT EXISTS responsible_role VARCHAR(120) NULL AFTER responsible_name;
ALTER TABLE campaign_sedes ADD COLUMN IF NOT EXISTS responsible_email VARCHAR(255) NULL AFTER responsible_role;
ALTER TABLE campaign_sedes ADD COLUMN IF NOT EXISTS responsible_phone VARCHAR(80) NULL AFTER responsible_email;
ALTER TABLE campaign_sedes ADD COLUMN IF NOT EXISTS contact_confirmed_by BIGINT UNSIGNED NULL AFTER responsible_phone;
ALTER TABLE campaign_sedes ADD COLUMN IF NOT EXISTS contact_confirmed_at DATETIME NULL AFTER contact_confirmed_by;
ALTER TABLE campaign_sedes ADD INDEX IF NOT EXISTS idx_cs_contact_pending (campaign_id,contact_confirmed_at);

-- Mejoras del maestro de sedes y Directorio Institucional
ALTER TABLE sedes ADD COLUMN IF NOT EXISTS horario_atencion VARCHAR(500) NULL AFTER telefono_contacto;
ALTER TABLE sedes ADD COLUMN IF NOT EXISTS directorio_clave CHAR(64) NULL AFTER horario_atencion;
ALTER TABLE sedes ADD UNIQUE INDEX IF NOT EXISTS uq_sedes_directorio_clave (directorio_clave);

CREATE TABLE IF NOT EXISTS directory_imports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    original_name VARCHAR(255) NOT NULL,
    file_hash CHAR(64) NULL,
    rows_processed INT NOT NULL DEFAULT 0,
    rows_created INT NOT NULL DEFAULT 0,
    rows_updated INT NOT NULL DEFAULT 0,
    rows_unchanged INT NOT NULL DEFAULT 0,
    rows_duplicates INT NOT NULL DEFAULT 0,
    rows_invalid INT NOT NULL DEFAULT 0,
    invalid_emails INT NOT NULL DEFAULT 0,
    status ENUM('procesando','completado','revertido','error') NOT NULL DEFAULT 'procesando',
    error_message TEXT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    CONSTRAINT fk_directory_import_user FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_directory_import_status(status),
    INDEX idx_directory_import_hash(file_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS directory_changes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    import_id BIGINT UNSIGNED NOT NULL,
    sede_id BIGINT UNSIGNED NOT NULL,
    changes_json JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_directory_change_import FOREIGN KEY(import_id) REFERENCES directory_imports(id) ON DELETE CASCADE,
    CONSTRAINT fk_directory_change_sede FOREIGN KEY(sede_id) REFERENCES sedes(id) ON DELETE CASCADE,
    INDEX idx_directory_change_import(import_id),
    INDEX idx_directory_change_sede(sede_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- RC3: control de calidad, homologación, traslados, correcciones y cierre formal.
ALTER TABLE campaign_sedes ADD COLUMN IF NOT EXISTS closed_by BIGINT UNSIGNED NULL AFTER reopened_at;
ALTER TABLE campaign_sedes ADD COLUMN IF NOT EXISTS closed_at DATETIME NULL AFTER closed_by;
ALTER TABLE campaign_sedes ADD COLUMN IF NOT EXISTS closure_notes TEXT NULL AFTER closed_at;
ALTER TABLE campaign_sedes ADD COLUMN IF NOT EXISTS closure_code VARCHAR(64) NULL AFTER closure_notes;
ALTER TABLE campaign_sedes ADD COLUMN IF NOT EXISTS correction_requested_at DATETIME NULL AFTER closure_code;
ALTER TABLE campaign_sedes ADD COLUMN IF NOT EXISTS correction_requested_by BIGINT UNSIGNED NULL AFTER correction_requested_at;
ALTER TABLE campaign_sedes ADD COLUMN IF NOT EXISTS correction_notes TEXT NULL AFTER correction_requested_by;

CREATE TABLE IF NOT EXISTS data_homologations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    data_type ENUM('departamento','municipio','tipo_sede','tipo_equipo','marca','modelo','estado') NOT NULL,
    source_value VARCHAR(255) NOT NULL,
    normalized_value VARCHAR(255) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_homologation (data_type,source_value),
    CONSTRAINT fk_homologation_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_homologation_type (data_type,active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS equipment_transfers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id BIGINT UNSIGNED NULL,
    equipment_id BIGINT UNSIGNED NOT NULL,
    origin_sede_id BIGINT UNSIGNED NULL,
    destination_sede_id BIGINT UNSIGNED NOT NULL,
    reason TEXT NOT NULL,
    support_path VARCHAR(500) NULL,
    status ENUM('reportado','pendiente_aprobacion','aprobado','rechazado','aplicado') NOT NULL DEFAULT 'reportado',
    requested_by BIGINT UNSIGNED NOT NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    applied_at DATETIME NULL,
    review_notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_transfer_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL,
    CONSTRAINT fk_transfer_equipment FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE CASCADE,
    CONSTRAINT fk_transfer_origin FOREIGN KEY (origin_sede_id) REFERENCES sedes(id) ON DELETE SET NULL,
    CONSTRAINT fk_transfer_destination FOREIGN KEY (destination_sede_id) REFERENCES sedes(id),
    CONSTRAINT fk_transfer_requester FOREIGN KEY (requested_by) REFERENCES users(id),
    CONSTRAINT fk_transfer_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_transfer_status (status),
    INDEX idx_transfer_equipment (equipment_id),
    INDEX idx_transfer_campaign (campaign_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS validation_corrections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    validation_id BIGINT UNSIGNED NOT NULL,
    requested_by BIGINT UNSIGNED NOT NULL,
    assigned_to BIGINT UNSIGNED NULL,
    notes TEXT NOT NULL,
    status ENUM('pendiente','corregida','aprobada','cancelada') NOT NULL DEFAULT 'pendiente',
    corrected_at DATETIME NULL,
    approved_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_vc_validation FOREIGN KEY (validation_id) REFERENCES equipment_validations(id) ON DELETE CASCADE,
    CONSTRAINT fk_vc_requester FOREIGN KEY (requested_by) REFERENCES users(id),
    CONSTRAINT fk_vc_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_vc_status (status),
    INDEX idx_vc_validation (validation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS import_errors (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    import_type ENUM('inventario','almacen','usuarios','directorio') NOT NULL,
    import_id BIGINT UNSIGNED NULL,
    source_row INT NULL,
    field_name VARCHAR(120) NULL,
    error_code VARCHAR(80) NULL,
    error_message TEXT NOT NULL,
    suggested_action TEXT NULL,
    raw_data JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_import_errors_ref (import_type,import_id),
    INDEX idx_import_errors_code (error_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- RC4: notificaciones internas, aceptación electrónica, reapertura controlada y evidencias.
ALTER TABLE campaign_sedes ADD COLUMN IF NOT EXISTS acceptance_text TEXT NULL AFTER closure_code;
ALTER TABLE campaign_sedes ADD COLUMN IF NOT EXISTS acceptance_ip VARCHAR(80) NULL AFTER acceptance_text;
ALTER TABLE campaign_sedes ADD COLUMN IF NOT EXISTS acceptance_user_agent VARCHAR(500) NULL AFTER acceptance_ip;
ALTER TABLE campaign_sedes ADD COLUMN IF NOT EXISTS accepted_at DATETIME NULL AFTER acceptance_user_agent;

CREATE TABLE IF NOT EXISTS internal_notifications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 campaign_id BIGINT UNSIGNED NULL,
 sede_id BIGINT UNSIGNED NULL,
 title VARCHAR(255) NOT NULL,
 message TEXT NOT NULL,
 notification_type ENUM('campania','recordatorio','correccion','traslado','reapertura','sistema') NOT NULL DEFAULT 'sistema',
 read_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_in_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_in_campaign FOREIGN KEY(campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
 CONSTRAINT fk_in_sede FOREIGN KEY(sede_id) REFERENCES sedes(id) ON DELETE CASCADE,
 INDEX idx_in_user_read(user_id,read_at), INDEX idx_in_created(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reopening_requests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 campaign_id BIGINT UNSIGNED NOT NULL,
 sede_id BIGINT UNSIGNED NOT NULL,
 requested_by BIGINT UNSIGNED NOT NULL,
 reason TEXT NOT NULL,
 status ENUM('pendiente','aprobada','rechazada','cerrada') NOT NULL DEFAULT 'pendiente',
 reviewed_by BIGINT UNSIGNED NULL,
 reviewed_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_rr_campaign FOREIGN KEY(campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
 CONSTRAINT fk_rr_sede FOREIGN KEY(sede_id) REFERENCES sedes(id) ON DELETE CASCADE,
 CONSTRAINT fk_rr_requester FOREIGN KEY(requested_by) REFERENCES users(id),
 CONSTRAINT fk_rr_reviewer FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
 INDEX idx_rr_status(status), INDEX idx_rr_scope(campaign_id,sede_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evidence_files (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 validation_id BIGINT UNSIGNED NOT NULL,
 evidence_type ENUM('general','placa','serial','dano','documento') NOT NULL DEFAULT 'general',
 file_path VARCHAR(500) NOT NULL,
 mime_type VARCHAR(120) NOT NULL,
 file_size BIGINT UNSIGNED NOT NULL,
 sha256 CHAR(64) NOT NULL,
 uploaded_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_ef_validation FOREIGN KEY(validation_id) REFERENCES equipment_validations(id) ON DELETE CASCADE,
 CONSTRAINT fk_ef_user FOREIGN KEY(uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
 UNIQUE KEY uq_ef_hash_validation(validation_id,sha256), INDEX idx_ef_validation(validation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- RC5: recordatorios, reporte ejecutivo, evidencias múltiples y respaldos.
CREATE TABLE IF NOT EXISTS backup_history (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 file_name VARCHAR(255) NOT NULL,
 file_size BIGINT UNSIGNED NOT NULL,
 sha256 CHAR(64) NOT NULL,
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_backup_user FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
 INDEX idx_backup_created(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- RC7: catálogos normalizados, rendimiento y calidad operativa.
CREATE TABLE IF NOT EXISTS departments (
 code VARCHAR(10) PRIMARY KEY, name VARCHAR(120) NOT NULL, active TINYINT(1) NOT NULL DEFAULT 1,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_departments_name(name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS municipalities (
 department_code VARCHAR(10) NOT NULL, code VARCHAR(10) NOT NULL, name VARCHAR(120) NOT NULL, active TINYINT(1) NOT NULL DEFAULT 1,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(department_code,code), INDEX idx_municipality_name(name),
 CONSTRAINT fk_municipality_department FOREIGN KEY(department_code) REFERENCES departments(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sede_types (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL UNIQUE, active TINYINT(1) NOT NULL DEFAULT 1,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX IF NOT EXISTS idx_sedes_geo ON sedes(cod_dd,cod_mm);
CREATE INDEX IF NOT EXISTS idx_sedes_name ON sedes(nombre_sede);
CREATE INDEX IF NOT EXISTS idx_validation_campaign_status ON equipment_validations(campaign_id,validation_status,review_status);
CREATE INDEX IF NOT EXISTS idx_campaign_sedes_status ON campaign_sedes(campaign_id,status);
CREATE INDEX IF NOT EXISTS idx_equipment_active_sede ON equipment(active,current_sede_id);
ALTER TABLE directory_imports MODIFY COLUMN status ENUM('procesando','completado','revertido','error') NOT NULL DEFAULT 'procesando';

-- 0.0.0.2: control institucional de versiones y trazabilidad de despliegues.
CREATE TABLE IF NOT EXISTS app_release_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    release_key CHAR(64) NOT NULL,
    version VARCHAR(32) NOT NULL,
    environment ENUM('development','testing','staging','production') NOT NULL DEFAULT 'production',
    build_id VARCHAR(120) NOT NULL DEFAULT 'dokploy',
    git_commit VARCHAR(64) NULL,
    schema_checksum CHAR(64) NULL,
    is_current TINYINT(1) NOT NULL DEFAULT 1,
    release_notes TEXT NULL,
    registered_by BIGINT UNSIGNED NULL,
    installed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_app_release_key (release_key),
    INDEX idx_app_release_current (environment,is_current),
    INDEX idx_app_release_version (version,environment),
    CONSTRAINT fk_app_release_user FOREIGN KEY (registered_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- SIVI 0.0.0.7: campañas con cobertura por departamentos.
INSERT IGNORE INTO campaign_departments(campaign_id,cod_dd)
SELECT DISTINCT cs.campaign_id,s.cod_dd FROM campaign_sedes cs JOIN sedes s ON s.id=cs.sede_id WHERE NULLIF(TRIM(s.cod_dd),'') IS NOT NULL;
ALTER TABLE campaigns ADD COLUMN IF NOT EXISTS scope_type ENUM('nacional','departamental') NOT NULL DEFAULT 'nacional' AFTER status;
CREATE INDEX IF NOT EXISTS idx_campaign_scope ON campaigns(scope_type,status);


-- SIVI 0.0.0.8: validación del activo, serial y Placa RNEC
ALTER TABLE equipment ADD COLUMN IF NOT EXISTS ownership_type ENUM('propio','comodato','donado_sin_legalizar','desconocido') NOT NULL DEFAULT 'desconocido' AFTER source_origin;
ALTER TABLE equipment MODIFY COLUMN inventory_status ENUM('activo','inactivo','para_baja','dado_baja','en_almacen','en_mantenimiento','trasladado','baja','almacen','desconocido') NOT NULL DEFAULT 'desconocido';
UPDATE equipment SET inventory_status='dado_baja' WHERE inventory_status='baja';
UPDATE equipment SET inventory_status='en_almacen' WHERE inventory_status='almacen';
ALTER TABLE equipment MODIFY COLUMN inventory_status ENUM('activo','inactivo','para_baja','dado_baja','en_almacen','en_mantenimiento','trasladado','desconocido') NOT NULL DEFAULT 'desconocido';
ALTER TABLE equipment_validations MODIFY COLUMN physical_condition ENUM('pendiente','activo','inactivo','para_baja','dado_baja','en_almacen','en_mantenimiento','trasladado','bueno','regular','malo','inoperativo','no_localizado') NOT NULL DEFAULT 'pendiente';
ALTER TABLE equipment_validations ADD COLUMN IF NOT EXISTS ownership_type ENUM('propio','comodato','donado_sin_legalizar','desconocido') NOT NULL DEFAULT 'desconocido' AFTER physical_condition;
ALTER TABLE equipment_validations ADD COLUMN IF NOT EXISTS disposal_date DATE NULL AFTER destination_text;
ALTER TABLE equipment_validations ADD COLUMN IF NOT EXISTS disposal_document VARCHAR(255) NULL AFTER disposal_date;
ALTER TABLE equipment_validations ADD INDEX IF NOT EXISTS idx_ev_asset_status (campaign_id,physical_condition,ownership_type);
UPDATE equipment_validations SET physical_condition='activo' WHERE physical_condition IN ('bueno','regular');
UPDATE equipment_validations SET physical_condition='inactivo' WHERE physical_condition IN ('malo','inoperativo','no_localizado');
UPDATE equipment_validations SET belongs_status=CASE WHEN physical_condition='trasladado' THEN 'no_pertenece' ELSE 'pertenece' END WHERE validation_status<>'pendiente';

-- SIVI 0.0.0.9: mejora de experiencia en formularios de validación; sin cambios estructurales.


-- SIVI 0.0.0.10: asociación territorial jerarquizada de activos GLPI.
ALTER TABLE imports ADD COLUMN IF NOT EXISTS association_summary_json LONGTEXT NULL AFTER unassigned_equipment;
ALTER TABLE imports ADD COLUMN IF NOT EXISTS review_required_equipment INT NOT NULL DEFAULT 0 AFTER association_summary_json;
ALTER TABLE equipment MODIFY COLUMN association_method ENUM('hostname','usuario','location','fallback_distrital','fallback_delegacion','warehouse','manual','unassigned') NOT NULL DEFAULT 'unassigned';
ALTER TABLE equipment ADD COLUMN IF NOT EXISTS association_confidence ENUM('alta','media','baja','sin_asignar') NOT NULL DEFAULT 'sin_asignar' AFTER association_method;
ALTER TABLE equipment ADD COLUMN IF NOT EXISTS association_evidence LONGTEXT NULL AFTER association_confidence;
ALTER TABLE equipment ADD COLUMN IF NOT EXISTS association_review_required TINYINT(1) NOT NULL DEFAULT 0 AFTER association_evidence;
ALTER TABLE equipment ADD INDEX IF NOT EXISTS idx_eq_assoc_review (association_review_required, association_confidence);

-- SIVI 0.0.0.18: validación previa, semáforo de calidad e historial de archivos.
CREATE TABLE IF NOT EXISTS import_validations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    import_type ENUM('sedes','glpi_computers','warehouse','glpi_asset') NOT NULL,
    asset_category VARCHAR(40) NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(255) NOT NULL,
    file_hash CHAR(64) NULL,
    status ENUM('validando','aprobada','advertencias','rechazada','aplicada','error') NOT NULL DEFAULT 'validando',
    traffic_light ENUM('verde','amarillo','rojo') NOT NULL DEFAULT 'rojo',
    rows_read INT NOT NULL DEFAULT 0,
    valid_rows INT NOT NULL DEFAULT 0,
    warning_count INT NOT NULL DEFAULT 0,
    critical_count INT NOT NULL DEFAULT 0,
    summary_json LONGTEXT NULL,
    issues_json LONGTEXT NULL,
    error_report_path VARCHAR(500) NULL,
    error_message TEXT NULL,
    applied_entity_type VARCHAR(80) NULL,
    applied_import_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    applied_at DATETIME NULL,
    CONSTRAINT fk_import_validation_user FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_import_validation_type (import_type,created_at),
    INDEX idx_import_validation_status (status,traffic_light),
    INDEX idx_import_validation_hash (file_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_quality_snapshots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    traffic_light ENUM('verde','amarillo','rojo') NOT NULL,
    critical_count INT NOT NULL DEFAULT 0,
    warning_count INT NOT NULL DEFAULT 0,
    metrics_json LONGTEXT NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_quality_snapshot_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_quality_snapshot_created (created_at),
    INDEX idx_quality_snapshot_traffic (traffic_light)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SIVI 0.0.0.22: gestión integral de campañas y operación por sede.
ALTER TABLE campaigns MODIFY COLUMN status ENUM('borrador','publicada','programada','activa','cerrada','en_revision','finalizada','cancelada') NOT NULL DEFAULT 'borrador';
UPDATE campaigns SET status='activa' WHERE status='publicada';
ALTER TABLE campaigns MODIFY COLUMN status ENUM('borrador','programada','activa','cerrada','en_revision','finalizada','cancelada') NOT NULL DEFAULT 'borrador';
ALTER TABLE campaigns MODIFY COLUMN scope_type ENUM('nacional','departamental','municipal','sedes') NOT NULL DEFAULT 'nacional';
ALTER TABLE campaigns ADD COLUMN IF NOT EXISTS scope_json LONGTEXT NULL AFTER instructions;
ALTER TABLE campaigns ADD COLUMN IF NOT EXISTS asset_categories_json LONGTEXT NULL AFTER scope_json;
ALTER TABLE campaigns ADD COLUMN IF NOT EXISTS requires_evidence TINYINT(1) NOT NULL DEFAULT 0 AFTER asset_categories_json;
ALTER TABLE campaigns ADD COLUMN IF NOT EXISTS allow_overlap TINYINT(1) NOT NULL DEFAULT 0 AFTER requires_evidence;
ALTER TABLE campaigns ADD COLUMN IF NOT EXISTS published_at DATETIME NULL AFTER allow_overlap;
ALTER TABLE campaigns ADD COLUMN IF NOT EXISTS closed_at DATETIME NULL AFTER published_at;
ALTER TABLE campaigns ADD COLUMN IF NOT EXISTS cancelled_at DATETIME NULL AFTER closed_at;
ALTER TABLE campaigns ADD INDEX IF NOT EXISTS idx_campaign_dates (status,start_date,end_date);

CREATE TABLE IF NOT EXISTS campaign_equipment (
    campaign_id BIGINT UNSIGNED NOT NULL,
    equipment_id BIGINT UNSIGNED NOT NULL,
    sede_id BIGINT UNSIGNED NOT NULL,
    asset_category VARCHAR(40) NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (campaign_id,equipment_id),
    CONSTRAINT fk_ce_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_ce_equipment FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE CASCADE,
    CONSTRAINT fk_ce_sede FOREIGN KEY (sede_id) REFERENCES sedes(id) ON DELETE CASCADE,
    INDEX idx_ce_sede (campaign_id,sede_id),
    INDEX idx_ce_category (campaign_id,asset_category),
    INDEX idx_ce_overlap (equipment_id,campaign_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO campaign_equipment(campaign_id,equipment_id,sede_id,asset_category)
SELECT cs.campaign_id,e.id,e.current_sede_id,e.asset_category
FROM campaign_sedes cs
JOIN equipment e ON e.current_sede_id=cs.sede_id AND e.active=1;

CREATE TABLE IF NOT EXISTS validation_drafts (
    campaign_id BIGINT UNSIGNED NOT NULL,
    equipment_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    sede_id BIGINT UNSIGNED NOT NULL,
    payload_json LONGTEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (campaign_id,equipment_id,user_id),
    CONSTRAINT fk_vd_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_vd_equipment FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE CASCADE,
    CONSTRAINT fk_vd_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_vd_sede FOREIGN KEY (sede_id) REFERENCES sedes(id) ON DELETE CASCADE,
    INDEX idx_vd_user_updated (user_id,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE incidents MODIFY COLUMN incident_type ENUM(
    'equipo_no_encontrado','no_pertenece','placa','serial','traslado','reparacion','baja','duplicado','datos','otro',
    'equipo_adicional','sin_placa','serial_ilegible','cambio_ubicacion','cambio_responsable','mantenimiento'
) NOT NULL;

-- SIVI 0.0.0.22: asociación territorial ampliada para Inventario de Almacén.
ALTER TABLE warehouse_assets ADD COLUMN IF NOT EXISTS associated_sede_id BIGINT UNSIGNED NULL AFTER holder;
ALTER TABLE warehouse_assets ADD COLUMN IF NOT EXISTS association_rule VARCHAR(120) NULL AFTER associated_sede_id;
ALTER TABLE warehouse_assets ADD COLUMN IF NOT EXISTS association_confidence ENUM('alta','media','baja','sin_asignar') NOT NULL DEFAULT 'sin_asignar' AFTER association_rule;
ALTER TABLE warehouse_assets ADD COLUMN IF NOT EXISTS association_evidence LONGTEXT NULL AFTER association_confidence;
ALTER TABLE warehouse_assets ADD COLUMN IF NOT EXISTS association_review_required TINYINT(1) NOT NULL DEFAULT 1 AFTER association_evidence;
ALTER TABLE warehouse_assets ADD INDEX IF NOT EXISTS idx_warehouse_associated_sede (associated_sede_id);
ALTER TABLE warehouse_assets ADD INDEX IF NOT EXISTS idx_warehouse_association_rule (association_rule);

ALTER TABLE warehouse_imports ADD COLUMN IF NOT EXISTS warehouse_exact_assigned INT NOT NULL DEFAULT 0 AFTER warehouse_only_assigned;
ALTER TABLE warehouse_imports ADD COLUMN IF NOT EXISTS warehouse_department_assigned INT NOT NULL DEFAULT 0 AFTER warehouse_exact_assigned;
ALTER TABLE warehouse_imports ADD COLUMN IF NOT EXISTS warehouse_unassigned INT NOT NULL DEFAULT 0 AFTER warehouse_department_assigned;
ALTER TABLE warehouse_imports ADD COLUMN IF NOT EXISTS warehouse_glpi_enhanced INT NOT NULL DEFAULT 0 AFTER warehouse_unassigned;

-- SIVI 0.0.0.24: integridad de seriales en el inventario activo.
ALTER TABLE equipment ADD COLUMN IF NOT EXISTS serial_source_original VARCHAR(255) NULL AFTER serial_number;
ALTER TABLE equipment ADD COLUMN IF NOT EXISTS serial_review_required TINYINT(1) NOT NULL DEFAULT 0 AFTER serial_source_original;
ALTER TABLE equipment ADD COLUMN IF NOT EXISTS serial_review_reason VARCHAR(60) NULL AFTER serial_review_required;
ALTER TABLE equipment ADD COLUMN IF NOT EXISTS serial_verified_at DATETIME NULL AFTER serial_review_reason;
ALTER TABLE equipment ADD COLUMN IF NOT EXISTS serial_verified_by BIGINT UNSIGNED NULL AFTER serial_verified_at;
ALTER TABLE equipment ADD INDEX IF NOT EXISTS idx_eq_serial_review (serial_review_required,serial_review_reason);

-- SIVI 0.0.0.25: responsable y correo se confirman al iniciar la validación de cada sede.


-- SIVI 0.0.0.27: validación obligatoria de la sede antes de los equipos.
ALTER TABLE campaign_sedes ADD COLUMN IF NOT EXISTS site_confirmation_status ENUM('pendiente','confirmada','con_novedad') NOT NULL DEFAULT 'pendiente' AFTER contact_confirmed_at;
ALTER TABLE campaign_sedes ADD COLUMN IF NOT EXISTS site_confirmation_notes TEXT NULL AFTER site_confirmation_status;
ALTER TABLE campaign_sedes ADD COLUMN IF NOT EXISTS site_confirmed_address VARCHAR(500) NULL AFTER site_confirmation_notes;
ALTER TABLE campaign_sedes ADD COLUMN IF NOT EXISTS site_confirmed_by BIGINT UNSIGNED NULL AFTER site_confirmed_address;
ALTER TABLE campaign_sedes ADD COLUMN IF NOT EXISTS site_confirmed_at DATETIME NULL AFTER site_confirmed_by;
ALTER TABLE campaign_sedes ADD INDEX IF NOT EXISTS idx_cs_site_confirmation (campaign_id,site_confirmation_status,site_confirmed_at);

-- SIVI 0.0.0.28: el Formador comparte el menú operativo del Registrador y no administra campañas.

-- SIVI 0.0.0.29: serial y placa únicos al registrar equipos adicionales; se informa sede y categoría existente.


-- SIVI 0.0.0.42: formulario dinámico y validación reforzada de equipos adicionales.
ALTER TABLE additional_equipment ADD COLUMN IF NOT EXISTS ownership_type ENUM('propio','comodato','donado_sin_legalizar','desconocido') NOT NULL DEFAULT 'desconocido' AFTER asset_category;
ALTER TABLE additional_equipment ADD INDEX IF NOT EXISTS idx_ae_category_property_state (asset_category,ownership_type,equipment_state);

-- SIVI 0.0.0.42: sin cambios estructurales; ajuste de reglas de formulario y cierre consistente.

-- SIVI 0.0.0.42: sin cambio de esquema; agrega decodificación fotográfica para escaneo móvil.

-- SIVI 0.0.0.42: trazabilidad de exportaciones del centro integral de informes.
CREATE TABLE IF NOT EXISTS report_exports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_type VARCHAR(80) NOT NULL,
    export_format VARCHAR(30) NOT NULL,
    filters_json LONGTEXT NULL,
    row_count INT UNSIGNED NOT NULL DEFAULT 0,
    exported_by BIGINT UNSIGNED NULL,
    ip_address VARCHAR(80) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_report_export_user FOREIGN KEY (exported_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_report_export_created (created_at),
    INDEX idx_report_export_type (report_type,export_format),
    INDEX idx_report_export_user (exported_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SIVI 0.0.0.42: notificaciones salientes Microsoft 365 mediante Microsoft Graph.
ALTER TABLE campaign_sedes MODIFY COLUMN notification_status ENUM('pendiente','encolada','enviada','error','sin_correo') NOT NULL DEFAULT 'pendiente';
ALTER TABLE notifications MODIFY COLUMN status ENUM('pendiente','encolado','enviado','error','registrado') NOT NULL DEFAULT 'pendiente';
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS event_key VARCHAR(100) NULL AFTER subject;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS queue_id BIGINT UNSIGNED NULL AFTER event_key;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS provider_request_id VARCHAR(255) NULL AFTER queue_id;
ALTER TABLE notifications ADD INDEX IF NOT EXISTS idx_notifications_event (event_key);
ALTER TABLE notifications ADD INDEX IF NOT EXISTS idx_notifications_queue (queue_id);

CREATE TABLE IF NOT EXISTS notification_templates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_key VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(180) NOT NULL,
    subject_template VARCHAR(255) NOT NULL,
    html_template LONGTEXT NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_notification_template_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_notification_template_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_queue (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    notification_id BIGINT UNSIGNED NOT NULL,
    event_key VARCHAR(100) NOT NULL DEFAULT 'manual',
    campaign_id BIGINT UNSIGNED NULL,
    sede_id BIGINT UNSIGNED NULL,
    recipient VARCHAR(255) NOT NULL,
    cc_json JSON NULL,
    bcc_json JSON NULL,
    subject VARCHAR(255) NOT NULL,
    html_body LONGTEXT NOT NULL,
    status ENUM('pendiente','procesando','enviado','error','cancelado') NOT NULL DEFAULT 'pendiente',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    next_attempt_at DATETIME NULL,
    last_error TEXT NULL,
    last_http_status SMALLINT UNSIGNED NULL,
    provider_request_id VARCHAR(255) NULL,
    client_request_id VARCHAR(80) NULL,
    locked_at DATETIME NULL,
    locked_by VARCHAR(190) NULL,
    created_by BIGINT UNSIGNED NULL,
    processed_at DATETIME NULL,
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_notification_queue_notification FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
    CONSTRAINT fk_notification_queue_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL,
    CONSTRAINT fk_notification_queue_sede FOREIGN KEY (sede_id) REFERENCES sedes(id) ON DELETE SET NULL,
    CONSTRAINT fk_notification_queue_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_notification_queue_due (status,next_attempt_at,id),
    INDEX idx_notification_queue_event (event_key),
    INDEX idx_notification_queue_campaign (campaign_id,sede_id),
    INDEX idx_notification_queue_recipient (recipient),
    INDEX idx_notification_queue_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SIVI 0.0.0.43: integración controlada y de solo consulta con GLPI.
CREATE TABLE IF NOT EXISTS glpi_sync_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    api_version ENUM('v1','v2') NOT NULL DEFAULT 'v1',
    status ENUM('preparando','vista_previa','aplicado','error') NOT NULL DEFAULT 'preparando',
    total_items INT UNSIGNED NOT NULL DEFAULT 0,
    new_items INT UNSIGNED NOT NULL DEFAULT 0,
    updated_items INT UNSIGNED NOT NULL DEFAULT 0,
    linked_items INT UNSIGNED NOT NULL DEFAULT 0,
    conflict_items INT UNSIGNED NOT NULL DEFAULT 0,
    unmapped_items INT UNSIGNED NOT NULL DEFAULT 0,
    applied_items INT UNSIGNED NOT NULL DEFAULT 0,
    error_message TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    applied_at DATETIME NULL,
    INDEX idx_gsr_status_created (status,created_at),
    CONSTRAINT fk_gsr_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS glpi_sync_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id BIGINT UNSIGNED NOT NULL,
    glpi_itemtype VARCHAR(80) NOT NULL,
    glpi_id BIGINT UNSIGNED NOT NULL,
    source_key VARCHAR(64) NOT NULL,
    asset_category VARCHAR(40) NOT NULL,
    name VARCHAR(255) NULL,
    serial_number VARCHAR(255) NULL,
    placa_rnec VARCHAR(120) NULL,
    location_key VARCHAR(190) NULL,
    location_name VARCHAR(255) NULL,
    candidate_sede_id BIGINT UNSIGNED NULL,
    existing_equipment_id BIGINT UNSIGNED NULL,
    decision ENUM('nuevo','actualizar','vincular','conflicto','sin_sede','omitido') NOT NULL DEFAULT 'nuevo',
    decision_reason TEXT NULL,
    raw_json LONGTEXT NULL,
    applied_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_gsi_run_item (run_id,glpi_itemtype,glpi_id),
    INDEX idx_gsi_run_decision (run_id,decision),
    CONSTRAINT fk_gsi_run FOREIGN KEY (run_id) REFERENCES glpi_sync_runs(id) ON DELETE CASCADE,
    CONSTRAINT fk_gsi_sede FOREIGN KEY (candidate_sede_id) REFERENCES sedes(id) ON DELETE SET NULL,
    CONSTRAINT fk_gsi_equipment FOREIGN KEY (existing_equipment_id) REFERENCES equipment(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS glpi_asset_links (
    glpi_itemtype VARCHAR(80) NOT NULL,
    glpi_id BIGINT UNSIGNED NOT NULL,
    equipment_id BIGINT UNSIGNED NOT NULL,
    source_key VARCHAR(64) NOT NULL,
    last_sync_run_id BIGINT UNSIGNED NULL,
    linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (glpi_itemtype,glpi_id),
    UNIQUE KEY uq_gal_equipment (equipment_id),
    UNIQUE KEY uq_gal_source_key (source_key),
    CONSTRAINT fk_gal_equipment FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE CASCADE,
    CONSTRAINT fk_gal_run FOREIGN KEY (last_sync_run_id) REFERENCES glpi_sync_runs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS glpi_location_mappings (
    location_key VARCHAR(190) NOT NULL PRIMARY KEY,
    location_name VARCHAR(255) NULL,
    sede_id BIGINT UNSIGNED NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_glm_sede (sede_id),
    CONSTRAINT fk_glm_sede FOREIGN KEY (sede_id) REFERENCES sedes(id) ON DELETE CASCADE,
    CONSTRAINT fk_glm_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SIVI 0.0.0.44: puerta de calidad avanzada por campaña y sede.
CREATE TABLE IF NOT EXISTS site_quality_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id BIGINT UNSIGNED NOT NULL,
    sede_id BIGINT UNSIGNED NOT NULL,
    score TINYINT UNSIGNED NOT NULL DEFAULT 0,
    blocking_count INT UNSIGNED NOT NULL DEFAULT 0,
    warning_count INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('bloqueado','aprobado') NOT NULL DEFAULT 'bloqueado',
    executed_by BIGINT UNSIGNED NULL,
    executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sqr_scope (campaign_id,sede_id,executed_at),
    CONSTRAINT fk_sqr_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_sqr_sede FOREIGN KEY (sede_id) REFERENCES sedes(id) ON DELETE CASCADE,
    CONSTRAINT fk_sqr_user FOREIGN KEY (executed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_quality_findings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id BIGINT UNSIGNED NOT NULL,
    severity ENUM('bloqueante','advertencia') NOT NULL,
    finding_code VARCHAR(100) NOT NULL,
    title VARCHAR(255) NOT NULL,
    detail TEXT NOT NULL,
    equipment_id BIGINT UNSIGNED NULL,
    action_route VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sqf_run (run_id,severity),
    INDEX idx_sqf_code (finding_code),
    CONSTRAINT fk_sqf_run FOREIGN KEY (run_id) REFERENCES site_quality_runs(id) ON DELETE CASCADE,
    CONSTRAINT fk_sqf_equipment FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SIVI 0.0.0.45: operación segura, respaldos y trazabilidad.
CREATE TABLE IF NOT EXISTS ops_backup_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    backup_id VARCHAR(100) NOT NULL,
    backup_type VARCHAR(30) NOT NULL,
    status VARCHAR(30) NOT NULL,
    package_name VARCHAR(255) NULL,
    size_bytes BIGINT UNSIGNED NULL,
    sha256 CHAR(64) NULL,
    encrypted TINYINT(1) NOT NULL DEFAULT 1,
    app_version VARCHAR(40) NULL,
    build_id VARCHAR(120) NULL,
    git_commit VARCHAR(80) NULL,
    started_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    error_reference VARCHAR(80) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ops_backup_runs_backup_id (backup_id),
    KEY idx_ops_backup_runs_status_started (status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ops_deployment_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    release_version VARCHAR(40) NOT NULL,
    build_id VARCHAR(120) NOT NULL,
    git_commit VARCHAR(80) NULL,
    backup_id VARCHAR(100) NULL,
    status VARCHAR(30) NOT NULL,
    health_ok TINYINT(1) NOT NULL DEFAULT 0,
    readiness_ok TINYINT(1) NOT NULL DEFAULT 0,
    started_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    rollback_reason VARCHAR(500) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ops_deploy_status_started (status, started_at),
    KEY idx_ops_deploy_version (release_version, build_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ops_security_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_type VARCHAR(80) NOT NULL,
    severity VARCHAR(20) NOT NULL DEFAULT 'info',
    actor_id BIGINT UNSIGNED NULL,
    actor_hash CHAR(64) NULL,
    ip_hash CHAR(64) NULL,
    details_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ops_security_type_created (event_type, created_at),
    KEY idx_ops_security_severity_created (severity, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SIVI 0.0.0.48: política configurable de Placa RNEC y recorrido guiado.
CREATE TABLE IF NOT EXISTS sivi_runtime_settings (
    setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value VARCHAR(500) NOT NULL,
    setting_type VARCHAR(30) NOT NULL DEFAULT 'string',
    description VARCHAR(500) NULL,
    updated_by BIGINT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sivi_runtime_settings
    (setting_key, setting_value, setting_type, description, updated_by)
SELECT
    'plate_rnec_total_characters',
    CAST(LEAST(21, GREATEST(5, COALESCE((SELECT CAST(setting_value AS UNSIGNED) FROM sivi_runtime_settings WHERE setting_key='plate_rnec_digits' LIMIT 1), 8) + 1)) AS CHAR),
    'integer',
    'Cantidad total de caracteres de la Placa RNEC, incluido el guion después de los primeros tres números',
    NULL
WHERE NOT EXISTS (SELECT 1 FROM sivi_runtime_settings WHERE setting_key='plate_rnec_total_characters');

UPDATE sivi_runtime_settings
SET setting_type='integer', description='Cantidad total de caracteres de la Placa RNEC, incluido el guion después de los primeros tres números'
WHERE setting_key='plate_rnec_total_characters';

CREATE TABLE IF NOT EXISTS sivi_user_onboarding (
    user_id BIGINT NOT NULL,
    tour_key VARCHAR(100) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'started',
    last_step SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    started_at TIMESTAMP NULL DEFAULT NULL,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    skipped_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, tour_key),
    KEY idx_sivi_user_onboarding_status (status),
    KEY idx_sivi_user_onboarding_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
