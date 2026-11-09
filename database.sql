CREATE DATABASE IF NOT EXISTS vetconnect CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE vetconnect;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role ENUM('farmer','vet','admin') NOT NULL,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(30) NULL UNIQUE,
  email VARCHAR(120) NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  county VARCHAR(60), lat DOUBLE NULL, lng DOUBLE NULL,
  specialty VARCHAR(80), license VARCHAR(40),
  available TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS animals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  farmer_id INT NOT NULL, species VARCHAR(60) NOT NULL, name VARCHAR(80), tag VARCHAR(40), age VARCHAR(40), notes TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (farmer_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  farmer_id INT NOT NULL, animal_id INT NULL,
  species VARCHAR(60) NOT NULL, problem TEXT NOT NULL,
  urgency ENUM('routine','urgent','emergency') NOT NULL DEFAULT 'routine',
  county VARCHAR(60), location_text VARCHAR(255), lat DOUBLE NULL, lng DOUBLE NULL,
  status ENUM('pending','accepted','in_progress','completed','declined','cancelled') NOT NULL DEFAULT 'pending',
  vet_id INT NULL, scheduled_at VARCHAR(30) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (farmer_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (animal_id) REFERENCES animals(id) ON DELETE SET NULL,
  FOREIGN KEY (vet_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX (status), INDEX (vet_id)
);
CREATE TABLE IF NOT EXISTS messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT NOT NULL, sender_id INT NOT NULL,
  kind ENUM('message','appointment','advice') NOT NULL DEFAULT 'message',
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
  FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX (request_id)
);
CREATE TABLE IF NOT EXISTS records (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT NULL, farmer_id INT NOT NULL, vet_id INT NULL, animal_id INT NULL,
  animal_info VARCHAR(255), diagnosis TEXT, treatment TEXT, follow_up_date DATE NULL, notes TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE SET NULL,
  FOREIGN KEY (farmer_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (vet_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (animal_id) REFERENCES animals(id) ON DELETE SET NULL
);
-- Realtime feed: each row is a notification for one user, picked up by the browser every few seconds
CREATE TABLE IF NOT EXISTS events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL, type VARCHAR(40) NOT NULL, payload MEDIUMTEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id, id)
);
