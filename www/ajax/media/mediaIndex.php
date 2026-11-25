<?php

include_once lib_path . 'lib.php';

class mediaIndex extends Controller {
	
    public function __construct()
    {
        parent::__construct();
		$this->chAccess('backup');
    }

    public function getData()
    {
        print "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n";

	$db = database::getInstance();

        $events = $db->fetchAll("SELECT * FROM Media ORDER BY start DESC");

        # Output one item for each media entry
        // G2W 2026-11: só as câmeras liberadas ao usuário (antes listava as
        // gravações de todas), e o caminho do arquivo no servidor só para o
        // administrador.
        $admin = (bool)intval($this->user->info['access_setup']);
        foreach ($events as $item) {
            if (!$this->user->camPermission($item['device_id'])) continue;
            if (!$admin) unset($item['filepath']);
        	print "  <item id='". $item['id'] ."'>\n";

        	foreach (array_keys($item) as $key) {
        		if ($key == 'id')
        			continue;
        		print "    <$key>". $item[$key] ."</$key>\n";
        	}

        	print "  </item>\n";
        }


        die();
    }
}

