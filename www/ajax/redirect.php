<?php 

class redirect extends Controller {
	
    public function __construct(){
        parent::__construct();
		// G2W 2026-11: só administrador. Revelava o endereço da câmera a qualquer usuário, sem conferir a câmera.
		$this->chAccess('admin');
    }

    public function getData()
    {
        $camera = new ipCamera(intval($_GET['id']));

        header('Location: http://'.$camera->info['ipAddr']);
    }

    public function postData()
    {
    }
}
