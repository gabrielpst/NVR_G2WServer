<div class="row">
    <div class="col-lg-12">
        <h1 class="page-header"><?php echo DISCOVER_IP_CAMERAS; ?></h1>
    </div>
</div>

<div class="row">
    <div class="col-lg-12">
        <div class="panel panel-default">
            <div class="panel-body" style="min-height: 300px;">
                <form action="/discover-cameras/add" id="discover-cameras-list" method="post" class="form-inline">
                    <table class="table table-striped table-vert-align">
                        <tbody>
                        <tr id="discover-cameras-list-bl-orig" style="display: none;" data-id="">
                            <td class="col-lg-3 col-md-3 col-sm-4 col-xs-4">
                                <div class="checkbox">
                                    <label>
                                        <input type="checkbox" class="discover-cameras-item-cameras" name="">
                                        <span class="discover-cameras-item-ipv4-disp"></span>
                                    </label>
                                    <input type="hidden" class="discover-cameras-item-ipv4-path" name="" value="">
                                    <input type="hidden" class="discover-cameras-item-ipv4" name="" value="">
                                    <input type="hidden" class="discover-cameras-item-ipv4-port" name="" value="">
                                    <input type="hidden" class="discover-cameras-item-manufacturer" name="" value="">
                                    <input type="hidden" class="discover-cameras-item-model-name" name="" value="">
                                </div>
                            </td>
                            <td>
                                <span class="discover-cameras-item-manuf-name"></span>
                            </td>
                            <td>
                                <div class="form-group discover-cameras-item-auth" style="display: none;">
                                    <input type="text" class="form-control discover-cameras-item-login" name="" value="" placeholder="Login">
                                    <input type="text" class="form-control discover-cameras-item-password" name="" value="" placeholder="Password">
                                </div>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-12">
        <form action="/discover-cameras" method="post" class="form-horizontal" style="display: inline;">
            <button type="submit" class="btn btn-success send-req-form" data-func-after="discoverCamerasFind" data-func="discoverCamerasClearList">Find cameras</button>
        </form>
        <button type="button" class="btn btn-primary send-req-form" data-form-id="discover-cameras-list" data-func-after="discoverCamerasAdded">Add selected cameras</button>
    </div>
</div>

<br>

<?php # Busca por IP: a descoberta automática acima é por broadcast (WS-Discovery),
      # e nem toda câmera se anuncia — algumas até declaram isso em GetCapabilities
      # (DiscoveryResolve/RemoteDiscovery = false), mesmo com o ONVIF funcionando
      # perfeitamente. Sem este caminho, esses aparelhos ficam invisíveis aqui.
      # O resultado entra na MESMA tabela acima, com usuário/senha já preenchidos,
      # pra reaproveitar o "Add selected cameras" sem nenhuma mudança. ?>
<div class="row">
    <div class="col-lg-12">
        <div class="panel panel-default">
            <div class="panel-heading"><i class="fa fa-search fa-fw"></i> <?php echo PROBE_IP_HEADER; ?></div>
            <div class="panel-body">
                <p class="text-muted"><small><?php echo PROBE_IP_INTRO; ?></small></p>
                <form action="/discover-cameras/probe" id="discover-cameras-probe" method="post" class="form-inline">
                    <div class="form-group">
                        <label class="sr-only"><?php echo PROBE_IP_ADDR; ?></label>
                        <input type="text" class="form-control" name="probe_ip" placeholder="<?php echo PROBE_IP_ADDR; ?>" style="width: 170px;">
                    </div>
                    <div class="form-group">
                        <label class="sr-only"><?php echo PROBE_IP_PORT; ?></label>
                        <input type="text" class="form-control" name="probe_port" value="80" placeholder="<?php echo PROBE_IP_PORT; ?>" style="width: 110px;">
                    </div>
                    <div class="form-group">
                        <label class="sr-only"><?php echo PROBE_IP_LOGIN; ?></label>
                        <input type="text" class="form-control" name="probe_login" placeholder="<?php echo PROBE_IP_LOGIN; ?>" style="width: 150px;">
                    </div>
                    <div class="form-group">
                        <label class="sr-only"><?php echo PROBE_IP_PASSWORD; ?></label>
                        <input type="password" class="form-control" name="probe_password" placeholder="<?php echo PROBE_IP_PASSWORD; ?>" style="width: 150px;">
                    </div>
                    <button type="submit" class="btn btn-info send-req-form" data-form-id="discover-cameras-probe" data-func-after="discoverCamerasProbed"><?php echo PROBE_IP_BUTTON; ?></button>
                </form>
                <div class="discover-cameras-probe-result" style="display: none; margin-top: 12px;"></div>
            </div>
        </div>
    </div>
</div>
