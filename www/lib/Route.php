<?php

#lib
include_once lib_path . 'lib.php';  #common functions

class Route
{

    public function __construct ()
    {

    }

    private function statRoute($one)
    {
        $res = Array();

        if ($one) {
            $one = strtolower($one);

            if (isset($this->route[$one])) {
                $arr = explode('.', $this->route[$one]);

                $res['ctl'] = $arr[0];
                if (isset($arr[1])) $res['method'] = $arr[1];
                else $res['method'] = '';
            }
        }

        return $res;
    }

    public function start()
    {
        $old_ajax_url = false;
        $uri = parse_url($_SERVER['REQUEST_URI']);



        $url = ltrim($uri['path'], '/');
        //$url = strtolower($url);
        if (empty($url)) {
            // Era '/news' (feed de Announcements embutido via iframe do
            // AnnounceKit da Bluecherry — carrega o changelog deles a cada
            // login, vazando IP/UA do usuário pra infra deles). Landing page
            // agora é o painel de status do sistema, sem chamada externa.
            // Controller real é ajax/monitor.php (rota /monitor, singular —
            // "monitoring" só existe como nome do template/menu, não da rota;
            // erro pego em teste real: usar "/monitoring" aqui dava "Route
            // error!" porque www/ajax/monitoring.php não existe).
            // G2W 2026-11: a entrada agora é o painel de gestão (/gestao,
            // telas simples em português). O painel completo segue em
            // /monitor ("modo avançado"). Quem não é admin é redirecionado
            // pelo próprio controller, como antes.
            $uri['path'] = '/gestao';
        } else {
            if (preg_match('/ajax\/(.*)\.php/', $url, $matches)) {
                $old_ajax_url = true;
                //$url = $matches[1];
            } else {
                //$url = str_replace('.php', '', $url);
            }
        }

        $routes = explode('/', $uri['path']);

        $routes_ctl = new StdClass();
        $routes_ctl->one = $this->chVar(1, $routes);
        $routes_ctl->two = $this->chVar(2, $routes);
        $routes_ctl->three = $this->chVar(3, $routes);
        $routes_ctl->four = $this->chVar(4, $routes);

        if ($old_ajax_url) {
            $routes_ctl->one = $routes_ctl->two;
            $routes_ctl->two = $routes_ctl->three;
            $routes_ctl->three = $routes_ctl->four;
            $routes_ctl->four = '';
        }

        $ctl_path = site_path.'ajax'.'/'.$routes_ctl->one.'.php';

        if (file_exists($ctl_path)) {
            include_once($ctl_path);

            $res = new $routes_ctl->one();
            $res = $res->start($routes_ctl);
        } else {
            $res = 'Route error!';
        }


        print_r($res);
    }

    private function chVar($var, $arr)
    {
        if (isset($arr[$var])) {
            $res = $arr[$var];
            $res = strtolower($res);
            $res = str_replace('.php', '', $res);

            $res = str_replace('_', '-', $res);
            
            $res_arr = explode('-', $res);
            $res = $res_arr[0];

            if (count($res_arr) > 1) {
                unset($res_arr[0]);
                foreach ($res_arr as $val) {
                    $res .= ucfirst($val);
                }
            }
        }
        else $res = '';

        return $res;
    }

}

