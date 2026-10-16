-- ==============================================================================
-- Proiect DAW: AgroLocal Marketplace - Schema Relațională a Bazei de Date (MySQL 8.0+)
-- Arhitectură completă bazată pe platforma curentă: Fermieri Bio, Produse, Comenzi multi-item,
-- Stripe Checkout, Recenzii verificate, Puncte de Ridicare și Jurnalizare DevSecOps.
-- ==============================================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `security_audit_logs`;
DROP TABLE IF EXISTS `reviews`;
DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `order_items`;
DROP TABLE IF EXISTS `orders`;
DROP TABLE IF EXISTS `pickup_points`;
DROP TABLE IF EXISTS `product_images`;
DROP TABLE IF EXISTS `products`;
DROP TABLE IF EXISTS `eco_certifications`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `farmers`;
DROP TABLE IF EXISTS `users`;

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------------------------
-- 1. TABEL: users (Autentificare, Roluri și Securitate)
-- ------------------------------------------------------------------------------
CREATE TABLE `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(120) NOT NULL,
    `email` VARCHAR(150) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `phone` VARCHAR(25) NOT NULL,
    `role` ENUM('customer', 'farmer', 'admin') NOT NULL DEFAULT 'customer',
    `is_active` BOOLEAN NOT NULL DEFAULT 1,
    `email_verified_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `users_email_unique` (`email`),
    INDEX `idx_users_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Utilizatori ai platformei: clienți, fermieri și administratori';

-- ------------------------------------------------------------------------------
-- 2. TABEL: farmers (Profilul detaliat al fermierului local & Stripe Connect)
-- ------------------------------------------------------------------------------
CREATE TABLE `farmers` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `farm_name` VARCHAR(120) NOT NULL,
    `cui_cif` VARCHAR(25) NULL COMMENT 'Cod Fiscal sau Atestat Producător Agricol',
    `county` VARCHAR(60) NOT NULL COMMENT 'Județ (ex: Brașov, Cluj, Vrancea)',
    `city` VARCHAR(80) NOT NULL,
    `farm_address` VARCHAR(255) NOT NULL,
    `latitude` DECIMAL(10, 8) NULL,
    `longitude` DECIMAL(11, 8) NULL,
    `iban` VARCHAR(34) NULL COMMENT 'Cont bancar IBAN pentru transferul câștigurilor',
    `stripe_account_id` VARCHAR(100) NULL COMMENT 'Cont Stripe Connect pentru payout direct',
    `bio_description` TEXT NULL COMMENT 'Povestea fermei și a tradiției agricole',
    `verified_producer` BOOLEAN NOT NULL DEFAULT 0 COMMENT 'Verificat oficial cu documente',
    `verification_date` DATE NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `farmers_user_id_unique` (`user_id`),
    INDEX `idx_farmers_county` (`county`),
    INDEX `idx_farmers_verified` (`verified_producer`),
    CONSTRAINT `fk_farmers_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Profiluri de producători locali și fermieri înregistrați';

-- ------------------------------------------------------------------------------
-- 3. TABEL: categories (Categorii ierarhice de produse agricole)
-- ------------------------------------------------------------------------------
CREATE TABLE `categories` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `parent_id` INT UNSIGNED NULL COMMENT 'Pentru subcategorii ierarhice',
    `name` VARCHAR(80) NOT NULL,
    `slug` VARCHAR(100) NOT NULL,
    `description` VARCHAR(255) NULL,
    `icon_class` VARCHAR(50) NULL DEFAULT 'bi-basket',
    `is_active` BOOLEAN NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `categories_slug_unique` (`slug`),
    INDEX `idx_categories_parent` (`parent_id`),
    CONSTRAINT `fk_categories_parent_id` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Clasificarea legumelor, fructelor, mierii, lactatelor etc.';

-- ------------------------------------------------------------------------------
-- 4. TABEL: eco_certifications (Atestate BIO oficiale RO-ECO)
-- ------------------------------------------------------------------------------
CREATE TABLE `eco_certifications` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `farmer_id` INT UNSIGNED NOT NULL,
    `certificate_code` VARCHAR(50) NOT NULL COMMENT 'Cod oficial (ex: RO-ECO-102, RO-ECO-451)',
    `issuing_body` VARCHAR(120) NOT NULL COMMENT 'Organism de Inspecție (ex: Bio Garantie, CertRom, Ecoinspect)',
    `valid_from` DATE NOT NULL,
    `valid_until` DATE NOT NULL,
    `document_scan_url` VARCHAR(255) NULL,
    `is_verified` BOOLEAN NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_eco_farmer` (`farmer_id`),
    INDEX `idx_eco_cert_code` (`certificate_code`),
    CONSTRAINT `fk_eco_certifications_farmer` FOREIGN KEY (`farmer_id`) REFERENCES `farmers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Validarea riguroasă a certificatelor ecologice RO-ECO';

-- ------------------------------------------------------------------------------
-- 5. TABEL: products (Produse agricole cu calcul automat de reducere ECO)
-- ------------------------------------------------------------------------------
CREATE TABLE `products` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `farmer_id` INT UNSIGNED NOT NULL,
    `category_id` INT UNSIGNED NOT NULL,
    `eco_certification_id` INT UNSIGNED NULL COMMENT 'Legătură directă la certificatul BIO validat',
    `name` VARCHAR(120) NOT NULL,
    `slug` VARCHAR(150) NOT NULL,
    `short_description` VARCHAR(255) NULL,
    `description` TEXT NULL,
    `base_price` DECIMAL(10, 2) NOT NULL COMMENT 'Prețul de listă stabilit de fermier',
    `eco_discount_percent` DECIMAL(5, 2) NOT NULL DEFAULT 5.00 COMMENT 'Discount ecologic automat (5% pe platformă)',
    `final_price` DECIMAL(10, 2) GENERATED ALWAYS AS (ROUND(`base_price` * (1 - (`eco_discount_percent` / 100)), 2)) STORED COMMENT 'Preț final plătit de client',
    `stock` INT NOT NULL DEFAULT 0,
    `unit_of_measure` ENUM('kg', 'g', 'buc', 'litru', 'borcan', 'legatura') NOT NULL DEFAULT 'kg',
    `harvest_date` DATE NULL COMMENT 'Data recoltării pentru prospețime maximă',
    `is_bio` BOOLEAN NOT NULL DEFAULT 1,
    `is_active` BOOLEAN NOT NULL DEFAULT 1,
    `image_filename` VARCHAR(255) NOT NULL COMMENT 'Nume fișier generat după igienizare anti-steganografie GD',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `products_slug_unique` (`slug`),
    INDEX `idx_products_farmer` (`farmer_id`),
    INDEX `idx_products_category` (`category_id`),
    INDEX `idx_products_eco_cert` (`eco_certification_id`),
    INDEX `idx_products_active_stock` (`is_active`, `stock`),
    CONSTRAINT `chk_base_price_positive` CHECK (`base_price` > 0),
    CONSTRAINT `chk_stock_non_negative` CHECK (`stock` >= 0),
    CONSTRAINT `fk_products_farmer` FOREIGN KEY (`farmer_id`) REFERENCES `farmers` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_products_eco_cert` FOREIGN KEY (`eco_certification_id`) REFERENCES `eco_certifications` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Catalogul de produse bio și locale cu prețuri calculate automat';

-- ------------------------------------------------------------------------------
-- 6. TABEL: product_images (Imagini multiple igienizate anti-steganografie)
-- ------------------------------------------------------------------------------
CREATE TABLE `product_images` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` INT UNSIGNED NOT NULL,
    `filename` VARCHAR(255) NOT NULL,
    `is_primary` BOOLEAN NOT NULL DEFAULT 0,
    `sanitized_by_gd` BOOLEAN NOT NULL DEFAULT 1 COMMENT 'Confirmare că metadatele EXIF au fost distruse',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_product_images_prod` (`product_id`),
    CONSTRAINT `fk_product_images_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Galeria de imagini sigure asociate fiecărui produs';

-- ------------------------------------------------------------------------------
-- 7. TABEL: pickup_points (Puncte de colectare directă & piețe locale volante)
-- ------------------------------------------------------------------------------
CREATE TABLE `pickup_points` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `farmer_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(120) NOT NULL COMMENT 'ex: Punct Ridicare Fermă Hărman sau Piața Sfatului Brașov',
    `county` VARCHAR(60) NOT NULL,
    `city` VARCHAR(80) NOT NULL,
    `address` VARCHAR(255) NOT NULL,
    `schedule_info` VARCHAR(200) NOT NULL COMMENT 'ex: În fiecare Sâmbătă 08:00 - 13:00',
    `is_active` BOOLEAN NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    INDEX `idx_pickup_farmer` (`farmer_id`),
    CONSTRAINT `fk_pickup_points_farmer` FOREIGN KEY (`farmer_id`) REFERENCES `farmers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Puncte de întâlnire între fermieri și cumpărători locali';

-- ------------------------------------------------------------------------------
-- 8. TABEL: orders (Comenzi plasate de clienți)
-- ------------------------------------------------------------------------------
CREATE TABLE `orders` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_number` VARCHAR(35) NOT NULL COMMENT 'Număr de comandă generat (ex: AGRO-2026-XXXX)',
    `buyer_id` INT UNSIGNED NULL COMMENT 'Utilizator înregistrat sau comandă rapidă guest',
    `buyer_name` VARCHAR(120) NOT NULL,
    `buyer_email` VARCHAR(150) NOT NULL,
    `buyer_phone` VARCHAR(25) NOT NULL,
    `delivery_type` ENUM('home_delivery', 'pickup_point') NOT NULL DEFAULT 'home_delivery',
    `pickup_point_id` INT UNSIGNED NULL,
    `delivery_county` VARCHAR(60) NOT NULL,
    `delivery_city` VARCHAR(80) NOT NULL,
    `delivery_address` VARCHAR(255) NOT NULL,
    `delivery_notes` VARCHAR(255) NULL,
    `subtotal_amount` DECIMAL(10, 2) NOT NULL,
    `discount_total` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    `shipping_fee` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    `total_amount` DECIMAL(10, 2) NOT NULL,
    `order_status` ENUM('pending', 'confirmed', 'harvesting', 'in_transit', 'delivered', 'cancelled') NOT NULL DEFAULT 'pending',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `orders_order_number_unique` (`order_number`),
    INDEX `idx_orders_buyer` (`buyer_id`),
    INDEX `idx_orders_status` (`order_status`),
    INDEX `idx_orders_created` (`created_at`),
    CONSTRAINT `fk_orders_buyer` FOREIGN KEY (`buyer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_orders_pickup_point` FOREIGN KEY (`pickup_point_id`) REFERENCES `pickup_points` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Comenzi gestionate în platformă';

-- ------------------------------------------------------------------------------
-- 9. TABEL: order_items (Linii de comandă - suport coș de cumpărături multi-produs)
-- ------------------------------------------------------------------------------
CREATE TABLE `order_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id` INT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `farmer_id` INT UNSIGNED NOT NULL COMMENT 'Pentru atribuirea venitului către fermierul respectiv',
    `product_name_snapshot` VARCHAR(120) NOT NULL COMMENT 'Numele produsului la momentul achiziției',
    `unit_price` DECIMAL(10, 2) NOT NULL,
    `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
    `line_total` DECIMAL(10, 2) NOT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_order_items_order` (`order_id`),
    INDEX `idx_order_items_product` (`product_id`),
    INDEX `idx_order_items_farmer` (`farmer_id`),
    CONSTRAINT `chk_order_item_qty` CHECK (`quantity` > 0),
    CONSTRAINT `fk_order_items_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_order_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_order_items_farmer` FOREIGN KEY (`farmer_id`) REFERENCES `farmers` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Articolele individuale incluse într-o comandă';

-- ------------------------------------------------------------------------------
-- 10. TABEL: payments (Integrare detaliată cu Stripe Checkout & Webhook)
-- ------------------------------------------------------------------------------
CREATE TABLE `payments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id` INT UNSIGNED NOT NULL,
    `stripe_session_id` VARCHAR(255) NOT NULL,
    `stripe_payment_intent_id` VARCHAR(255) NULL,
    `currency` VARCHAR(3) NOT NULL DEFAULT 'RON',
    `amount` DECIMAL(10, 2) NOT NULL,
    `payment_method` VARCHAR(50) NOT NULL DEFAULT 'card',
    `payment_status` ENUM('pending', 'succeeded', 'failed', 'refunded') NOT NULL DEFAULT 'pending',
    `receipt_url` VARCHAR(255) NULL,
    `error_message` VARCHAR(255) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `payments_stripe_session_unique` (`stripe_session_id`),
    INDEX `idx_payments_order` (`order_id`),
    INDEX `idx_payments_status` (`payment_status`),
    CONSTRAINT `fk_payments_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Jurnalul plăților online securizate prin Stripe SCA';

-- ------------------------------------------------------------------------------
-- 11. TABEL: reviews (Evaluări autentice cu achiziție verificată & răspuns fermier)
-- ------------------------------------------------------------------------------
CREATE TABLE `reviews` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` INT UNSIGNED NOT NULL,
    `order_id` INT UNSIGNED NOT NULL,
    `buyer_id` INT UNSIGNED NULL,
    `rating` TINYINT UNSIGNED NOT NULL COMMENT 'Notă de la 1 la 5 stele',
    `title` VARCHAR(100) NULL,
    `comment` TEXT NULL,
    `farmer_reply` TEXT NULL COMMENT 'Răspunsul fermierului la recenzie',
    `farmer_replied_at` TIMESTAMP NULL DEFAULT NULL,
    `is_verified_buyer` BOOLEAN NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_reviews_product` (`product_id`),
    INDEX `idx_reviews_order` (`order_id`),
    CONSTRAINT `chk_reviews_rating_range` CHECK (`rating` BETWEEN 1 AND 5),
    CONSTRAINT `fk_reviews_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_reviews_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_reviews_buyer` FOREIGN KEY (`buyer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Recenzii de la clienți cu verificare strictă a comenzii';

-- ------------------------------------------------------------------------------
-- 12. TABEL: security_audit_logs (Jurnalizare evenimente DevSecOps & Audit)
-- ------------------------------------------------------------------------------
CREATE TABLE `security_audit_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `user_agent` VARCHAR(255) NULL,
    `event_type` VARCHAR(50) NOT NULL COMMENT 'ex: CSRF_FAILED, IMAGE_SANITIZED, STRIPE_CHECKOUT_CREATED, PRODUCT_ADDED',
    `severity` ENUM('INFO', 'WARNING', 'CRITICAL') NOT NULL DEFAULT 'INFO',
    `payload_json` JSON NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_audit_event` (`event_type`),
    INDEX `idx_audit_user` (`user_id`),
    INDEX `idx_audit_created` (`created_at`),
    CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Jurnal de audit pentru cerințe de conformitate și securitate DevSecOps';

-- ==============================================================================
-- DATE INIȚIALE DEMONSTRATIVE (Seed Data sincronizate cu AgroLocal)
-- ==============================================================================

INSERT INTO `users` (`id`, `name`, `email`, `password_hash`, `phone`, `role`) VALUES
(1, 'Gheorghe Hărman', 'contact@fermaharman.ro', '$2y$10$wO9Ww2t8Q.Fm8q7R0XoWCe8F2yU2b0V5k1Z.J8Q8lZ9Y8w8e8r8t.', '0722111222', 'farmer'),
(2, 'Maria Popa', 'maria.popa@email.ro', '$2y$10$wO9Ww2t8Q.Fm8q7R0XoWCe8F2yU2b0V5k1Z.J8Q8lZ9Y8w8e8r8t.', '0744333444', 'customer'),
(3, 'Admin SecOps', 'security@agrolocal.ro', '$2y$10$wO9Ww2t8Q.Fm8q7R0XoWCe8F2yU2b0V5k1Z.J8Q8lZ9Y8w8e8r8t.', '0700000000', 'admin');

INSERT INTO `farmers` (`id`, `user_id`, `farm_name`, `cui_cif`, `county`, `city`, `farm_address`, `verified_producer`, `verification_date`) VALUES
(1, 1, 'Ferma Bio Hărman', 'RO34928172', 'Brașov', 'Hărman', 'Str. Morii Nr. 12', 1, '2026-01-15');

INSERT INTO `categories` (`id`, `parent_id`, `name`, `slug`, `icon_class`) VALUES
(1, NULL, 'Apicultură & Miere', 'apicultura-miere', 'bi-flower1'),
(2, NULL, 'Legume Proaspete', 'legume-proaspete', 'bi-egg-fried'),
(3, NULL, 'Lactate Tradiționale', 'lactate-traditionale', 'bi-cup-straw');

INSERT INTO `eco_certifications` (`id`, `farmer_id`, `certificate_code`, `issuing_body`, `valid_from`, `valid_until`) VALUES
(1, 1, 'RO-ECO-451', 'Ecoinspect S.R.L.', '2026-01-01', '2027-12-31'),
(2, 1, 'RO-ECO-102', 'CertRom Organism de Certificare', '2026-01-01', '2027-12-31');

INSERT INTO `products` (`id`, `farmer_id`, `category_id`, `eco_certification_id`, `name`, `slug`, `base_price`, `eco_discount_percent`, `stock`, `unit_of_measure`, `harvest_date`, `is_bio`, `image_filename`) VALUES
(1, 1, 1, 1, 'Miere Bio de Salcâm', 'miere-bio-de-salcam', 45.00, 5.00, 30, 'borcan', '2026-06-20', 1, 'default_honey.jpg'),
(2, 1, 2, 2, 'Roșii Inimă de Bou', 'rosii-inima-de-bou', 20.00, 5.00, 100, 'kg', '2026-08-10', 1, 'default_tomatoes.jpg');

INSERT INTO `pickup_points` (`id`, `farmer_id`, `name`, `county`, `city`, `address`, `schedule_info`) VALUES
(1, 1, 'Punct Ridicare Ferma Hărman', 'Brașov', 'Hărman', 'Str. Morii Nr. 12', 'Zilnic 09:00 - 18:00'),
(2, 1, 'Piața Astra - Brașov', 'Brașov', 'Brașov', 'Stand Producători Locali', 'Sâmbătă 08:00 - 14:00');
