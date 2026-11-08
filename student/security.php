<?php
// student/security.php
// Shared helpers for the student portal: sessions, CSRF, login/reset throttling
// and the security-question ("forgot password") system.
//
// Include with:   require_once 'security.php'; secure_session_start(); require_once 'db.php';

// Server-side secret used to make the "fake questions" shown for unknown usernames
// unpredictable. Change this to your own random value (any long random string).
const SECURITY_PEPPER = '3ce8f2da9d69d55f4d75cc168a37cb37d530875273b6efe3c1e1ec32d68fc349';

// A valid bcrypt hash of a random string. Verified against when a username does not
// exist so that every login / recovery attempt takes about the same time.
const DUMMY_HASH = '$2y$10$a8t/Z7N39E674pvEXKUNh.6rPzXHKmBW.yrnEe8LDo8/6oBE83fWW';

// Throttle policy
const AUTH_WINDOW_SEC       = 900;  // look back 15 minutes
const AUTH_MAX_PER_ACCOUNT_IP = 5;  // failures for one username from one IP
const AUTH_MAX_PER_ACCOUNT  = 20;   // failures for one username from anywhere
const AUTH_MAX_PER_IP       = 30;   // failures from one IP (kept high: a school lab may share one IP)

// ── Sessions ─────────────────────────────────────────────────────────────
function secure_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ── CSRF ─────────────────────────────────────────────────────────────────
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}
function csrf_valid($token): bool {
    return is_string($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// ── Tables (created once per session if they do not exist yet) ──────────
function security_ensure_tables(mysqli $db): void {
    if (!empty($_SESSION['sec_tables_ok'])) return;
    $db->query("CREATE TABLE IF NOT EXISTS student_security_answers (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        slot TINYINT UNSIGNED NOT NULL,
        question_key VARCHAR(40) NOT NULL,
        answer_hash VARCHAR(255) NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_slot (user_id, slot),
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->query("CREATE TABLE IF NOT EXISTS auth_attempts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        kind VARCHAR(16) NOT NULL,
        identifier VARCHAR(100) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        success TINYINT(1) NOT NULL DEFAULT 0,
        attempted_at DATETIME NOT NULL,
        KEY idx_ident (kind, identifier, attempted_at),
        KEY idx_ip (kind, ip, attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $_SESSION['sec_tables_ok'] = 1;
}

// ── Throttling ───────────────────────────────────────────────────────────
function auth_ip(): string {
    // REMOTE_ADDR only. X-Forwarded-For can be forged by the client.
    return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
}

function auth_count_failures(mysqli $db, string $kind, ?string $identifier, ?string $ip): int {
    $since  = date('Y-m-d H:i:s', time() - AUTH_WINDOW_SEC);
    $sql    = "SELECT COUNT(*) FROM auth_attempts WHERE kind = ? AND success = 0 AND attempted_at >= ?";
    $types  = 'ss';
    $params = [$kind, $since];
    if ($identifier !== null) { $sql .= " AND identifier = ?"; $types .= 's'; $params[] = $identifier; }
    if ($ip !== null)         { $sql .= " AND ip = ?";         $types .= 's'; $params[] = $ip; }
    $st = $db->prepare($sql);
    $st->bind_param($types, ...$params);
    $st->execute();
    $st->bind_result($n);
    $st->fetch();
    $st->close();
    return (int)$n;
}

function auth_is_locked(mysqli $db, string $kind, string $identifier): bool {
    $ip = auth_ip();
    return auth_count_failures($db, $kind, $identifier, $ip) >= AUTH_MAX_PER_ACCOUNT_IP
        || auth_count_failures($db, $kind, $identifier, null) >= AUTH_MAX_PER_ACCOUNT
        || auth_count_failures($db, $kind, null, $ip)         >= AUTH_MAX_PER_IP;
}

function auth_record(mysqli $db, string $kind, string $identifier, bool $success): void {
    $now = date('Y-m-d H:i:s');
    $ip  = auth_ip();
    $ident = mb_substr($identifier, 0, 100);
    $st = $db->prepare("INSERT INTO auth_attempts (kind, identifier, ip, success, attempted_at) VALUES (?,?,?,?,?)");
    $s  = $success ? 1 : 0;
    $st->bind_param('sssis', $kind, $ident, $ip, $s, $now);
    $st->execute();
    $st->close();
    if (random_int(1, 100) === 1) {   // housekeeping: drop rows older than a day
        $old = date('Y-m-d H:i:s', time() - 86400);
        $db->query("DELETE FROM auth_attempts WHERE attempted_at < '" . $db->real_escape_string($old) . "'");
    }
}

// After a success, forget this person's earlier failures so they are not locked by old typos.
function auth_clear(mysqli $db, string $kind, string $identifier): void {
    $ip = auth_ip();
    $ident = mb_substr($identifier, 0, 100);
    $st = $db->prepare("DELETE FROM auth_attempts WHERE kind = ? AND identifier = ? AND ip = ? AND success = 0");
    $st->bind_param('sss', $kind, $ident, $ip);
    $st->execute();
    $st->close();
}

const AUTH_LOCK_MESSAGE = 'Too many failed attempts. Please wait 15 minutes and try again, or contact your administrator.';

// ── Security questions ──────────────────────────────────────────────────
// Keys are stored in the database (not the wording), so you can reword or add questions freely.
function sq_questions(): array {
    return [
        'nickname'     => 'What is your nickname?',
        'called_by'    => 'What do people usually call you?',
        'fav_food'     => 'What is your favorite food?',
        'fav_color'    => 'What is your favorite color?',
        'fav_subject'  => 'What is your favorite subject in school?',
        'fav_fruit'    => 'What is your favorite fruit?',
        'fav_drink'    => 'What is your favorite drink?',
        'fav_sport'    => 'What is your favorite sport or game?',
        'fav_singer'   => 'Who is your favorite singer or band?',
        'pet_name'     => 'What is the name of your pet (or the pet you wish to have)?',
        'bestie_name'  => 'What is the first name of your best friend?',
        'fav_place'    => 'What is your favorite place to go to?',
        'hometown'     => 'What barangay or town do you live in?',
    ];
}

// Questions that are no longer offered to new sign-ups (too easy for a classmate to know or guess)
// but that some students already chose. Their answers keep working for password recovery;
// students are nudged to pick a current question. Never delete a key from here while any
// student may still have it stored.
function sq_retired_questions(): array {
    return [
        // Previously active "childhood memory" questions (replaced by the basic list above).
        'first_pet'          => 'What was the name of your first pet (or the pet you always wanted)?',
        'childhood_nick'     => 'What was your nickname when you were a small child?',
        'grandparent_middle' => 'What is the middle name of your oldest grandparent (or the oldest one you remember)?',
        'childhood_neighbor' => 'What was the first name of the neighbor you remember best from your childhood?',
        'childhood_toy'      => 'What was the name of your favorite toy or stuffed animal as a child (or the one you always wanted)?',
        'grow_up_street'     => 'What was the name of the street or sitio where you spent most of your childhood?',
        'first_phone'        => 'What was the brand and model of the first mobile phone you used?',
        'first_movie'        => 'What is the title of the first movie you remember watching in a cinema?',
        'parents_met'        => 'In what town or city did your parents meet?',
        'oldest_cousin'      => 'What is the first name of your oldest cousin?',
        'first_game'         => 'What is the name of the first video or mobile game you remember playing?',
        'first_trip'         => 'Where did you go on the first trip you remember taking?',
        'best_friend' => 'What was the first name of your best friend in elementary school?',
        'fav_teacher' => 'What was the last name of your favorite elementary teacher?',
        'first_dish'  => 'What is the first dish you learned to cook?',
    ];
}

// Active + retired: used to DISPLAY a student's stored questions (never to offer new ones).
function sq_all_questions(): array {
    return sq_questions() + sq_retired_questions();
}

// True when a student has fewer than 3 questions or still uses a retired one.
function sq_needs_update(array $rows): bool {
    if (count($rows) < 3) return true;
    $active = sq_questions();
    foreach ($rows as $r) if (!isset($active[$r['question_key']])) return true;
    return false;
}

// Lowercase, strip accents, punctuation and spaces: "Sto. Niño" and "sto nino" become the same answer.
function sq_normalize(string $a): string {
    $a = mb_strtolower(trim($a), 'UTF-8');
    if (class_exists('Normalizer')) {
        $d = Normalizer::normalize($a, Normalizer::FORM_D);
        if ($d !== false) $a = preg_replace('/\p{Mn}+/u', '', $d);
    }
    return preg_replace('/[^\p{L}\p{N}]+/u', '', $a);
}

function sq_is_weak(string $normalized, string $username): bool {
    static $bad = ['none','nothing','idk','unknown','yes','no','na','secret','password','test',
                   '123','1234','12345','abc','abcd','qwerty','asdf','dog','cat','mother','father'];
    return in_array($normalized, $bad, true) || $normalized === sq_normalize($username);
}

// Validate the 3 submitted question/answer pairs. Returns [errorOrNull, rowsToStore].
function sq_validate($qs, $as, string $username): array {
    $qs = is_array($qs) ? $qs : [];
    $as = is_array($as) ? $as : [];
    $all = sq_questions();
    $rows = []; $seenQ = [];
    for ($slot = 1; $slot <= 3; $slot++) {
        $k   = (string)($qs[$slot] ?? '');
        $raw = (string)($as[$slot] ?? '');
        if (!isset($all[$k]))            return ["Please choose security question $slot.", []];
        if (isset($seenQ[$k]))           return ['Please choose three different security questions.', []];
        if (mb_strlen($raw) > 100)       return ["Answer $slot is too long (100 characters max).", []];
        $n = sq_normalize($raw);
        if (mb_strlen($n) < 3)           return ["Answer $slot is too short. Use at least 3 letters or numbers.", []];
        if (sq_is_weak($n, $username))   return ["Answer $slot is too easy to guess. Please be more specific.", []];
        $seenQ[$k] = 1;
        $rows[] = ['slot' => $slot, 'key' => $k, 'hash' => password_hash($n, PASSWORD_DEFAULT)];
    }
    return [null, $rows];
}

// Like sq_validate(), but lets a person change only some of their questions.
// A slot whose question is unchanged and whose answer is left blank keeps its stored
// (hashed) answer. A changed question, or a newly typed answer, is validated in full.
// $existing = sq_load() rows keyed by slot. Returns [errorOrNull, rowsToStore].
function sq_validate_update($qs, $as, string $username, array $existing): array {
    $qs = is_array($qs) ? $qs : [];
    $as = is_array($as) ? $as : [];
    $all = sq_questions();
    $rows = []; $seenQ = []; $changed = false;
    for ($slot = 1; $slot <= 3; $slot++) {
        $k   = (string)($qs[$slot] ?? '');
        $raw = (string)($as[$slot] ?? '');
        if (!isset($all[$k]))            return ["Please choose security question $slot.", []];
        if (isset($seenQ[$k]))           return ['Please choose three different security questions.', []];
        $seenQ[$k] = 1;
        $old = $existing[$slot] ?? null;
        if (trim($raw) === '' && $old && (string)($old['question_key'] ?? '') === $k && !empty($old['answer_hash'])) {
            $rows[] = ['slot' => $slot, 'key' => $k, 'hash' => $old['answer_hash']];   // unchanged: keep it
            continue;
        }
        if (mb_strlen($raw) > 100)       return ["Answer $slot is too long (100 characters max).", []];
        $n = sq_normalize($raw);
        if (mb_strlen($n) < 3)           return ["Answer $slot is too short. Use at least 3 letters or numbers.", []];
        if (sq_is_weak($n, $username))   return ["Answer $slot is too easy to guess. Please be more specific.", []];
        $rows[] = ['slot' => $slot, 'key' => $k, 'hash' => password_hash($n, PASSWORD_DEFAULT)];
        $changed = true;
    }
    if (!$changed) return ['Change at least one question or answer before saving.', []];
    return [null, $rows];
}

// Replace a student's stored questions. Call inside a transaction.
function sq_save(mysqli $db, int $userId, array $rows): void {
    $del = $db->prepare("DELETE FROM student_security_answers WHERE user_id = ?");
    $del->bind_param('i', $userId);
    $del->execute();
    $del->close();
    $ins = $db->prepare("INSERT INTO student_security_answers (user_id, slot, question_key, answer_hash) VALUES (?,?,?,?)");
    foreach ($rows as $r) {
        $ins->bind_param('iiss', $userId, $r['slot'], $r['key'], $r['hash']);
        $ins->execute();
    }
    $ins->close();
}

// Stored rows for a user, keyed by slot: [1 => ['question_key'=>..., 'answer_hash'=>...], ...]
function sq_load(mysqli $db, int $userId): array {
    $st = $db->prepare("SELECT slot, question_key, answer_hash FROM student_security_answers WHERE user_id = ? ORDER BY slot");
    $st->bind_param('i', $userId);
    $st->execute();
    $res = $st->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) $out[(int)$r['slot']] = $r;
    $st->close();
    return $out;
}

// Question keys shown for a username that does not exist (or has no questions set).
// Deterministic per username but unpredictable without SECURITY_PEPPER, so an attacker
// cannot tell real accounts from made-up ones.
function sq_fake_keys(string $username): array {
    // Drawn from active + retired so a made-up set can't be told apart from a real one.
    $keys = array_keys(sq_all_questions());
    $seed = mb_strtolower($username);
    usort($keys, fn($a, $b) =>
        strcmp(hash_hmac('sha256', "$seed|$a", SECURITY_PEPPER), hash_hmac('sha256', "$seed|$b", SECURITY_PEPPER)));
    return array_slice($keys, 0, 3);
}