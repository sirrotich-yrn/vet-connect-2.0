<?php
// XAMPP defaults: MySQL user "root" with an empty password. Change if yours differs.
const DB_HOST = '127.0.0.1';
const DB_NAME = 'vetconnect';
const DB_USER = 'root';
const DB_PASS = '';

const SECRET = 'change-this-to-any-long-random-text';   // signs login tokens
const MATCH_RADIUS_KM = 60;                              // how far a request reaches vets
const ADMIN_EMAIL = 'admin@vetconnect.co.ke';
const ADMIN_PASSWORD = 'admin123';                       // used by install.php — change it
