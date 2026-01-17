-- migration_social_pharmacy.sql
-- Добавляет всё необходимое под рецепты, склад и статусы заказов

ALTER TABLE prescriptions
  ADD COLUMN doctor_id INT(10) UNSIGNED NULL AFTER customer_id,
  ADD COLUMN status ENUM('active','cancelled','expired','fulfilled') NOT NULL DEFAULT 'active' AFTER comment,
  ADD COLUMN expires_at DATE NULL AFTER status;

ALTER TABLE prescriptions
  ADD CONSTRAINT fk_presc_doctor FOREIGN KEY (doctor_id) REFERENCES doctors(id);

ALTER TABLE prescription_items
  ADD COLUMN used_quantity INT(10) UNSIGNED NOT NULL DEFAULT 0 AFTER quantity;

ALTER TABLE drugs
  ADD COLUMN stock INT(10) UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE orders
  ADD COLUMN clinic_id INT(10) UNSIGNED NULL AFTER customer_id,
  ADD COLUMN prescription_id INT(10) UNSIGNED NULL AFTER clinic_id,
  ADD COLUMN status ENUM('new','processing','ready','dispensed','cancelled') NOT NULL DEFAULT 'new' AFTER total_amount,
  ADD COLUMN dispensed_by INT(10) UNSIGNED NULL AFTER status,
  ADD COLUMN dispensed_at DATETIME NULL AFTER dispensed_by;

ALTER TABLE orders
  ADD CONSTRAINT fk_orders_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id),
  ADD CONSTRAINT fk_orders_prescription FOREIGN KEY (prescription_id) REFERENCES prescriptions(id),
  ADD CONSTRAINT fk_orders_dispensed_by FOREIGN KEY (dispensed_by) REFERENCES pharmacists(id);

CREATE TABLE IF NOT EXISTS order_status_history (
  id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id INT(10) UNSIGNED NOT NULL,
  old_status VARCHAR(32) NOT NULL,
  new_status VARCHAR(32) NOT NULL,
  changed_by INT(10) UNSIGNED NOT NULL,
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_order (order_id),
  CONSTRAINT fk_osh_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_osh_user FOREIGN KEY (changed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
