<?php
// Run ONCE in the browser: http://localhost/vetconnect/install.php   (add ?demo=1 for sample vets/farmer)
require __DIR__ . '/api/config.php';
header('Content-Type: text/html; charset=utf-8');
try {
  $pdo = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  foreach (array_filter(array_map('trim', explode(';', file_get_contents(__DIR__ . '/database.sql')))) as $sql) {
    if (!preg_match('/^--/', $sql) || strpos($sql, "\n") !== false) $pdo->exec(preg_replace('/^--.*$/m', '', $sql));
  }
  $pdo->exec('USE ' . DB_NAME);
  if (!$pdo->query("SELECT 1 FROM users WHERE role='admin'")->fetch()) {
    $pdo->prepare("INSERT INTO users (role,name,email,phone,password_hash,county) VALUES ('admin','Administrator',?,?,?,'Nairobi')")
        ->execute([ADMIN_EMAIL, '0700000000', password_hash(ADMIN_PASSWORD, PASSWORD_DEFAULT)]);
  }
  $msg = '';
  if (isset($_GET['demo'])) {
    $h = password_hash('password123', PASSWORD_DEFAULT);
    $ins = $pdo->prepare('INSERT IGNORE INTO users (role,name,phone,email,password_hash,county,lat,lng,specialty,license) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $ins->execute(['vet', 'Dr. Otieno Owuor', '0711000001', 'otieno@vet.ke', $h, 'Kajiado', -1.3950, 36.7450, 'Large animals (cattle)', 'KVB/1001']);
    $ins->execute(['vet', 'Dr. Aisha Noor', '0711000002', 'aisha@vet.ke', $h, 'Nairobi', -1.2921, 36.8219, 'Small ruminants (goats/sheep)', 'KVB/1002']);
    $ins->execute(['vet', 'Dr. Kamau Njoroge', '0711000003', 'kamau@vet.ke', $h, 'Nakuru', -0.3031, 36.0800, 'Poultry', 'KVB/1003']);
    $ins->execute(['farmer', 'Wanjiru Kamau', '0722000001', 'wanjiru@farm.ke', $h, 'Kajiado', -1.4100, 36.7300, null, null]);
    $msg = '<p>Demo accounts added (password <b>password123</b>): otieno@vet.ke, aisha@vet.ke, kamau@vet.ke, wanjiru@farm.ke</p>';
  }
  echo '<body style="font-family:sans-serif;max-width:560px;margin:3rem auto"><h2>✅ VetConnect database is ready</h2>'
     . '<p>Admin login: <b>' . ADMIN_EMAIL . '</b> / <b>' . ADMIN_PASSWORD . '</b></p>' . $msg
     . '<p><a href="index.html">Open the site →</a> &nbsp; <a href="install.php?demo=1">Add demo accounts</a></p>';
} catch (Throwable $e) {
  http_response_code(500);
  echo '<body style="font-family:sans-serif;max-width:560px;margin:3rem auto"><h2>❌ Setup failed</h2><p>' . htmlspecialchars($e->getMessage()) . '</p><p>Is MySQL started in the XAMPP Control Panel? Do the details in api/config.php match?</p>';
}
