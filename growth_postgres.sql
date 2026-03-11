-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 22, 2025 at 02:51 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12


;
;
TRUNCATE TABLE land_images, lands RESTART IDENTITY CASCADE;

INSERT INTO "lands" ("id", "title", "location", "size", "price_per_unit", "total_units", "available_units", "is_available", "description", "created_at", "updated_at") VALUES
(1, 'Growth Newtons', 'Tokyo', 2500, 1050.00, 1500, 1500, TRUE, 'A beautiful piece of land located in sunny Japan', '2025-10-21 10:40:36', '2025-10-21 10:40:36'),
(2, 'Growth Nold', 'Tokyo', 2500, 1050.00, 1500, 1500, TRUE, 'A beautiful piece of land located in sunny Japan', '2025-10-21 10:44:48', '2025-10-21 10:44:48'),
(3, 'Growth New', 'Tokyo', 2500, 1050.00, 1500, 1500, TRUE, 'A beautiful piece of land located in sunny Japan', '2025-10-21 12:22:28', '2025-10-21 12:22:28'),
(4, 'Palmeiras', 'Brazil', 25000, 10500.00, 1500, 1500, TRUE, 'A beautiful piece of land located in sunny Rio', '2025-10-21 13:23:43', '2025-10-21 13:23:43'),
(5, 'San Andreas', 'Brazil', 2000, 1000.00, 1500, 1500, TRUE, 'A beautiful piece of land located in sunny Rio', '2025-10-21 13:27:54', '2025-10-21 13:27:54'),
(6, 'Botafogo', 'Brazil', 3000, 4000.00, 1700, 1700, TRUE, 'A beautiful piece of land located in sunny Cali', '2025-10-21 13:44:41', '2025-10-21 13:44:41');

INSERT INTO "land_images" ("id", "land_id", "image_path", "created_at", "updated_at") VALUES
(1, 2, 'land_images/x6T2k5IQsWdKGhDKkDq8eAnjztn9VvAkQOBUUcLy.png', '2025-10-21 10:44:49', '2025-10-21 10:44:49'),
(2, 3, 'land_images/8JHeOj0va0zSMu25VuqMbbhszEZhQMI4A3TgDneb.jpg', '2025-10-21 12:22:28', '2025-10-21 12:22:28'),
(3, 3, 'land_images/rUPKxjscPIJrHmIslQ32JM5tf0LfC9OhGtT6wlAT.jpg', '2025-10-21 13:23:43', '2025-10-21 13:23:43'),
(4, 3, 'land_images/NDSPxbHs6zGStlWmr5lBmiVal8omFLGWkhau6oQj.png', '2025-10-21 13:27:54', '2025-10-21 13:27:54'),
(5, 6, 'land_images/cDfRIWI9bl0ODUwARZALr0L681ZhnjIFvsFkJLZg.jpg', '2025-10-21 13:44:41', '2025-10-21 13:44:41');

