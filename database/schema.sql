-- =====================================================================
-- NOTE BANK V1 — Database Schema
-- Landmark University first deployment. Engine: InnoDB. Charset: utf8mb4.
-- Money fields use DECIMAL. Foreign keys enforced. No plaintext passwords.
--
-- Import with:
--   mysql -u root -p -e "CREATE DATABASE notebank CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
--   mysql -u root -p notebank < database/schema.sql
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1. ADMINS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admins (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name       VARCHAR(150)        NOT NULL,
    email           VARCHAR(190)        NOT NULL,
    password_hash   VARCHAR(255)        NOT NULL,
    role            ENUM('super_admin','admin','payments_officer') NOT NULL DEFAULT 'admin',
    status          ENUM('active','suspended') NOT NULL DEFAULT 'active',
    last_login_at   DATETIME            NULL,
    created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_admins_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. ACADEMIC STRUCTURE — University > Faculty > Department > Level
--    Semesters are global academic periods reused across courses.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS universities (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(190)    NOT NULL,
    code        VARCHAR(20)     NOT NULL,
    country     VARCHAR(100)    NOT NULL DEFAULT 'Nigeria',
    status      ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_universities_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS faculties (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    university_id   INT UNSIGNED    NOT NULL,
    name            VARCHAR(190)    NOT NULL,
    code            VARCHAR(20)     NOT NULL,
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_faculties_uni_code (university_id, code),
    KEY idx_faculties_university (university_id),
    CONSTRAINT fk_faculties_university FOREIGN KEY (university_id) REFERENCES universities(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS departments (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    faculty_id  INT UNSIGNED    NOT NULL,
    name        VARCHAR(190)    NOT NULL,
    code        VARCHAR(20)     NOT NULL,
    status      ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_departments_faculty_code (faculty_id, code),
    KEY idx_departments_faculty (faculty_id),
    CONSTRAINT fk_departments_faculty FOREIGN KEY (faculty_id) REFERENCES faculties(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS levels (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    department_id   INT UNSIGNED    NOT NULL,
    name            VARCHAR(50)     NOT NULL,   -- e.g. "100 Level"
    code            VARCHAR(20)     NOT NULL,   -- e.g. "100"
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_levels_department_code (department_id, code),
    KEY idx_levels_department (department_id),
    CONSTRAINT fk_levels_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS semesters (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100)    NOT NULL,   -- e.g. "First Semester"
    code        VARCHAR(20)     NOT NULL,   -- e.g. "FIRST"
    status      ENUM('active','inactive') NOT NULL DEFAULT 'active',
    UNIQUE KEY uq_semesters_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS courses (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    university_id   INT UNSIGNED    NOT NULL,
    department_id   INT UNSIGNED    NOT NULL,
    level_id        INT UNSIGNED    NOT NULL,
    semester_id     INT UNSIGNED    NOT NULL,
    code            VARCHAR(30)     NOT NULL,  -- e.g. "CSC301", scoped to university+department
    title           VARCHAR(190)    NOT NULL,
    description     TEXT            NULL,
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_courses_scope_code (university_id, department_id, code),
    KEY idx_courses_department (department_id),
    KEY idx_courses_level (level_id),
    KEY idx_courses_semester (semester_id),
    CONSTRAINT fk_courses_university FOREIGN KEY (university_id) REFERENCES universities(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_courses_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_courses_level FOREIGN KEY (level_id) REFERENCES levels(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_courses_semester FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. DOCUMENTS — sellable notes / past questions
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS documents (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id       INT UNSIGNED    NOT NULL,
    type            ENUM('NOTE','PAST_QUESTION') NOT NULL,
    title           VARCHAR(190)    NOT NULL,
    description     TEXT            NULL,
    file_path       VARCHAR(255)    NOT NULL,   -- storage/original/<random>.pdf — outside public root
    preview_path    VARCHAR(255)    NULL,       -- storage/preview/<random>.pdf — regenerated on demand
    page_count      SMALLINT UNSIGNED NULL,
    price           DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    version         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    status          ENUM('ACTIVE','INACTIVE','DRAFT') NOT NULL DEFAULT 'DRAFT',
    created_by      INT UNSIGNED    NOT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_documents_course (course_id),
    KEY idx_documents_type (type),
    KEY idx_documents_status (status),
    CONSTRAINT fk_documents_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_documents_admin FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. STUDENTS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS students (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    university_id       INT UNSIGNED    NOT NULL,
    full_name           VARCHAR(150)    NOT NULL,
    email               VARCHAR(190)    NOT NULL,
    password_hash       VARCHAR(255)    NOT NULL,
    student_number      VARCHAR(50)     NULL,
    department_id       INT UNSIGNED    NULL,
    level_id            INT UNSIGNED    NULL,
    email_verified      TINYINT(1)      NOT NULL DEFAULT 0,
    status              ENUM('active','suspended') NOT NULL DEFAULT 'active',
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_students_email (email),
    KEY idx_students_university (university_id),
    KEY idx_students_department (department_id),
    KEY idx_students_level (level_id),
    CONSTRAINT fk_students_university FOREIGN KEY (university_id) REFERENCES universities(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_students_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_students_level FOREIGN KEY (level_id) REFERENCES levels(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_verifications (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id  INT UNSIGNED    NOT NULL,
    token_hash  VARCHAR(255)    NOT NULL,
    expires_at  DATETIME        NOT NULL,
    used_at     DATETIME        NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_email_verifications_student (student_id),
    CONSTRAINT fk_email_verifications_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id  INT UNSIGNED    NOT NULL,
    token_hash  VARCHAR(255)    NOT NULL,
    expires_at  DATETIME        NOT NULL,
    used_at     DATETIME        NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_password_resets_student (student_id),
    CONSTRAINT fk_password_resets_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. CART
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cart_items (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id  INT UNSIGNED    NOT NULL,
    document_id INT UNSIGNED    NOT NULL,
    quantity    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    unit_price  DECIMAL(10,2)   NOT NULL,   -- snapshotted from documents.price at add-time, re-validated at checkout
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cart_student_document (student_id, document_id),
    KEY idx_cart_document (document_id),
    CONSTRAINT fk_cart_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_cart_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. ORDERS / PAYMENTS / ENTITLEMENTS
--    Status machine (see guide section 10):
--    PENDING_PAYMENT -> PAYMENT_SUBMITTED -> APPROVED -> UNLOCKED (entitlements created)
--                                        \-> REJECTED -> (resubmit) PAYMENT_SUBMITTED
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id      INT UNSIGNED    NOT NULL,
    order_number    VARCHAR(30)     NOT NULL,
    subtotal        DECIMAL(10,2)   NOT NULL,
    total           DECIMAL(10,2)   NOT NULL,
    currency        VARCHAR(10)     NOT NULL DEFAULT 'XAF',
    status          ENUM('PENDING_PAYMENT','PAYMENT_SUBMITTED','APPROVED','REJECTED','CANCELLED') NOT NULL DEFAULT 'PENDING_PAYMENT',
    payment_status  ENUM('NOT_SUBMITTED','SUBMITTED','APPROVED','REJECTED') NOT NULL DEFAULT 'NOT_SUBMITTED',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at     DATETIME        NULL,
    UNIQUE KEY uq_orders_number (order_number),
    KEY idx_orders_student (student_id),
    KEY idx_orders_status (status),
    CONSTRAINT fk_orders_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id    INT UNSIGNED    NOT NULL,
    document_id INT UNSIGNED    NOT NULL,
    quantity    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    unit_price  DECIMAL(10,2)   NOT NULL,
    total       DECIMAL(10,2)   NOT NULL,
    KEY idx_order_items_order (order_id),
    KEY idx_order_items_document (document_id),
    CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_order_items_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_proofs (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id            INT UNSIGNED    NOT NULL,
    method              ENUM('MTN_MOMO','ORANGE_MONEY') NOT NULL,
    reference           VARCHAR(100)    NULL,
    amount              DECIMAL(10,2)   NOT NULL,
    proof_path          VARCHAR(255)    NOT NULL,  -- storage/receipts/<random>.(pdf|jpg|png)
    status              ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
    reviewed_by         INT UNSIGNED    NULL,
    reviewed_at         DATETIME        NULL,
    rejection_reason    VARCHAR(255)    NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_payment_proofs_order (order_id),
    KEY idx_payment_proofs_status (status),
    CONSTRAINT fk_payment_proofs_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_payment_proofs_admin FOREIGN KEY (reviewed_by) REFERENCES admins(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS entitlements (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id          INT UNSIGNED    NOT NULL,
    document_id         INT UNSIGNED    NOT NULL,
    order_id            INT UNSIGNED    NOT NULL,
    granted_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    download_count      INT UNSIGNED    NOT NULL DEFAULT 0,
    last_download_at    DATETIME        NULL,
    UNIQUE KEY uq_entitlement_student_document (student_id, document_id),
    KEY idx_entitlements_order (order_id),
    KEY idx_entitlements_document (document_id),
    CONSTRAINT fk_entitlements_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_entitlements_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_entitlements_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS downloads (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entitlement_id  INT UNSIGNED    NOT NULL,
    student_id      INT UNSIGNED    NOT NULL,
    document_id     INT UNSIGNED    NOT NULL,
    ip_address      VARCHAR(45)     NULL,
    user_agent      VARCHAR(255)    NULL,
    downloaded_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_downloads_entitlement (entitlement_id),
    KEY idx_downloads_student (student_id),
    KEY idx_downloads_document (document_id),
    CONSTRAINT fk_downloads_entitlement FOREIGN KEY (entitlement_id) REFERENCES entitlements(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_downloads_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_downloads_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoices (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id        INT UNSIGNED    NOT NULL,
    invoice_number  VARCHAR(30)     NOT NULL,
    file_path       VARCHAR(255)    NOT NULL,  -- storage/receipts/<random>.pdf
    issued_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_invoices_number (invoice_number),
    UNIQUE KEY uq_invoices_order (order_id),
    CONSTRAINT fk_invoices_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. LOGS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_logs (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id  INT UNSIGNED    NULL,
    email       VARCHAR(190)    NOT NULL,
    subject     VARCHAR(255)    NOT NULL,
    type        VARCHAR(50)     NOT NULL,  -- e.g. verify_email, payment_submitted, payment_approved, payment_rejected, receipt_issued
    status      ENUM('SENT','FAILED') NOT NULL,
    sent_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_email_logs_student (student_id),
    KEY idx_email_logs_type (type),
    CONSTRAINT fk_email_logs_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id    INT UNSIGNED    NULL,
    action      VARCHAR(100)    NOT NULL,
    entity_type VARCHAR(50)     NOT NULL,
    entity_id   INT UNSIGNED    NULL,
    details     TEXT            NULL,
    ip_address  VARCHAR(45)     NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_logs_admin (admin_id),
    KEY idx_audit_logs_entity (entity_type, entity_id),
    CONSTRAINT fk_audit_logs_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. SETTINGS — key/value store for payment accounts, branding, limits
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    setting_key     VARCHAR(100)    NOT NULL PRIMARY KEY,
    setting_value   TEXT            NULL,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- SEED DATA
-- Landmark University is seeded as ordinary data, not hard-coded PHP,
-- so additional universities can be added later purely through the
-- admin portal without touching application code.
-- =====================================================================

-- Default super admin. Email: admin@notebank.test  Password: NoteBank@2026
-- CHANGE THIS PASSWORD IMMEDIATELY AFTER FIRST LOGIN.
INSERT INTO admins (full_name, email, password_hash, role, status) VALUES
('Note Bank Super Admin', 'admin@notebank.test', '$2y$12$p80j.9RpFocHYlZyuE7o1O5g4qzPgOtqT/JSIotqx1s3qxux.7UdC', 'super_admin', 'active');
-- The hash above is a bcrypt hash of "NoteBank@2026" generated with PHP password_hash().

INSERT INTO universities (name, code, country, status) VALUES
('Landmark University', 'LMU', 'Nigeria', 'active');

SET @uni_id = LAST_INSERT_ID();

INSERT INTO faculties (university_id, name, code, status) VALUES
(@uni_id, 'College of Science and Embedded Technology', 'COSET', 'active'),
(@uni_id, 'College of Business and Social Sciences', 'CBSS', 'active'),
(@uni_id, 'College of Environmental Sciences', 'COES', 'active');

SET @fac_coset = (SELECT id FROM faculties WHERE university_id = @uni_id AND code = 'COSET');
SET @fac_cbss  = (SELECT id FROM faculties WHERE university_id = @uni_id AND code = 'CBSS');

INSERT INTO departments (faculty_id, name, code, status) VALUES
(@fac_coset, 'Computer Science', 'CSC', 'active'),
(@fac_coset, 'Mathematical Sciences', 'MTS', 'active'),
(@fac_cbss, 'Accounting', 'ACC', 'active'),
(@fac_cbss, 'Economics', 'ECO', 'active');

SET @dept_csc = (SELECT id FROM departments WHERE faculty_id = @fac_coset AND code = 'CSC');
SET @dept_acc = (SELECT id FROM departments WHERE faculty_id = @fac_cbss AND code = 'ACC');

INSERT INTO levels (department_id, name, code, status) VALUES
(@dept_csc, '100 Level', '100', 'active'),
(@dept_csc, '200 Level', '200', 'active'),
(@dept_csc, '300 Level', '300', 'active'),
(@dept_csc, '400 Level', '400', 'active'),
(@dept_acc, '100 Level', '100', 'active'),
(@dept_acc, '200 Level', '200', 'active'),
(@dept_acc, '300 Level', '300', 'active'),
(@dept_acc, '400 Level', '400', 'active');

INSERT INTO semesters (name, code, status) VALUES
('First Semester', 'FIRST', 'active'),
('Second Semester', 'SECOND', 'active');

SET @lvl_csc300 = (SELECT id FROM levels WHERE department_id = @dept_csc AND code = '300');
SET @lvl_csc200 = (SELECT id FROM levels WHERE department_id = @dept_csc AND code = '200');
SET @sem_first  = (SELECT id FROM semesters WHERE code = 'FIRST');
SET @sem_second = (SELECT id FROM semesters WHERE code = 'SECOND');

INSERT INTO courses (university_id, department_id, level_id, semester_id, code, title, description, status) VALUES
(@uni_id, @dept_csc, @lvl_csc300, @sem_first, 'CSC301', 'Data Structures and Algorithms', 'Core data structures, algorithm design and complexity analysis.', 'active'),
(@uni_id, @dept_csc, @lvl_csc300, @sem_second, 'CSC308', 'Operating Systems', 'Processes, memory management, scheduling and file systems.', 'active'),
(@uni_id, @dept_csc, @lvl_csc200, @sem_first, 'CSC201', 'Computer Programming II', 'Object-oriented programming concepts and practice.', 'active');

INSERT INTO settings (setting_key, setting_value) VALUES
('site_name', 'Note Bank'),
('site_tagline', 'Learn. Access. Succeed.'),
('support_email', 'support@notebank.test'),
('default_currency', 'XAF'),
('currency_symbol', 'FCFA'),
('mtn_momo_name', 'Note Bank Nigeria'),
('mtn_momo_number', '0000000000'),
('orange_money_name', 'Note Bank Nigeria'),
('orange_money_number', '0000000000'),
('preview_max_pages', '3'),
('preview_session_minutes', '20'),
('download_limit_per_entitlement', '0');
-- download_limit_per_entitlement = 0 means unlimited; set >0 to cap re-downloads.
