<?php
// VetConnect Kenya — PHP/MySQL API for XAMPP. Called as  api/index.php/<route>
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

/* ------------------------------ Core helpers ------------------------------ */
function db(): PDO {
  static $pdo;
  if (!$pdo) {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");   // store/read everything in UTC; the browser converts
  }
  return $pdo;
}
function q(string $sql, array $p = []): PDOStatement { $s = db()->prepare($sql); $s->execute($p); return $s; }
function one(string $sql, array $p = []) { return q($sql, $p)->fetch() ?: null; }
function all(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function out($d, int $code = 200) { http_response_code($code); echo json_encode($d); exit; }
function fail(string $m, int $code = 400) { out(['error' => $m], $code); }
function b64($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

function make_token(int $id): string { $p = b64(json_encode(['id' => $id, 'exp' => time() + 7 * 86400])); return $p . '.' . hash_hmac('sha256', $p, SECRET); }
function read_token(string $t): ?int {
  $parts = explode('.', $t);
  if (count($parts) !== 2 || !hash_equals(hash_hmac('sha256', $parts[0], SECRET), $parts[1])) return null;
  $d = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
  return ($d && $d['exp'] > time()) ? (int)$d['id'] : null;
}
function pub(?array $u): ?array {
  return $u ? ['id' => (int)$u['id'], 'role' => $u['role'], 'name' => $u['name'], 'phone' => $u['phone'], 'email' => $u['email'], 'county' => $u['county'],
    'lat' => $u['lat'], 'lng' => $u['lng'], 'specialty' => $u['specialty'], 'license' => $u['license'], 'available' => (bool)$u['available']] : null;
}
function km($aLat, $aLng, $bLat, $bLng): ?float {
  if ($aLat === null || $aLng === null || $bLat === null || $bLng === null) return null;
  $r = 6371; $d = fn($x) => $x * M_PI / 180;
  $h = sin($d($bLat - $aLat) / 2) ** 2 + cos($d($aLat)) * cos($d($bLat)) * sin($d($bLng - $aLng) / 2) ** 2;
  return round(2 * $r * asin(sqrt($h)), 1);
}
$COUNTIES = ['Nairobi' => [-1.2864, 36.8172], 'Kajiado' => [-1.8523, 36.7768], 'Nakuru' => [-0.3031, 36.0800], 'Kiambu' => [-1.1714, 36.8356],
  'Meru' => [0.0480, 37.6559], 'Kakamega' => [0.2827, 34.7519], 'Marsabit' => [2.3284, 37.9899], 'Other' => [-0.0236, 37.9062]];

/* Realtime: write a notification row per user; browsers poll GET /events */
function emit(array $userIds, string $type, $data): void {
  foreach (array_unique(array_filter($userIds)) as $id) q('INSERT INTO events (user_id,type,payload) VALUES (?,?,?)', [$id, $type, json_encode($data)]);
}
function ids_of(string $role): array { return array_column(all('SELECT id FROM users WHERE role=?', [$role]), 'id'); }

const REQ_SELECT = 'SELECT r.*, f.name AS farmer_name, f.phone AS farmer_phone, v.name AS vet_name, v.phone AS vet_phone,
  a.name AS animal_name, a.tag AS animal_tag FROM requests r JOIN users f ON f.id=r.farmer_id
  LEFT JOIN users v ON v.id=r.vet_id LEFT JOIN animals a ON a.id=r.animal_id';
const REC_SELECT = "SELECT rc.*, f.name AS farmer_name, f.phone AS farmer_phone, f.county AS farmer_county, v.name AS vet_name,
  COALESCE(rc.animal_info, CONCAT(a.species, COALESCE(CONCAT(' - ', a.name), ''))) AS animal_label FROM records rc
  JOIN users f ON f.id=rc.farmer_id LEFT JOIN users v ON v.id=rc.vet_id LEFT JOIN animals a ON a.id=rc.animal_id";
const MSG_SELECT = 'SELECT m.*, u.name AS sender_name, u.role AS sender_role FROM messages m JOIN users u ON u.id=m.sender_id';

function get_req(int $id) { return one(REQ_SELECT . ' WHERE r.id=?', [$id]); }
function nearby_vets($lat, $lng, $radius = MATCH_RADIUS_KM, bool $onlyAvail = true): array {
  $rows = all("SELECT * FROM users WHERE role='vet'" . ($onlyAvail ? ' AND available=1' : ''));
  $res = [];
  foreach ($rows as $v) { $d = km($lat, $lng, $v['lat'], $v['lng']); if ($d !== null && $d <= $radius) $res[] = pub($v) + ['distance_km' => $d]; }
  usort($res, fn($a, $b) => $a['distance_km'] <=> $b['distance_km']);
  return $res;
}

/* ------------------------------ Request + routing ------------------------------ */
$method = $_SERVER['REQUEST_METHOD'];
$path = '/' . trim($_SERVER['PATH_INFO'] ?? '', '/');
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$me = null;

function route(string $m, string $pattern, callable $fn, ?array $roles = null, bool $public = false): void {
  global $method, $path, $me;
  if ($m !== $method || !preg_match('#^' . $pattern . '$#', $path, $mt)) return;
  if (!$public) {
    $id = read_token($_SERVER['HTTP_X_AUTH_TOKEN'] ?? '');
    $me = $id ? one('SELECT * FROM users WHERE id=?', [$id]) : null;
    if (!$me) fail('Please log in', 401);
    if ($roles && !in_array($me['role'], $roles, true)) fail('Not allowed', 403);
  }
  array_shift($mt);
  $fn(...$mt);
  exit;
}

try {

/* ---- Auth ---- */
route('POST', '/auth/register', function () use ($body, $COUNTIES) {
  $role = $body['role'] ?? ''; $name = trim($body['name'] ?? ''); $pw = $body['password'] ?? '';
  $phone = preg_replace('/\s+/', '', $body['phone'] ?? '') ?: null; $email = trim($body['email'] ?? '') ?: null;
  if (!in_array($role, ['farmer', 'vet'], true)) fail('Invalid role');
  if (!$name || strlen($pw) < 8 || !($phone || $email)) fail('Name, phone/email and an 8+ character password are required');
  if ($role === 'vet' && empty($body['license'])) fail('KVB licence number required');
  $county = $body['county'] ?? 'Other'; $c = $COUNTIES[$county] ?? $COUNTIES['Other'];
  $lat = ($body['lat'] ?? '') !== '' ? (float)$body['lat'] : $c[0]; $lng = ($body['lng'] ?? '') !== '' ? (float)$body['lng'] : $c[1];
  if (($phone && one('SELECT 1 FROM users WHERE phone=?', [$phone])) || ($email && one('SELECT 1 FROM users WHERE email=?', [$email]))) fail('That phone or email is already registered', 409);
  q('INSERT INTO users (role,name,phone,email,password_hash,county,lat,lng,specialty,license) VALUES (?,?,?,?,?,?,?,?,?,?)',
    [$role, $name, $phone, $email, password_hash($pw, PASSWORD_DEFAULT), $county, $lat, $lng, $body['specialty'] ?? null, $body['license'] ?? null]);
  $id = (int)db()->lastInsertId();
  out(['token' => make_token($id), 'user' => pub(one('SELECT * FROM users WHERE id=?', [$id]))]);
}, null, true);

route('POST', '/auth/login', function () use ($body) {
  $id = trim($body['identifier'] ?? '');
  $u = one('SELECT * FROM users WHERE email=? OR phone=?', [$id, preg_replace('/\s+/', '', $id)]);
  if (!$u || !password_verify($body['password'] ?? '', $u['password_hash'])) fail('Wrong details — check and try again', 401);
  out(['token' => make_token((int)$u['id']), 'user' => pub($u)]);
}, null, true);

route('GET', '/me', fn() => out(pub($GLOBALS['me'])));

route('PUT', '/me/location', function () use ($body) {
  global $me;
  if (!isset($body['lat'], $body['lng']) || !is_numeric($body['lat']) || !is_numeric($body['lng'])) fail('lat/lng required');
  q('UPDATE users SET lat=?, lng=?, county=COALESCE(?,county) WHERE id=?', [$body['lat'], $body['lng'], $body['county'] ?? null, $me['id']]);
  if ($me['role'] === 'vet') emit(ids_of('farmer'), 'vets:changed', new stdClass);
  out(pub(one('SELECT * FROM users WHERE id=?', [$me['id']])));
});

route('PUT', '/me/availability', function () use ($body) {
  global $me; $a = !empty($body['available']) ? 1 : 0;
  q('UPDATE users SET available=? WHERE id=?', [$a, $me['id']]);
  emit(ids_of('farmer'), 'vets:changed', new stdClass);
  out(['available' => (bool)$a]);
}, ['vet']);

/* ---- Realtime polling ---- */
route('GET', '/events', function () {
  global $me; $since = (int)($_GET['since'] ?? -1);
  if (mt_rand(1, 50) === 1) q('DELETE FROM events WHERE created_at < (NOW() - INTERVAL 2 DAY)');
  if ($since < 0) out(['last' => (int)(one('SELECT MAX(id) m FROM events')['m'] ?? 0), 'events' => []]);
  $rows = all('SELECT id,type,payload FROM events WHERE user_id=? AND id>? ORDER BY id LIMIT 100', [$me['id'], $since]);
  $last = $since; $ev = [];
  foreach ($rows as $r) { $last = (int)$r['id']; $ev[] = ['id' => $last, 'type' => $r['type'], 'data' => json_decode($r['payload'], true)]; }
  if (!$rows) $last = max($since, (int)(one('SELECT MAX(id) m FROM events')['m'] ?? 0));
  out(['last' => $last, 'events' => $ev]);
});

/* ---- Vets nearby ---- */
route('GET', '/vets/nearby', function () {
  global $me;
  $lat = isset($_GET['lat']) ? (float)$_GET['lat'] : $me['lat']; $lng = isset($_GET['lng']) ? (float)$_GET['lng'] : $me['lng'];
  out(nearby_vets($lat, $lng, (float)($_GET['radius'] ?? MATCH_RADIUS_KM), ($_GET['all'] ?? '') !== '1'));
});

/* ---- Animals ---- */
route('GET', '/animals', fn() => out(all('SELECT * FROM animals WHERE farmer_id=? ORDER BY id DESC', [$GLOBALS['me']['id']])), ['farmer']);
route('POST', '/animals', function () use ($body) {
  global $me; if (empty($body['species'])) fail('Species required');
  q('INSERT INTO animals (farmer_id,species,name,tag,age,notes) VALUES (?,?,?,?,?,?)', [$me['id'], $body['species'], $body['name'] ?? null, $body['tag'] ?? null, $body['age'] ?? null, $body['notes'] ?? null]);
  out(one('SELECT * FROM animals WHERE id=?', [db()->lastInsertId()]));
}, ['farmer']);
route('DELETE', '/animals/(\d+)', function ($id) { q('DELETE FROM animals WHERE id=? AND farmer_id=?', [$id, $GLOBALS['me']['id']]); out(['ok' => true]); }, ['farmer']);

/* ---- 1. Farmer submits a request; nearby vets are alerted ---- */
route('POST', '/requests', function () use ($body) {
  global $me;
  if (empty($body['species']) || empty($body['problem'])) fail('Animal and problem are required');
  $lat = $body['lat'] ?? $me['lat']; $lng = $body['lng'] ?? $me['lng'];
  $urg = in_array($body['urgency'] ?? '', ['routine', 'urgent', 'emergency'], true) ? $body['urgency'] : 'routine';
  q('INSERT INTO requests (farmer_id,animal_id,species,problem,urgency,county,location_text,lat,lng) VALUES (?,?,?,?,?,?,?,?,?)',
    [$me['id'], ($body['animal_id'] ?? '') ?: null, $body['species'], $body['problem'], $urg, $body['county'] ?? $me['county'], $body['location_text'] ?? null, $lat, $lng]);
  $row = get_req((int)db()->lastInsertId());
  $vets = nearby_vets($lat, $lng);
  foreach ($vets as $v) emit([$v['id']], 'request:new', $row + ['distance_km' => $v['distance_km']]);
  emit(ids_of('admin'), 'request:update', $row);
  out(['request' => $row, 'vets_notified' => count($vets)]);
}, ['farmer']);

route('GET', '/requests', function () {
  global $me; $u = $me;
  if ($u['role'] === 'farmer') out(all(REQ_SELECT . ' WHERE r.farmer_id=? ORDER BY r.id DESC', [$u['id']]));
  if ($u['role'] === 'admin') out(all(REQ_SELECT . ' ORDER BY r.id DESC'));
  $withDist = fn($r) => $r + ['distance_km' => km($u['lat'], $u['lng'], $r['lat'], $r['lng'])];
  $mine = array_map($withDist, all(REQ_SELECT . ' WHERE r.vet_id=? ORDER BY r.id DESC', [$u['id']]));
  $open = array_values(array_filter(array_map($withDist, all(REQ_SELECT . " WHERE r.status='pending' AND r.vet_id IS NULL ORDER BY r.id DESC")),
    fn($r) => $r['distance_km'] !== null && $r['distance_km'] <= MATCH_RADIUS_KM));
  out(array_merge($mine, $open));
});

/* ---- 2. Vet manages: accept / decline / start / complete / schedule ---- */
route('PATCH', '/requests/(\d+)', function ($id) use ($body) {
  global $me; $u = $me;
  $r = one('SELECT * FROM requests WHERE id=?', [$id]);
  if (!$r) fail('Not found', 404);
  $action = $body['action'] ?? '';
  if ($u['role'] === 'vet' && $action === 'accept' && ($r['vet_id'] || $r['status'] !== 'pending')) fail('Another vet already took this request', 409);
  $canSee = $u['role'] === 'admin' || $r['farmer_id'] == $u['id'] || $r['vet_id'] == $u['id'] || ($u['role'] === 'vet' && $r['status'] === 'pending');
  if (!$canSee) fail('Not found', 404);
  $set = fn($sql, ...$p) => q("UPDATE requests SET $sql, updated_at=NOW() WHERE id=?", [...$p, $r['id']]);
  $msg = fn($kind, $text) => q('INSERT INTO messages (request_id,sender_id,kind,body) VALUES (?,?,?,?)', [$r['id'], $u['id'], $kind, $text]);

  if ($u['role'] === 'vet') {
    if ($action === 'accept') { $set("status='accepted', vet_id=?", $u['id']); $msg('message', "Hello, I'm {$u['name']}. I've accepted your request and will be in touch shortly."); }
    elseif ($r['vet_id'] != $u['id']) fail('Not assigned to you', 403);
    elseif ($action === 'decline') $set("status='pending', vet_id=NULL");
    elseif ($action === 'start') $set("status='in_progress'");
    elseif ($action === 'complete') $set("status='completed'");
    elseif ($action === 'schedule' && !empty($body['scheduled_at'])) { $set('scheduled_at=?', $body['scheduled_at']); $msg('appointment', 'Appointment set for ' . str_replace('T', ' ', $body['scheduled_at'])); }
    else fail('Unknown action');
  } elseif ($action === 'cancel' && ($u['role'] === 'admin' || ($u['role'] === 'farmer' && $r['farmer_id'] == $u['id']))) $set("status='cancelled'");
  else fail('Not allowed', 403);

  $row = get_req((int)$r['id']);
  emit([$row['farmer_id'], $row['vet_id'], $r['vet_id'], ...ids_of('admin')], 'request:update', $row);
  if ($action === 'accept') emit(ids_of('vet'), 'request:taken', ['id' => (int)$r['id']]);
  if ($action === 'schedule' || $action === 'accept') { $m = one(MSG_SELECT . ' WHERE m.request_id=? ORDER BY m.id DESC LIMIT 1', [$r['id']]); emit([$row['farmer_id'], $row['vet_id']], 'message:new', $m); }
  out($row);
});

/* ---- 3. Messages (chat, appointments, advice) ---- */
route('GET', '/requests/(\d+)/messages', function ($id) {
  global $me; $r = one('SELECT * FROM requests WHERE id=?', [$id]);
  if (!$r || !($me['role'] === 'admin' || $r['farmer_id'] == $me['id'] || $r['vet_id'] == $me['id'])) fail('Not found', 404);
  out(all(MSG_SELECT . ' WHERE m.request_id=? ORDER BY m.id', [$id]));
});
route('POST', '/requests/(\d+)/messages', function ($id) use ($body) {
  global $me; $r = one('SELECT * FROM requests WHERE id=?', [$id]);
  if (!$r || !($r['farmer_id'] == $me['id'] || $r['vet_id'] == $me['id'])) fail('Not found', 404);
  $text = trim($body['body'] ?? ''); if ($text === '') fail('Empty message');
  $kind = ($me['role'] === 'vet' && ($body['kind'] ?? '') === 'advice') ? 'advice' : 'message';
  q('INSERT INTO messages (request_id,sender_id,kind,body) VALUES (?,?,?,?)', [$id, $me['id'], $kind, $text]);
  $m = one(MSG_SELECT . ' WHERE m.id=?', [db()->lastInsertId()]);
  emit([$r['farmer_id'], $r['vet_id']], 'message:new', $m);
  out($m);
});

/* ---- 4. Service records (admin full CRUD; vets add for own cases; farmers read own) ---- */
route('GET', '/records', function () {
  global $me; $q = '%' . ($_GET['q'] ?? '') . '%';
  $scope = $me['role'] === 'admin' ? '1=1' : ($me['role'] === 'vet' ? 'rc.vet_id=?' : 'rc.farmer_id=?');
  $args = $me['role'] === 'admin' ? [] : [$me['id']];
  out(all(REC_SELECT . " WHERE $scope AND (f.name LIKE ? OR rc.diagnosis LIKE ? OR rc.treatment LIKE ? OR rc.animal_info LIKE ?) ORDER BY rc.id DESC", [...$args, $q, $q, $q, $q]));
});
route('GET', '/records/(\d+)', function ($id) { $r = one(REC_SELECT . ' WHERE rc.id=?', [$id]); $r ? out($r) : fail('Not found', 404); }, ['admin']);

function save_record(array $b, ?int $id): void {
  global $me;
  $farmer = $b['farmer_id'] ?? null;
  if (!$farmer && !empty($b['request_id'])) $farmer = one('SELECT farmer_id FROM requests WHERE id=?', [$b['request_id']])['farmer_id'] ?? null;
  if (!$farmer) fail('Choose a farmer');
  if ($me['role'] === 'vet' && (empty($b['request_id']) || !one('SELECT 1 FROM requests WHERE id=? AND vet_id=?', [$b['request_id'], $me['id']]))) fail('You can only log records for your own cases', 403);
  $vet = $me['role'] === 'vet' ? $me['id'] : (($b['vet_id'] ?? '') ?: null);
  $n = fn($k) => ($b[$k] ?? '') !== '' ? $b[$k] : null;
  $vals = [$n('request_id'), $farmer, $vet, $n('animal_id'), $n('animal_info'), $n('diagnosis'), $n('treatment'), $n('follow_up_date'), $n('notes')];
  if ($id) q('UPDATE records SET request_id=?,farmer_id=?,vet_id=?,animal_id=?,animal_info=?,diagnosis=?,treatment=?,follow_up_date=?,notes=?,updated_at=NOW() WHERE id=?', [...$vals, $id]);
  else { q('INSERT INTO records (request_id,farmer_id,vet_id,animal_id,animal_info,diagnosis,treatment,follow_up_date,notes) VALUES (?,?,?,?,?,?,?,?,?)', $vals); $id = (int)db()->lastInsertId(); }
  $row = one(REC_SELECT . ' WHERE rc.id=?', [$id]);
  emit([...ids_of('admin'), $row['farmer_id']], 'record:changed', $row);
  out($row);
}
route('POST', '/records', fn() => save_record($GLOBALS['body'], null), ['admin', 'vet']);
route('PUT', '/records/(\d+)', fn($id) => save_record($GLOBALS['body'], (int)$id), ['admin']);
route('DELETE', '/records/(\d+)', function ($id) { q('DELETE FROM records WHERE id=?', [$id]); emit(ids_of('admin'), 'record:changed', new stdClass); out(['ok' => true]); }, ['admin']);

/* ---- Admin overview ---- */
route('GET', '/admin/overview', function () {
  $n = fn($sql) => (int)one($sql)['n'];
  out([
    'farmers' => $n("SELECT COUNT(*) n FROM users WHERE role='farmer'"),
    'vets' => $n("SELECT COUNT(*) n FROM users WHERE role='vet'"),
    'open_requests' => $n("SELECT COUNT(*) n FROM requests WHERE status IN ('pending','accepted','in_progress')"),
    'records' => $n('SELECT COUNT(*) n FROM records'),
    'users' => array_map('pub', all("SELECT * FROM users WHERE role!='admin' ORDER BY id DESC LIMIT 100")),
    'followups' => all(REC_SELECT . ' WHERE rc.follow_up_date >= CURDATE() ORDER BY rc.follow_up_date LIMIT 10'),
  ]);
}, ['admin']);

fail('Not found', 404);

} catch (Throwable $e) {
  error_log($e);
  fail('Server error: ' . $e->getMessage(), 500);
}
