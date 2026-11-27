<?php
/*
 * G2W Monitor — importador de usuários em lote (linha de comando).
 *
 * Cria usuários comuns a partir de uma lista JSON, com as MESMAS colunas e a
 * mesma validação da tela "Usuários" do painel (www/ajax/gestao.php).
 *
 * Uso, dentro do container do servidor (não fica ao alcance da web):
 *
 *   php importar-usuarios.php [--simular] --senha-arquivo=/caminho <arquivo.json | ->
 *
 *   --simular         só valida e conta; não grava nada
 *   --senha-arquivo   arquivo com a senha inicial (uma linha). A senha nunca
 *                     vai na linha de comando nem aparece na saída.
 *   -                 lê o JSON da entrada padrão
 *   --grupo=NOME      com os grupos de câmeras ligados (docs/grupos-de-cameras.md):
 *                     os criados entram nesse grupo. Sem ele, não veem câmera
 *                     nenhuma até alguém dar grupo ou avulsas em Usuários.
 *
 * Cada item: login, nome, email (opcional).
 *
 * Regras:
 *  - todo usuário entra comum (sem administração), com acesso por app e site,
 *    vendo todas as câmeras (com os grupos ligados: as do --grupo), sem baixar gravações, e marcado para trocar a
 *    senha no primeiro acesso;
 *  - login que já existe (sem diferenciar maiúsculas) é pulado, nunca alterado;
 *  - tudo numa transação: se um falhar, nada é gravado.
 *
 * A saída traz só contagens e os logins pulados/recusados.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('INDVR', true);
define('site_path', '/usr/share/bluecherry/www/');
define('lib_path', site_path.'lib/');
define('ctl_path', site_path.'ajax/');
define('template_path', site_path.'template/');
require_once site_path.'vendor/autoload.php';
require_once lib_path.'lib.php';
require_once lib_path.'Gestao.php';

function fim($codigo, $msg) { fwrite(STDERR, $msg."\n"); exit($codigo); }

$simular = false; $senhaArq = null; $origem = null; $grupoNome = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--simular') $simular = true;
    elseif (strpos($arg, '--grupo=') === 0) $grupoNome = substr($arg, 8);
    elseif (strpos($arg, '--senha-arquivo=') === 0) $senhaArq = substr($arg, 16);
    elseif ($arg === '-' || ($arg !== '' && $arg[0] !== '-')) $origem = $arg;
    else fim(2, 'Opção desconhecida: '.$arg);
}
if ($origem === null || $senhaArq === null) fim(2, 'Uso: php importar-usuarios.php [--simular] --senha-arquivo=ARQ <arquivo.json | ->');

$senha = @file_get_contents($senhaArq);
if ($senha === false) fim(2, 'Não consegui ler o arquivo da senha.');
$senha = rtrim($senha, "\r\n");
if (($v = G2WGestao::validarSenha($senha)) !== true) fim(2, 'Senha inicial recusada: '.$v);

$bruto = ($origem === '-') ? stream_get_contents(STDIN) : @file_get_contents($origem);
$lista = json_decode((string)$bruto, true);
if (!is_array($lista)) fim(2, 'A lista não é um JSON válido.');

$grupos = G2WGestao::gruposLigados();
$grupoId = 0;
if ($grupoNome !== null) {
    if (!$grupos) fim(2, '--grupo só vale com os grupos de câmeras ligados nesta instalação.');
    $g = G2WGestao::q("SELECT id FROM G2WGrupos WHERE nome=?", 's', array(trim($grupoNome)));
    if (!$g) fim(2, 'Grupo não encontrado: '.$grupoNome);
    $grupoId = intval($g[0]['id']);
}
if ($grupos && !$grupoId) echo "Aviso: grupos de câmeras ligados e sem --grupo: os usuários criados não verão câmera nenhuma até receberem grupo ou câmeras em Usuários.\n";

$existentes = array();
foreach (G2WGestao::q("SELECT username FROM Users") as $u) $existentes[strtolower($u['username'])] = true;

$novos = array(); $pulados = array(); $recusados = array();
foreach ($lista as $i => $it) {
    $login = isset($it['login']) ? trim((string)$it['login']) : '';
    if (G2WGestao::validarLogin($login) !== true) { $recusados[] = 'item '.($i + 1).' (login inválido)'; continue; }
    $chave = strtolower($login);
    if (isset($existentes[$chave])) { $pulados[] = $login; continue; }
    $nome = isset($it['nome']) ? trim(preg_replace('/\s+/u', ' ', (string)$it['nome'])) : '';
    if (mb_strlen($nome) > 60) $nome = mb_substr($nome, 0, 60);
    $email = isset($it['email']) ? trim((string)$it['email']) : '';
    if ($email !== '' && (strlen($email) > 60 || !filter_var($email, FILTER_VALIDATE_EMAIL))) $email = '';
    $existentes[$chave] = true;
    $novos[] = array($login, $nome, $email);
}

echo 'Na lista: '.count($lista).' | a criar: '.count($novos).' | já existiam: '.count($pulados).' | recusados: '.count($recusados)."\n";
if ($pulados) echo 'Já existiam: '.implode(', ', $pulados)."\n";
if ($recusados) echo 'Recusados: '.implode('; ', $recusados)."\n";
if ($simular) { echo "Simulação: nada foi gravado.\n"; exit(0); }
if (!$novos) exit(0);

data::query("START TRANSACTION", true);
try {
    foreach ($novos as $n) {
        $salt = data::getRandomString(4);
        G2WGestao::q("INSERT INTO Users (username, password, salt, name, email, phone, notes, access_setup, access_remote,
            access_web, access_backup, access_relay, access_device_list, access_ptz_list, change_password)
            VALUES (?, ?, ?, ?, ?, '', ?, 0, 1, 1, 0, 0, ?, '', 1)", 'sssssss',
            array($n[0], G2WGestao::hashSenha($senha, $salt), $salt, $n[1], $n[2], 'Importado em '.date('Y-m-d'), $grupos ? G2WGestao::bloqueioTotal() : ''));
        if ($grupoId) {
            $u = G2WGestao::q("SELECT id FROM Users WHERE username=?", 's', array($n[0]));
            G2WGestao::q("INSERT INTO G2WUsuarioGrupos (user_id, grupo_id) VALUES (?, ?)", 'ii', array(intval($u[0]['id']), $grupoId));
        }
    }
    if ($grupos) G2WGestao::recalcularBloqueios(null, false);
    data::query("COMMIT", true);
} catch (Throwable $ex) {
    data::query("ROLLBACK", true);
    fim(1, 'Falha ao gravar; nada foi criado.');
}
echo 'Criados: '.count($novos)."\n";
