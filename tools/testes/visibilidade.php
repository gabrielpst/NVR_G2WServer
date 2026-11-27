<?php
/*
 * G2W Monitor — o que cada usuário vê, pelo caminho real do painel
 * (user::camPermission, o mesmo de /devices.php, /aovivo, /media/...).
 * Teste dos grupos de câmeras: rodar antes e depois da migração e comparar.
 *
 *   php visibilidade.php > antes.txt
 *   php visibilidade.php > depois.txt && diff antes.txt depois.txt
 *
 * Uma linha por usuário: "id admin|comum: ids visíveis". Sem nomes nem senhas.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('INDVR', true);
define('site_path', getenv('G2W_WWW') ?: '/usr/share/bluecherry/www/');
define('lib_path', site_path.'lib/');
define('ctl_path', site_path.'ajax/');
define('template_path', site_path.'template/');
require_once site_path.'vendor/autoload.php';
require_once lib_path.'lib.php';
require_once lib_path.'Gestao.php';

$cams = array_map('intval', array_column(G2WGestao::q("SELECT id FROM Devices ORDER BY id"), 'id'));
foreach (G2WGestao::q("SELECT id FROM Users ORDER BY id") as $r) {
    $u = new user('id', intval($r['id']));
    $v = array();
    foreach ($cams as $c) if ($u->camPermission($c)) $v[] = $c;
    echo intval($r['id']).' '.(intval($u->info['access_setup']) ? 'admin' : 'comum').': '.implode(',', $v)."\n";
}
