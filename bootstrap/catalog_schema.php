<?php
declare(strict_types=1);
// Exact catalog DDL from the supplied 2026-11-01 backup. Local development only.
return [
<<<'SQL'
CREATE TABLE `brands` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `slug` varchar(60) NOT NULL,
  `name` varchar(120) NOT NULL,
  `short_note` varchar(200) DEFAULT NULL COMMENT 'Короткая подпись справа от названия в списке домов',
  `description` text DEFAULT NULL,
  `meta_title` varchar(255) DEFAULT NULL,
  `meta_description` varchar(500) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL,
<<<'SQL'
CREATE TABLE `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sku` varchar(150) NOT NULL COMMENT 'Артикул, латиницей, напр. tom-ford-tobacco-vanille',
  `brand_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `gender` varchar(60) DEFAULT NULL,
  `fragrance_group` varchar(120) DEFAULT NULL,
  `top_notes` varchar(255) DEFAULT NULL,
  `heart_notes` varchar(255) DEFAULT NULL,
  `base_notes` varchar(255) DEFAULT NULL,
  `release_year` varchar(20) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `meta_title` varchar(255) DEFAULT NULL,
  `meta_description` varchar(500) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `in_stock_msk` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'есть на складе в Москве',
  `in_stock_spb` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'есть на складе в Санкт-Петербурге',
  PRIMARY KEY (`id`),
  UNIQUE KEY `sku` (`sku`),
  KEY `brand_id` (`brand_id`),
  CONSTRAINT `products_ibfk_1` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=76 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL,
<<<'SQL'
CREATE TABLE `product_prices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `volume_ml` int(11) NOT NULL COMMENT '100, 50 или 5',
  `price` decimal(10,2) DEFAULT NULL,
  `promo_price` decimal(10,2) DEFAULT NULL,
  `promo_until` datetime DEFAULT NULL,
  `stock` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_product_volume` (`product_id`,`volume_ml`),
  CONSTRAINT `product_prices_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=272 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL
];
