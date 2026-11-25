<?php
/*
 * G2W Monitor — importador de câmeras em lote (linha de comando).
 *
 * Cria câmeras RTSP a partir de uma lista JSON, pela MESMA função da tela
 * "Adicionar câmera" (G2WGestao::criarCamera): mesma validação, mesmas colunas.
 *
 * Uso, dentro do container do servidor (não fica ao alcance da web):
 *
 *   php importar-cameras.php [--simular] [--dias=3] [--pausa=3] <arquivo.json | ->
 *
 *   --simular   só valida e conta; não grava nada
 *   --dias=N    dias de gravação de cada câmera (padrão 3)
 *   --pausa=S   segundos entre uma câmera e a seguinte (padrão 3), para o
 *               motor receber uma de cada vez
 *   -           lê o JSON da entrada padrão (o arquivo não precisa entrar no
 *               container): docker exec -i bc-server php ... - < lista.json
 *
 * Cada item da lista: nome, dispositivo, numero, is_rtmp, ativo, latitude,
 * longitude, stream_url (rtsp://usuario:senha@host:porta/caminho?query).
 *
 * O arquivo tem as senhas das câmeras. Este programa NUNCA escreve endereço,
 * usuário nem senha: a saída traz só o nome, o resultado e o motivo.
 *
 * Regras:
 *  - RTMP é pulado e listado no fim (cadastro manual);
 *  - rodar de novo não duplica: câmera com o mesmo host, porta e caminho é
 *    dada como "já existia";
 *  - nome repetido vira "<dispositivo> — <nome>" e, se ainda repetir, ganha
 *    um número; nenhuma câmera é descartada por causa do nome;
 *  - "ativo" falso entra desativada; sem sinal entra normalmente (não há
 *    teste de conexão no lote);
 *  - latitude e longitude diferentes de zero viram a localização no mapa;
 *  - grava o tempo todo, som desligado (o padrão do cadastro).
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// nada de aviso do PHP na tela nem em log: uma mensagem de erro poderia
// carregar um pedaço do endereço
ini_set('display_errors', '0');
ini_set('log_errors', '0');
error_reporting(0);

define('INDVR', true);
define('site_path', '/usr/share/bluecherry/www/');
define('lib_path', site_path.'lib/');
define('ctl_path', site_path.'ajax/');
define('template_path', site_path.'template/');
require_once site_path.'vendor/autoload.php';
require_once lib_path.'lib.php';

function fim($codigo, $msg) { fwrite(STDERR, $msg."\n"); exit($codigo); }

$simular = false; $dias = 3; $pausa = 3.0; $origem = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--simular') $simular = true;
    elseif (preg_match('/^--dias=(\d{1,4})$/', $arg, $m)) $dias = intval($m[1]);
    elseif (preg_match('/^--pausa=(\d{1,3}(?:\.\d)?)$/', $arg, $m)) $pausa = floatval($m[1]);
    elseif ($arg === '-' || ($arg !== '' && $arg[0] !== '-')) $origem = $arg;
    else fim(2, 'Opção desconhecida. Uso: php importar-cameras.php [--simular] [--dias=3] [--pausa=3] <arquivo.json | ->');
}
if ($origem === null) fim(2, 'Informe o arquivo JSON (ou - para ler da entrada padrão).');
if ($dias < 1 || $dias > 3650) fim(2, 'Dias de gravação: de 1 a 3650.');

$bruto = ($origem === '-') ? stream_get_contents(STDIN) : @file_get_contents($origem);
if ($bruto === false || $bruto === '') fim(2, 'Não foi possível ler a lista.');
$lista = json_decode($bruto, true);
unset($bruto);
if (!is_array($lista) || ($lista && array_keys($lista) !== range(0, count($lista) - 1))) fim(2, 'A lista não é um JSON válido no formato esperado (uma lista de câmeras).');

/* rtsp://[usuario[:senha]@]host[:porta][/caminho[?query]]
 * A senha pode ter "@" e ":": a credencial vai até o ÚLTIMO "@" antes da
 * primeira "/", e o usuário até o PRIMEIRO ":". Devolve os campos ou um texto
 * de motivo (sem nenhum pedaço do endereço). */
function separarEndereco($url)
{
    if (!is_string($url) || !preg_match('#^rtsp://(.+)$#is', trim($url), $m)) return 'endereço não é RTSP';
    $resto = $m[1];
    $barra = strpos($resto, '/');
    $autoridade = ($barra === false) ? $resto : substr($resto, 0, $barra);
    $caminho = ($barra === false) ? '/' : substr($resto, $barra);
    $usuario = ''; $senha = '';
    $arroba = strrpos($autoridade, '@');
    if ($arroba !== false) {
        $cred = substr($autoridade, 0, $arroba);
        $autoridade = substr($autoridade, $arroba + 1);
        $dois = strpos($cred, ':');
        if ($dois === false) { $usuario = $cred; } else { $usuario = substr($cred, 0, $dois); $senha = substr($cred, $dois + 1); }
    }
    if (!preg_match('/^([^:]+)(?::(\d{1,5}))?$/', $autoridade, $h)) return 'endereço sem host ou com porta inválida';
    return array('host' => $h[1], 'porta' => isset($h[2]) ? intval($h[2]) : 554, 'caminho' => $caminho, 'usuario' => $usuario, 'senha' => $senha);
}

function limparNome($n)
{
    $n = is_string($n) ? $n : '';
    $n = trim(preg_replace('/\s+/u', ' ', preg_replace('/[<>"\'\\\\\x00-\x1f\x7f]/', ' ', $n)));
    return $n;
}
function cortar($n, $sufixo = '') { return mb_substr($n, 0, 100 - mb_strlen($sufixo)).$sufixo; }

// 1) nomes: resolve as repetições dentro da própria lista
$itens = array();
$conta = array();
foreach ($lista as $i => $it) {
    $it = is_array($it) ? $it : array();
    $nome = limparNome(isset($it['nome']) ? $it['nome'] : '');
    if ($nome === '') $nome = 'Câmera '.($i + 1);
    $itens[$i] = array('bruto' => $it, 'nome' => $nome, 'original' => $nome);
    $chave = mb_strtolower($nome);
    $conta[$chave] = isset($conta[$chave]) ? $conta[$chave] + 1 : 1;
}
foreach ($itens as $i => $x) {
    if ($conta[mb_strtolower($x['nome'])] < 2) continue;
    $disp = limparNome(isset($x['bruto']['dispositivo']) ? $x['bruto']['dispositivo'] : '');
    if ($disp !== '') $itens[$i]['nome'] = $disp.' — '.$x['nome'];
}
$usados = array();
foreach ($itens as $i => $x) {
    $nome = cortar($x['nome']);
    $n = 2;
    while (isset($usados[mb_strtolower($nome)])) { $nome = cortar($x['nome'], ' ('.$n.')'); $n++; }
    $usados[mb_strtolower($nome)] = true;
    $itens[$i]['nome'] = $nome;
}

// 2) uma por vez
$total = count($itens);
$r = array('criada' => 0, 'ja_existia' => 0, 'rtmp' => array(), 'recusada' => array(), 'renomeada' => array(), 'desativada' => 0, 'com_local' => 0);
echo ($simular ? 'SIMULAÇÃO (nada é gravado). ' : '').$total." câmeras na lista. Gravação: ".$dias." dia(s), gravando o tempo todo.\n";
$primeira = true;
foreach ($itens as $i => $x) {
    $it = $x['bruto'];
    $nome = $x['nome'];
    $rotulo = sprintf('[%d/%d] %s', $i + 1, $total, $nome);
    try {
        $esquema = isset($it['stream_url']) && is_string($it['stream_url']) ? strtolower(substr(ltrim($it['stream_url']), 0, 5)) : '';
        if (!empty($it['is_rtmp']) || $esquema === 'rtmp:' || $esquema === 'rtmps') {
            $r['rtmp'][] = $nome;
            echo $rotulo.": pulada (RTMP, cadastro manual)\n";
            continue;
        }
        $a = separarEndereco(isset($it['stream_url']) ? $it['stream_url'] : null);
        if (!is_array($a)) throw new InvalidArgumentException($a);
        $end = G2WGestao::enderecoCamera($a);   // mesma validação da tela

        if (G2WGestao::q("SELECT id FROM Devices WHERE device=?", 's', array($end['device']))) {
            $r['ja_existia']++;
            echo $rotulo.": já existia (mesmo endereço), nada feito\n";
            continue;
        }
        // nome já usado por outra câmera do servidor: acrescenta um número
        $base = $nome; $n = 2;
        while (G2WGestao::q("SELECT id FROM Devices WHERE device_name=?", 's', array($nome))) { $nome = cortar($base, ' ('.$n.')'); $n++; }
        G2WGestao::nomeCamera($nome);
        if ($nome !== $x['original']) $r['renomeada'][] = $x['original'].' -> '.$nome;

        $dados = $a + array('nome' => $nome, 'retencao_dias' => $dias, 'gravacao' => 'sempre', 'gravar_som' => false,
            'desativada' => (array_key_exists('ativo', $it) && !$it['ativo']));
        $lat = isset($it['latitude']) && is_numeric($it['latitude']) ? floatval($it['latitude']) : 0.0;
        $lon = isset($it['longitude']) && is_numeric($it['longitude']) ? floatval($it['longitude']) : 0.0;
        $notas = array();
        if ($lat != 0.0 && $lon != 0.0) {
            try {
                G2WGestao::coordenada($lat, $lon);
                $dados['local'] = array('latitude' => $lat, 'longitude' => $lon, 'endereco' => '');
                $notas[] = 'com localização';
            } catch (InvalidArgumentException $ex) {
                $notas[] = 'localização ignorada (fora do mapa)';
            }
        }
        if ($dados['desativada']) $notas[] = 'desativada';
        if (preg_match('/%[0-9A-Fa-f]{2}/', $a['senha'])) $notas[] = 'atenção: a senha parece codificada (%XX); confira se a câmera conecta';

        if ($simular) {
            $id = null;
        } else {
            if (!$primeira && $pausa > 0) usleep(intval($pausa * 1000000));
            $primeira = false;
            $id = G2WGestao::criarCamera($dados);
        }
        $r['criada']++;
        if ($dados['desativada']) $r['desativada']++;
        if (isset($dados['local'])) $r['com_local']++;
        echo $rotulo.': '.($simular ? 'seria criada' : 'criada (id '.$id.')').($notas ? ' ['.implode('; ', $notas).']' : '')."\n";
    } catch (InvalidArgumentException $ex) {
        // os textos de recusa são fixos, não carregam valor do pedido
        $r['recusada'][] = $nome;
        echo $rotulo.': RECUSADA: '.$ex->getMessage()."\n";
    } catch (Throwable $ex) {
        $r['recusada'][] = $nome;
        echo $rotulo.': ERRO interno ('.get_class($ex).'), não criada'."\n";
    }
}

echo "\nResumo".($simular ? ' da simulação' : '').': '.$r['criada'].($simular ? ' seriam criadas' : ' criadas')
    .' ('.$r['desativada'].' desativadas, '.$r['com_local'].' com localização), '
    .$r['ja_existia'].' já existiam, '.count($r['rtmp']).' RTMP puladas, '.count($r['recusada'])." recusadas.\n";
if ($r['rtmp']) echo "RTMP (cadastro manual): ".implode('; ', $r['rtmp'])."\n";
if ($r['renomeada']) echo "Nomes ajustados:\n  ".implode("\n  ", $r['renomeada'])."\n";
if ($r['recusada']) echo "Recusadas: ".implode('; ', $r['recusada'])."\n";
exit($r['recusada'] ? 1 : 0);
