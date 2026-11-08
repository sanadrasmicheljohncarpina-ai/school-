<?php
// Regenerate the ERD SVGs from the current database metadata.
$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';
$name = getenv('DB_NAME') ?: 'evaluation';
$port = (int)(getenv('DB_PORT') ?: 3306);
$db = new mysqli($host, $user, $pass, $name, $port);
if ($db->connect_errno) { fwrite(STDERR, "Database connection failed: {$db->connect_error}\n"); exit(1); }
$db->set_charset('utf8mb4');

$groups = [
    'people-access' => ['People and access', [
        'users','admin_users','admin_permissions','teaching_assignments','user_year_levels','user_preferences',
        'login_confirmations','password_resets','student_security_answers','auth_attempts','login_attempts',
        'role_change_log','user_management_log','notifications'
    ]],
    'evaluations' => ['Evaluation records', [
        'evaluation_periods','evaluation_tracker','evaluation_answers','questionnaire_answers','evaluation_submissions',
        'evaluation_results','peer_evaluation_submissions','peer_evaluation_results','evaluation_reminders',
        'school_head_evaluation_assignments','rating_certifications'
    ]],
    'questionnaires' => ['Questionnaires and question banks', [
        'evaluation_questions','question_categories','evaluation_question_categories','questionnaire_forms',
        'questionnaire_questions','questionnaire_migrations','user_questions','user_question_categories'
    ]],
    'operations' => ['Administration and operations', [
        'activity_log','analytics_archive','analytics_reports','business_hours','documents','services',
        'system_archives','system_documents','system_settings'
    ]],
];

$tableRows = [];
$res = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'");
while ($r = $res->fetch_row()) $tableRows[] = $r[0];
$listed = array_merge(...array_values(array_map(fn($g) => $g[1], $groups)));
$missing = array_diff($tableRows, $listed);
$unknown = array_diff($listed, $tableRows);
if ($missing || $unknown) {
    fwrite(STDERR, 'Group assignment mismatch. Missing: ' . implode(', ', $missing) . '; absent: ' . implode(', ', $unknown) . "\n");
    exit(1);
}

$columns = [];
$res = $db->query("SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,COLUMN_KEY,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION");
while ($r = $res->fetch_assoc()) $columns[$r['TABLE_NAME']][] = $r;
$relations = [];
$res = $db->query("SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,ORDINAL_POSITION");
while ($r = $res->fetch_assoc()) $relations[] = $r;

function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }
function text($x, $y, $value, $size=15, $color='#23364d', $weight='normal') {
    return '<text x="'.$x.'" y="'.$y.'" font-family="Arial, sans-serif" font-size="'.$size.'" fill="'.$color.'" font-weight="'.$weight.'">'.esc($value).'</text>';
}
function makeSvg($slug, $title, $tables, $columns, $relations) {
    $colCount = 3; $boxW = 590; $gapX = 34; $gapY = 34; $pad = 42; $headerH = 42; $rowH = 22;
    $heights = [];
    foreach ($tables as $t) $heights[$t] = $headerH + max(1, count($columns[$t] ?? [])) * $rowH + 14;
    $rows = array_chunk($tables, $colCount);
    $rowHeights = [];
    foreach ($rows as $row) $rowHeights[] = max(array_map(fn($t) => $heights[$t], $row));
    $ys = []; $cursor = 112;
    foreach ($rowHeights as $h) { $ys[] = $cursor; $cursor += $h + $gapY; }
    $width = $pad*2 + $colCount*$boxW + ($colCount-1)*$gapX;
    $height = max(330, $cursor + 35);
    $pos = [];
    foreach ($tables as $i => $t) {
        $r = intdiv($i, $colCount); $c = $i % $colCount;
        $pos[$t] = ['x'=>$pad+$c*($boxW+$gapX), 'y'=>$ys[$r], 'h'=>$heights[$t]];
    }
    $out = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$width.'" height="'.$height.'" viewBox="0 0 '.$width.' '.$height.'">';
    $out .= '<rect width="100%" height="100%" fill="#f5f8fc"/>';
    $out .= text($pad, 48, $title, 28, '#173b63', 'bold');
    $out .= text($pad, 75, count($tables).' tables · solid connectors are declared foreign keys', 15, '#62758b');
    // Draw relationships before the entities so table boxes remain legible.
    foreach ($relations as $rel) {
        $a=$rel['TABLE_NAME']; $b=$rel['REFERENCED_TABLE_NAME'];
        if (!isset($pos[$a],$pos[$b])) continue;
        $p=$pos[$a]; $q=$pos[$b];
        $x1=$p['x']+$boxW/2; $y1=$p['y']+$headerH/2;
        $x2=$q['x']+$boxW/2; $y2=$q['y']+$headerH/2;
        $out .= '<path d="M '.$x1.' '.$y1.' C '.$x1.' '.(($y1+$y2)/2).', '.$x2.' '.(($y1+$y2)/2).', '.$x2.' '.$y2.'" fill="none" stroke="#5791be" stroke-width="2" opacity=".58" marker-end="url(#arrow)"/>';
    }
    $out .= '<defs><marker id="arrow" markerWidth="9" markerHeight="9" refX="7" refY="3" orient="auto"><path d="M0,0 L0,6 L8,3 z" fill="#5791be"/></marker></defs>';
    foreach ($tables as $t) {
        $p=$pos[$t]; $x=$p['x']; $y=$p['y'];
        $out .= '<rect x="'.$x.'" y="'.$y.'" width="'.$boxW.'" height="'.$p['h'].'" rx="7" fill="#fff" stroke="#5085b1" stroke-width="1.6"/>';
        $out .= '<path d="M '.($x+7).' '.$y.' H '.($x+$boxW-7).' Q '.($x+$boxW).' '.$y.' '.($x+$boxW).' '.($y+7).' V '.($y+$headerH).' H '.$x.' V '.($y+7).' Q '.$x.' '.$y.' '.($x+7).' '.$y.'Z" fill="#e7f1fb"/>';
        $out .= '<line x1="'.$x.'" y1="'.($y+$headerH).'" x2="'.($x+$boxW).'" y2="'.($y+$headerH).'" stroke="#b7cde0"/>';
        $out .= text($x+14,$y+27,$t,17,'#173b63','bold');
        $yy=$y+$headerH+20;
        foreach ($columns[$t] ?? [] as $field) {
            $key = $field['COLUMN_KEY']==='PRI' ? '🔑 ' : ($field['COLUMN_KEY']==='MUL' ? '↗ ' : '');
            $nullable = $field['IS_NULLABLE']==='YES' ? ' NULL' : '';
            $line = $key.$field['COLUMN_NAME'].' : '.$field['COLUMN_TYPE'].$nullable;
            $out .= text($x+14,$yy,$line,13,$field['COLUMN_KEY']==='PRI'?'#a65c16':'#34485f');
            $yy += $rowH;
        }
    }
    return $out.'</svg>';
}

$outDir = __DIR__;
foreach ($groups as $slug => [$title, $tables]) {
    file_put_contents($outDir.'/'.$slug.'.svg', makeSvg($slug, $title, $tables, $columns, $relations));
}
$db->close();
echo 'Generated four ERDs for ' . count($tableRows) . " database tables.\n";
