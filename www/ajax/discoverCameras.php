<?php 


class discoverCameras extends Controller {

    protected $default_pass = Array(
        Array('' => ''),
        Array('admin' => 'admin'),
        Array('admin' => ''),
    );

    // http://ipvm.com/reports/ip-cameras-default-passwords-directory
    protected $default_manuf_pass = Array(
        'ACTI' => Array(
            Array('admin' => '12345'),
            Array('Admin' => '123456'),
        ),
        'American Dynamics' => Array(
            Array('admin' => 'admin'),
            Array('admin' => '9999'),
        ),
        'Arecont Vision' => Array(),
        'Avigilon' => Array(
            Array('admin' => 'admin'),
        ),
        'Axis' => Array(
            Array('root' => 'pass'),
        ),
        'Basler' => Array(
            Array('admin' => 'admin'),
        ),
        'Bosch' => Array(),
        'Brickcom' => Array(
            Array('admin' => 'admin'),
        ),
        'Canon' => Array(
            Array('root' => 'camera'),
        ),
        'Dahua' => Array(
            Array('admin' => 'admin'),
        ),
        'Digital Watchdog' => Array(
            Array('admin' => 'admin'),
        ),
        'DRS' => Array(
            Array('admin' => '1234'),
        ),
        'DVTel' => Array(
            Array('Admin' => '1234'),
        ),
        'DynaColor' => Array(
            Array('Admin' => '1234'),
        ),
        'FLIR' => Array(
            Array('admin' => 'fliradmin'),
        ),
        'Foscam' => Array(
            Array('admin' => ''),
        ),
        'GeoVision' => Array(
            Array('admin' => 'admin'),
        ),
        'Grandstream' => Array(
            Array('admin' => 'admin'),
        ),
        'Hikvision' => Array(
            Array('admin' => '12345'),
        ),
        'Honeywell' => Array(
            Array('admin' => '1234'),
        ),
        'Intellio' => Array(
            Array('admin' => 'admin'),
        ),
        'IQinVision' => Array(
            Array('root' => 'system'),
        ),
        'IPX-DDK' => Array(
            Array('root' => 'admin'),
            Array('root' => 'Admin'),
        ),
        'JVC' => Array(
            Array('admin' => 'jvc'),
        ),
	'Lorex' => Array(
	    Array('admin' => 'admin'),
	),
        'March Networks' => Array(
            Array('admin' => ''),
        ),
        'Mobotix' => Array(
            Array('admin' => 'meinsm'),
        ),
        'Panasonic' => Array(
            Array('admin' => '12345'),
        ),
        'Pelco Sarix' => Array(
            Array('admin' => 'admin'),
        ),
        'Pixord' => Array(
            Array('admin' => 'admin'),
        ),
        'Samsung Electronics' => Array(
            Array('root' => 'root'),
            Array('admin' => '4321'),
        ),
        'Samsung Techwin' => Array(
            Array('admin' => '1111111'),
        ),
        'Samsung' => Array(
            Array('admin' => '4321'),
        ),
        'Sanyo' => Array(
            Array('admin' => 'admin'),
        ),
        'Scallop' => Array(
            Array('admin' => 'password'),
        ),
        'Sentry360' => Array(
            Array('admin' => '1234'),
        ),
        'Sony' => Array(
            Array('admin' => 'admin'),
        ),
        'Speco' => Array(
            Array('admin' => '1234'),
        ),
        'Stardot' => Array(
            Array('admin' => 'admin'),
        ),
        'Starvedia' => Array(
            Array('admin' => ''),
        ),
        'Trendnet' => Array(
            Array('admin' => 'admin'),
        ),
        'Toshiba' => Array(
            Array('root' => 'ikwd'),
        ),
        'VideoIQ' => Array(
            Array('supervisor' => 'supervisor'),
        ),
        'Vivotek' => Array(
            Array('root' => ''),
        ),
        'Ubiquiti' => Array(
            Array('ubnt' => 'ubnt'),
        ),
        'Wodsee' => Array(
            Array('admin' => ''),
        ),
    );

    public function __construct(){
        parent::__construct();
        $this->chAccess('admin');
    }

    public function getData()
    {
        $this->setView('ajax.discoverCameras');
    }

    public function postData()
    {
        $status = 10;
        $res = Array();
        $ips = Array();
        $checked_ip = Array();
        $onvif_ports = Array(
            80,
            8899,
            34567,
            34599
        );

        // Descoberta em PHP puro (ver wsDiscover()). Vem primeiro porque as duas
        // ferramentas externas abaixo NÃO funcionam nesta imagem:
        //   - a de Node exige o pacote bluecherry-node-onvif (Recommends:, que
        //     não é instalado) e o módulo npm em /usr/share/nodejs/onvif;
        //   - onvif_tool é um wrapper PHP que chama /usr/lib/bluecherry/onvif_tool,
        //     binário que o pacote nunca instala (dpkg -L confirma: só vão os
        //     scripts). Falha do projeto original, não da nossa build.
        // Ficam na lista mesmo assim, como reforço, caso alguém instale o pacote
        // do Node depois — quem responder primeiro ganha, sem duplicar IP.
        foreach ($this->wsDiscover() as $cam) {
            if (in_array($cam['ipv4'], $ips)) { continue; }
            $res[] = $cam;
            $ips[] = $cam['ipv4'];
        }

        $tools = Array(
            "NODE_PATH=/usr/share/nodejs/onvif/node_modules node /usr/share/bluecherry/onvif/discovery_json/node-onvif.js",
            "/usr/share/bluecherry/onvif/discovery_json/onvif_tool",
        );
        foreach ($tools as $tool) {
            $p = @popen($tool, "r");
            if (!$p) { continue; }
            while ($json_str = fgets($p)) {
                $cam = json_decode($json_str, true);
                if (empty($cam['ipv4'])) { continue; }
                $ip = $cam['ipv4'];
                // append, unless already reported by earlier tools
                if (in_array($ip, $ips)) { continue; }
                $res[] = $cam;
                $ips[] = $cam['ipv4'];
            }
	    pclose($p);
        }

        $ips = array_unique($ips);

        $exist_devices = $this->existDevices($ips);
        foreach ($res as $key => $val) {
            if (in_array($val['ipv4'], $exist_devices)) {
                $ips = array_diff($ips, Array($val['ipv4']));
                $res[$key]['exists'] = 1;
            } else {
                $res[$key]['exists'] = 0;
            }
        }
        foreach ($ips as $key => $val) {
            if (in_array($val, $exist_devices)) {
                unset($ips[$key]);
            }
        }
	//
        data::responseJSON($status, 'err', $res);
    }


    /**
     * Descoberta ONVIF (WS-Discovery) implementada direto em PHP.
     *
     * Existe porque as duas ferramentas externas que o upstream usa não estão
     * disponíveis nesta imagem (ver comentário em postData()). O protocolo é
     * simples: manda um SOAP "Probe" por multicast UDP em 239.255.255.250:3702
     * e coleta as respostas "ProbeMatch" por alguns segundos. Cada aparelho
     * responde com XAddrs (URL do serviço ONVIF) e Scopes (nome/modelo).
     *
     * Devolve exatamente o mesmo formato do node-onvif.js original, pra não
     * mexer no template nem no JS que consomem isso:
     *   ipv4 => IP de quem respondeu
     *   manufacturer => scope onvif://www.onvif.org/name/...
     *   model_name => scope onvif://www.onvif.org/hardware/...
     *   ipv4_path => primeira URL de XAddrs (completa)
     *   ipv4_port => "host:porta"
     *
     * Obs.: só funciona com o container em network_mode host — multicast não
     * atravessa a ponte de rede padrão do Docker.
     */
    protected function wsDiscover($timeout = 5)
    {
        $res = Array();

        if (!function_exists('socket_create')) {
            return $res; // extensão sockets ausente — nada a fazer
        }

        $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sock === false) { return $res; }

        // Porta efêmera: as respostas voltam em unicast pra porta de origem.
        @socket_bind($sock, '0.0.0.0', 0);
        @socket_set_option($sock, IPPROTO_IP, IP_MULTICAST_TTL, 4);
        @socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, Array('sec' => 1, 'usec' => 0));

        $uuid = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        $probe = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<e:Envelope xmlns:e="http://www.w3.org/2003/05/soap-envelope"'
            . ' xmlns:w="http://schemas.xmlsoap.org/ws/2004/08/addressing"'
            . ' xmlns:d="http://schemas.xmlsoap.org/ws/2005/04/discovery"'
            . ' xmlns:dn="http://www.onvif.org/ver10/network/wsdl">'
            . '<e:Header>'
            . '<w:MessageID>uuid:' . $uuid . '</w:MessageID>'
            . '<w:To e:mustUnderstand="true">urn:schemas-xmlsoap-org:ws:2005:04:discovery</w:To>'
            . '<w:Action e:mustUnderstand="true">http://schemas.xmlsoap.org/ws/2005/04/discovery/Probe</w:Action>'
            . '</e:Header>'
            . '<e:Body><d:Probe><d:Types>dn:NetworkVideoTransmitter</d:Types></d:Probe></e:Body>'
            . '</e:Envelope>';

        @socket_sendto($sock, $probe, strlen($probe), 0, '239.255.255.250', 3702);

        $deadline = time() + $timeout;
        $seen = Array();

        while (time() < $deadline) {
            $buf = '';
            $from = '';
            $port = 0;

            // Bloqueia no máximo 1s (SO_RCVTIMEO) e volta pro laço, pra respeitar
            // o $deadline mesmo quando ninguém mais responde.
            if (@socket_recvfrom($sock, $buf, 65535, 0, $from, $port) === false) {
                continue;
            }
            if (empty($buf)) { continue; }

            // Namespaces variam entre fabricantes (d:, wsd:, sem prefixo...),
            // então casa pelo nome local da tag em vez de usar um parser XML
            // amarrado a prefixo.
            if (!preg_match('/<[^>]*XAddrs[^>]*>([^<]+)</i', $buf, $m)) { continue; }

            $xaddrs = trim($m[1]);
            if ($xaddrs === '') { continue; }

            // Algumas câmeras (Hikvision) devolvem várias URLs no mesmo campo,
            // incluindo IPv6. Fica com a primeira, igual o node-onvif.js fazia.
            $url = trim(explode(' ', $xaddrs)[0]);

            $parts = @parse_url($url);
            if (empty($parts['host'])) { continue; }

            $ip = $parts['host'];
            if (isset($seen[$ip])) { continue; }
            $seen[$ip] = true;

            $url_port = (!empty($parts['port'])) ? $parts['port'] : 80;

            $manufacturer = '';
            $model_name = '';
            if (preg_match('/<[^>]*Scopes[^>]*>([^<]+)</i', $buf, $sm)) {
                foreach (preg_split('/\s+/', trim($sm[1])) as $scope) {
                    if (strpos($scope, 'onvif://www.onvif.org/name/') === 0) {
                        $manufacturer = rawurldecode(substr($scope, 27));
                    } else if (strpos($scope, 'onvif://www.onvif.org/hardware/') === 0) {
                        $model_name = rawurldecode(substr($scope, 31));
                    }
                }
            }

            $res[] = Array(
                'ipv4'         => $ip,
                'manufacturer' => $manufacturer,
                'model_name'   => $model_name,
                'ipv4_path'    => $url,
                'ipv4_port'    => $ip . ':' . $url_port,
            );
        }

        @socket_close($sock);

        return $res;
    }

    /**
     * Pega as URLs RTSP (principal e extra) de uma câmera ONVIF, usando a
     * biblioteca ONVIF em PHP que já vem no pacote (www/lib/Ponvif.php).
     *
     * Mesmo motivo do wsDiscover(): o caminho original dependia do Node
     * (getRtspUrls.js) ou de um binário que não é instalado.
     *
     * $addr no formato "host:porta". Devolve um array com um item por canal
     * (câmera IP = 1 item; DVR/NVR = um por entrada de vídeo), cada um no
     * formato ['main' => URL do stream principal, 'sub' => URL do extra ou '']
     * — ou array vazio se não conseguir falar com o aparelho.
     */
    protected function onvifStreamUris($addr, $username, $password)
    {
        $uris = Array();

        try {
            $ponvif = new Ponvif();
            $ponvif->setIPAddress($addr);
            $ponvif->setUsername($username);
            $ponvif->setPassword($password);

            $init = $ponvif->initialize();
            if (empty($init['onvif']) || empty($init['datetime'])) {
                return $uris;
            }

            $sources = $ponvif->getSources();
            if (empty($sources) || $ponvif->isFault($sources)) {
                return $uris;
            }

            // Uma "fonte de vídeo" por canal: câmera IP tem uma, DVR/NVR tem
            // uma por entrada (o Intelbras MHDX_1004-C testado expõe 4).
            // $sources[<fonte>][<perfil>]['profiletoken'] — ver
            // Ponvif::_getProfileData(). Perfil 0 = stream principal,
            // perfil 1 = stream extra (menor resolução).
            foreach ($sources as $source) {
                $ch_uris = Array();

                foreach ($source as $key => $profile) {
                    // A chave 'sourcetoken' convive com os índices numéricos
                    // dos perfis no mesmo array; só interessam os numéricos.
                    if (!is_int($key) || empty($profile['profiletoken'])) { continue; }

                    $uri = $ponvif->media_GetStreamUri($profile['profiletoken']);
                    if (!empty($uri) && is_string($uri)) {
                        $ch_uris[] = $uri;
                    }
                }

                if (!empty($ch_uris)) {
                    $uris[] = Array(
                        'main' => $ch_uris[0],
                        'sub'  => (isset($ch_uris[1])) ? $ch_uris[1] : '',
                    );
                }
            }
        } catch (Exception $e) {
            return Array();
        }

        return $uris;
    }

    protected function curlReq($url) {
        $ch=curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $output=curl_exec($ch);

        curl_close($ch);

        return $output;
    }

    protected function getManufPass($manuf)
    {
        $res = Array();
        $manuf = strtolower($manuf);

        foreach ($this->default_manuf_pass as $key => $val) {
            $key = strtolower($key);
            if ($key == $manuf || strpos($manuf, $key) !== false) {
                $res = $val;
                break;
            }
        }

        if (empty($res)) $res = $this->default_pass;

        return $res;
    }

    private function existDevices($ips_arr)
    {
        $res = Array();

        if (!empty($ips_arr)) {
            $ips_str = implode('|', $ips_arr);
            $list_cam = data::query("SELECT device FROM Devices WHERE CAST(device AS BINARY) REGEXP BINARY '{$ips_str}'");
            if (!empty($list_cam)) {
                foreach ($list_cam as $key => $val) {
                    $tmp = explode('|', $val['device']);
                    $res[] = $tmp[0];
                }
            }
        }

        return $res;
    }


    public function postAdd()
    {
        $status = 10;

        $err['ip'] = Array();
        $err['passwd_ip'] = Array();
        $err['rtsp_ip'] = Array();
        $err['onvif_ip'] = Array();

        $added_ip = Array();

        $ipv4 = (Inp::gp_arr('ipv4'));
        $ipv4_port = (Inp::gp_arr('ipv4_port'));
        $ipv4_path = (Inp::gp_arr('ipv4_path'));
        $manufacturer = (Inp::gp_arr('manufacturer'));
        $model_name_arr = (Inp::gp_arr('model_name'));
        $login_arr = (Inp::gp_arr('login'));
        $password_arr = (Inp::gp_arr('password'));

        $exist_devices = $this->existDevices($ipv4);

        foreach(Inp::gp_arr('cameras') as $key => $val) {
            $err_add = true;

            $ip = $ipv4_port[$key];
            if (!in_array($ipv4[$key], $exist_devices)) {
            $manuf = $manufacturer[$key];
            $model_name = $model_name_arr[$key];

            if (!empty($login_arr[$key]) || !empty($password_arr[$key])) {
                $passwords = Array();
                $passwords[] = Array(
                    $login_arr[$key] => $password_arr[$key]
                );
            } else {
                if (!empty($manuf)) {
                    $passwords = $this->getManufPass($manuf);
                } else {
                    $passwords = $this->default_pass;
                }
            }

            $onvif_addr = $ip;

            $password_ch = false;
            // guess login/passwrod
            foreach ($passwords as $pass_arr) {
                foreach ($pass_arr as $login => $password) {
                    $onvif_username = $login;
                    $onvif_password = $password;

                    try {
                        // Caminho principal: biblioteca ONVIF em PHP que já vem no
                        // pacote (ver onvifStreamUris()). Substitui o par
                        // node/getRtspUrls.js + binário onvif_tool do upstream, que
                        // não existem nesta imagem — sem isto, "Add selected
                        // cameras" falhava em todas as câmeras, mesmo com a
                        // descoberta funcionando.
                        // Um item por canal: câmera IP devolve 1, DVR/NVR devolve
                        // um por entrada de vídeo.
                        $channels = $this->onvifStreamUris($onvif_addr, $onvif_username, $onvif_password);

                        if (empty($channels)) {
                            // Reforço: se alguém instalar o pacote bluecherry-node-onvif
                            // depois, esse caminho volta a funcionar sozinho.
                            $json_out = @shell_exec("node /usr/share/bluecherry/onvif/getRtspUrls.js " . escapeshellarg($onvif_addr) .' '. escapeshellarg($onvif_username) .' '. escapeshellarg($onvif_password));
                            if ($json_out) {
                                $urls = json_decode($json_out, /*associative=*/true);
                                if (!empty($urls[0]['rtspUri'])) {
                                    $channels[] = Array(
                                        'main' => $urls[0]['rtspUri'],
                                        'sub'  => (!empty($urls[1]['rtspUri'])) ? $urls[1]['rtspUri'] : '',
                                    );
                                }
                            }
                        }

                        if (!empty($channels))
                        {
                            // Credenciais aceitas — não precisa tentar as outras.
                            $password_ch = true;
                            $added_any = false;

                            foreach ($channels as $ch_idx => $channel) {
                                $media_uri = trim($channel['main']);
                                if (empty($media_uri)) { continue; }

                                $media_uri_parse = parse_url($media_uri);
                                if (empty($media_uri_parse['host'])) { continue; }

                                if (!isset($media_uri_parse['port'])) {
                                    $media_uri_parse['port'] = 554;
                                }

                                if (isset($media_uri_parse['query'])) $media_uri_parse['path'] .= '?'.$media_uri_parse['query'];

                                // O original montava isso errado: atribuía o path e
                                // depois concatenava a query dentro de um if que
                                // testava o PATH (não a query), gerando "?" solto ou
                                // aviso quando não havia query.
                                $sub_path = '';
                                if (!empty($channel['sub'])) {
                                    $sub_parse = parse_url(trim($channel['sub']));
                                    if (!empty($sub_parse['path'])) {
                                        $sub_path = $sub_parse['path'];
                                        if (isset($sub_parse['query'])) {
                                            $sub_path .= '?'.$sub_parse['query'];
                                        }
                                    }
                                }

                                // device_name é único no banco. Num DVR/NVR todos os
                                // canais têm o mesmo host, então sem sufixo só o
                                // primeiro entraria e os demais falhariam como nome
                                // duplicado.
                                $cam_name = $media_uri_parse['host'];
                                if (count($channels) > 1) {
                                    $cam_name .= ' - CH' . ($ch_idx + 1);
                                }

                                $_POST = Array(
                                    'mode' => 'addip',
                                    'models' => 'Generic',
                                    'camName' => $cam_name,
                                    'ipAddr' => $media_uri_parse['host'],
                                    'user' => $login,
                                    'pass' => $password,
                                    'protocol' => 'IP-RTSP',
                                    'rtsp' => $media_uri_parse['path'],
                                    'port' => $media_uri_parse['port'],
				                    'substream' => $sub_path,
                                    'prefertcp' => '0',
                                    'mjpeg' => '',
                                    'portMjpeg' => 80
                                );

                                $result = ipCamera::create($_POST);
                                if ($result[0]) {
                                    $added_any = true;
                                }
                            }

                            if ($added_any) {
                                $err_add = false;
                                $added_ip[] = $ip;
                            } else {
                                $err['ip'][] = $ip;
                            }

                            break(2);
                        }
                    } catch (Exception $e) {
                        $err['ip'][] = $ip;
                        break(2);
                    }
                }

            }

            if (!$password_ch) {
                $err['passwd_ip'][] = $ip;
            }

            } else {
                $added_ip[] = $ip;
            }
        }

        $res['err'] = $err;
        $res['added_ip'] = $added_ip;


        data::responseJSON($status, '', $res);
    }
}
