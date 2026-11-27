<?php
/*
 * G2W Monitor — liga (e desliga) os grupos de câmeras numa instalação.
 * Desenho: docs/grupos-de-cameras.md.
 *
 * Uso, dentro do container do servidor (não fica ao alcance da web):
 *
 *   php grupos-cameras.php --simular [--modo=todas|avulsas]
 *   php grupos-cameras.php --migrar  [--modo=todas|avulsas]
 *   php grupos-cameras.php --conferir
 *   php grupos-cameras.php --desfazer [--restaurar-copia] [--apagar-tabelas] [--coluna-original]
 *
 * --modo=todas (padrão): cria o grupo "Todas as câmeras" com as câmeras de
 *   hoje; quem vê todas recebe o grupo, quem tem câmera bloqueada recebe as
 *   que vê como avulsas.
 * --modo=avulsas: sem grupo; cada usuário recebe como avulsas as que vê.
 *
 * --migrar confere, usuário por usuário, que o conjunto de câmeras visível
 * depois é IGUAL ao de antes; se um diferir, desfaz tudo e não liga nada.
 * --conferir compara o que cada usuário vê hoje com a cópia do dia da
 * migração (câmeras criadas depois aparecem como diferença esperada).
 * --desfazer tira a marca: cada um continua vendo o que via no modo de
 * grupos, e câmera nova volta à regra antiga (aparece para todos).
 *   --restaurar-copia  devolve as listas guardadas no dia da migração
 *   --apagar-tabelas   apaga as tabelas de grupos (sem volta)
 *   --coluna-original  devolve a coluna a varchar(255), se todas couberem
 *
 * A saída traz só contagens e ids de usuário. Nada de senha nem endereço.
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

function fim($codigo, $msg) { fwrite($codigo ? STDERR : STDOUT, $msg."\n"); exit($codigo); }
function q($sql, $t = '', $p = array()) { return G2WGestao::q($sql, $t, $p); }
function ids($csv) { return G2WGestao::listaIds($csv); }

$acao = null; $modo = 'todas'; $restaurar = false; $apagar = false; $colunaOriginal = false;
foreach (array_slice($argv, 1) as $arg) {
    if (in_array($arg, array('--simular', '--migrar', '--conferir', '--desfazer'), true)) {
        if ($acao !== null) fim(2, 'Use uma ação de cada vez.');
        $acao = substr($arg, 2);
    } elseif (strpos($arg, '--modo=') === 0) $modo = substr($arg, 7);
    elseif ($arg === '--restaurar-copia') $restaurar = true;
    elseif ($arg === '--apagar-tabelas') $apagar = true;
    elseif ($arg === '--coluna-original') $colunaOriginal = true;
    else fim(2, 'Opção desconhecida: '.$arg);
}
if ($acao === null) fim(2, 'Uso: php grupos-cameras.php --simular|--migrar|--conferir|--desfazer (ver o cabeçalho do arquivo)');
if (!in_array($modo, array('todas', 'avulsas'), true)) fim(2, 'Modo desconhecido: use --modo=todas ou --modo=avulsas.');

$ligado = G2WGestao::gruposLigados();
$GRUPO_TODAS = 'Todas as câmeras';

/* O que cada usuário vê hoje, como o painel decide (user::camPermission):
 * câmera de Devices fora da lista de bloqueio; câmera removida pelo painel
 * nunca para usuário comum. Administrador: idem, sem a regra da removida. */
function visiveis() {
    $todas = array_map('intval', array_column(q("SELECT id FROM Devices ORDER BY id"), 'id'));
    $removidas = G2WGestao::idsRemovidas();
    $res = array();
    foreach (q("SELECT id, access_setup, access_device_list FROM Users ORDER BY id") as $u) {
        $admin = (bool)intval($u['access_setup']);
        $bloq = array_flip(ids($u['access_device_list']));
        $v = array();
        foreach ($todas as $d) {
            if (isset($bloq[$d])) continue;
            if (!$admin && in_array($d, $removidas, true)) continue;
            $v[] = $d;
        }
        $res[intval($u['id'])] = array('admin' => $admin, 'ver' => $v);
    }
    return array($todas, $removidas, $res);
}

function tipoColuna() {
    $r = q("SELECT DATA_TYPE AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Users' AND COLUMN_NAME='access_device_list'");
    return $r ? strtolower($r[0]['t']) : '?';
}

/* ---------------------------------------------------------------- simular */
if ($acao === 'simular' || $acao === 'migrar') {
    if ($ligado) fim(1, 'Os grupos já estão ligados nesta instalação. Nada a fazer.');
    list($todas, $removidas, $vis) = visiveis();
    $vivas = array_values(array_diff($todas, $removidas));
    $comuns = 0; $veemTudo = 0; $parciais = 0; $nenhuma = 0; $admins = 0;
    foreach ($vis as $id => $u) {
        if ($u['admin']) { $admins++; continue; }
        $comuns++;
        if (!$u['ver']) $nenhuma++;
        elseif (count($u['ver']) === count($vivas)) $veemTudo++;
        else $parciais++;
    }
    echo "Câmeras: ".count($todas)." (".count($removidas)." removida(s) guardando gravação)\n";
    echo "Usuários comuns: $comuns — veem todas: $veemTudo, veem parte: $parciais, nenhuma: $nenhuma. Administradores: $admins (não mudam)\n";
    echo "Coluna access_device_list hoje: ".tipoColuna()."\n";
    echo $modo === 'todas'
        ? "Modo todas: grupo \"$GRUPO_TODAS\" com ".count($vivas)." câmera(s) para $veemTudo usuário(s); $parciais usuário(s) com câmeras avulsas.\n"
        : "Modo avulsas: $comuns usuário(s) com câmeras avulsas, sem grupo.\n";
    if ($acao === 'simular') fim(0, 'Simulação: nada foi gravado.');
}

/* ---------------------------------------------------------------- migrar */
if ($acao === 'migrar') {
    // 1. coluna sem limite (DDL fica fora da transação; repetir é inofensivo)
    if (tipoColuna() !== 'text') {
        q("ALTER TABLE Users MODIFY access_device_list TEXT");
        echo "Coluna access_device_list: agora TEXT.\n";
    }
    G2WGestao::criarTabelasGrupos();
    foreach (array('G2WGrupos', 'G2WGrupoCameras', 'G2WUsuarioGrupos', 'G2WUsuarioCameras') as $t) {
        if (q("SELECT 1 FROM $t LIMIT 1")) fim(1, "A tabela $t já tem dados (migração anterior?). Confira antes; nada foi alterado.");
    }

    data::query("START TRANSACTION", true);
    try {
        // trava as linhas: ninguém salva usuário no meio
        $antes = array();
        foreach (q("SELECT id, access_device_list FROM Users ORDER BY id FOR UPDATE") as $u) $antes[intval($u['id'])] = (string)$u['access_device_list'];
        list($todas, $removidas, $vis) = visiveis();
        $vivas = array_values(array_diff($todas, $removidas));

        q("DELETE FROM G2WGruposAntes");
        foreach ($antes as $id => $csv) q("INSERT INTO G2WGruposAntes (user_id, access_device_list, migrado_em) VALUES (?, ?, ?)", 'isi', array($id, $csv, time()));

        $grupoTodas = 0;
        if ($modo === 'todas') {
            q("INSERT INTO G2WGrupos (nome, criado_em) VALUES (?, ?)", 'si', array($GRUPO_TODAS, time()));
            $r = q("SELECT id FROM G2WGrupos WHERE nome=?", 's', array($GRUPO_TODAS));
            $grupoTodas = intval($r[0]['id']);
            foreach ($vivas as $d) q("INSERT INTO G2WGrupoCameras (grupo_id, device_id) VALUES (?, ?)", 'ii', array($grupoTodas, $d));
        }
        $noGrupo = 0; $comAvulsas = 0;
        foreach ($vis as $id => $u) {
            if ($u['admin']) continue;
            if ($grupoTodas && $u['ver'] && count($u['ver']) === count($vivas)) {
                q("INSERT INTO G2WUsuarioGrupos (user_id, grupo_id) VALUES (?, ?)", 'ii', array($id, $grupoTodas));
                $noGrupo++;
            } else {
                foreach ($u['ver'] as $d) q("INSERT INTO G2WUsuarioCameras (user_id, device_id) VALUES (?, ?)", 'ii', array($id, $d));
                if ($u['ver']) $comAvulsas++;
            }
        }

        // liga e recalcula dentro da mesma transação
        q("INSERT INTO GlobalSettings (parameter, value) VALUES (?, '1') ON DUPLICATE KEY UPDATE value='1'", 's', array(G2WGestao::GRUPOS_PARAM));
        $ref = new ReflectionProperty('G2WGestao', 'gruposLigados');
        $ref->setAccessible(true);
        $ref->setValue(null, true);
        $mudaram = G2WGestao::recalcularBloqueios(null, false);

        // confere: o que cada um vê agora é o que via antes
        list(, , $depois) = visiveis();
        $diferentes = array();
        foreach ($vis as $id => $u) {
            if (!isset($depois[$id]) || $depois[$id]['ver'] !== $u['ver']) $diferentes[] = $id;
        }
        if ($diferentes) throw new RuntimeException('o que '.count($diferentes).' usuário(s) vê(em) mudaria (ids '.implode(',', array_slice($diferentes, 0, 20)).')');
        data::query("COMMIT", true);
    } catch (Throwable $e) {
        data::query("ROLLBACK", true);
        fim(1, 'Migração desfeita, nada mudou: '.$e->getMessage());
    }
    echo "Grupos ligados. ".($grupoTodas ? "$noGrupo usuário(s) no grupo \"$GRUPO_TODAS\"; " : '')."$comAvulsas com câmeras avulsas; $mudaram lista(s) reescrita(s).\n";
    fim(0, 'Conferido: cada usuário vê exatamente as mesmas câmeras de antes.');
}

/* ---------------------------------------------------------------- conferir */
if ($acao === 'conferir') {
    if (!q("SHOW TABLES LIKE 'G2WGruposAntes'")) fim(1, 'Sem cópia de migração nesta instalação.');
    $copia = array();
    foreach (q("SELECT user_id, access_device_list FROM G2WGruposAntes") as $r) $copia[intval($r['user_id'])] = ids($r['access_device_list']);
    list($todas, $removidas, $vis) = visiveis();
    $iguais = 0; $novos = 0; $dif = array();
    foreach ($vis as $id => $u) {
        if (!isset($copia[$id])) { $novos++; continue; }
        $antes = array();
        foreach ($todas as $d) {
            if (in_array($d, $copia[$id], true)) continue;
            if (!$u['admin'] && in_array($d, $removidas, true)) continue;
            $antes[] = $d;
        }
        if ($antes === $u['ver']) $iguais++;
        else $dif[] = $id.' (+'.count(array_diff($u['ver'], $antes)).' / -'.count(array_diff($antes, $u['ver'])).')';
    }
    echo "Grupos ".($ligado ? 'LIGADOS' : 'desligados').". Iguais à cópia: $iguais; usuários criados depois: $novos; diferentes: ".count($dif)."\n";
    if ($dif) echo "Diferentes (id +câmeras a mais / -a menos; câmera criada depois da migração conta aqui): ".implode(', ', $dif)."\n";
    fim(0, '');
}

/* ---------------------------------------------------------------- desfazer */
if ($acao === 'desfazer') {
    $temCopia = (bool)q("SHOW TABLES LIKE 'G2WGruposAntes'");
    if ($restaurar && !$temCopia) fim(1, 'Sem cópia para restaurar; nada foi alterado.');
    data::query("START TRANSACTION", true);
    try {
        q("DELETE FROM GlobalSettings WHERE parameter IN (?, ?)", 'ss', array(G2WGestao::GRUPOS_PARAM, G2WGestao::GRUPOS_VISTO_PARAM));
        $n = 0;
        if ($restaurar) {
            foreach (q("SELECT user_id, access_device_list FROM G2WGruposAntes") as $r) {
                q("UPDATE Users SET access_device_list=? WHERE id=?", 'si', array((string)$r['access_device_list'], intval($r['user_id'])));
                $n++;
            }
        }
        data::query("COMMIT", true);
    } catch (Throwable $e) {
        data::query("ROLLBACK", true);
        fim(1, 'Nada foi alterado: '.$e->getMessage());
    }
    echo "Grupos desligados.".($restaurar ? " $n lista(s) devolvida(s) à cópia da migração." : ' Cada usuário continua vendo o que via; câmera nova volta a aparecer para todos.')."\n";
    if ($apagar) {
        foreach (array('G2WGrupos', 'G2WGrupoCameras', 'G2WUsuarioGrupos', 'G2WUsuarioCameras', 'G2WGruposAntes') as $t) q("DROP TABLE IF EXISTS $t");
        echo "Tabelas de grupos apagadas.\n";
    }
    if ($colunaOriginal) {
        $r = q("SELECT COALESCE(MAX(CHAR_LENGTH(access_device_list)), 0) AS m FROM Users");
        if (intval($r[0]['m']) > 255) echo "Coluna mantida em TEXT: há lista com ".intval($r[0]['m'])." caracteres (não cabe em 255).\n";
        else { q("ALTER TABLE Users MODIFY access_device_list varchar(255) DEFAULT ''"); echo "Coluna access_device_list devolvida a varchar(255).\n"; }
    }
    fim(0, 'Pronto.');
}
