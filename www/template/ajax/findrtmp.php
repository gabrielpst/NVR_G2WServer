<div class="row">
    <div class="col-lg-12">
        <h1 class="page-header"><?php echo FIND_RTMP_HEADER; ?></h1>
    </div>
</div>

<div class="row">
    <div class="col-lg-12">
        <div class="alert alert-info">
            <i class="fa fa-info-circle fa-fw"></i>
            <?php echo FIND_RTMP_INTRO; ?>
            <br><br>
            <code><?php echo FIND_RTMP_PUSH_HINT; ?></code>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-12">
        <div class="panel panel-default">
            <div class="panel-body" style="min-height: 200px;">
                <form action="/findrtmp/add" id="findrtmp-list" method="post" class="form-inline">
                    <table class="table table-striped table-vert-align">
                        <thead>
                            <tr>
                                <th class="col-lg-5 col-md-5"><?php echo FIND_RTMP_COL_STREAM; ?></th>
                                <th class="col-lg-3 col-md-3"><?php echo FIND_RTMP_COL_STATUS; ?></th>
                                <th class="col-lg-4 col-md-4"><?php echo FIND_RTMP_COL_NAME; ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php # Linha-modelo escondida: o JS clona uma por stream encontrado
                              # e preenche os name= indexados, mesmo padrão do discoverCameras. ?>
                        <tr id="findrtmp-list-bl-orig" style="display: none;" data-id="">
                            <td>
                                <div class="checkbox">
                                    <label>
                                        <input type="checkbox" class="findrtmp-item-check" name="">
                                        <span class="findrtmp-item-path-disp"></span>
                                    </label>
                                    <input type="hidden" class="findrtmp-item-path" name="" value="">
                                </div>
                            </td>
                            <td>
                                <span class="findrtmp-item-status"></span>
                            </td>
                            <td>
                                <input type="text" class="form-control findrtmp-item-camname" name="" value="">
                            </td>
                        </tr>
                        </tbody>
                    </table>
                    <p class="text-muted findrtmp-empty" style="display: none;">—</p>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-12">
        <form action="/findrtmp" method="post" class="form-horizontal" style="display: inline;">
            <button type="submit" class="btn btn-success send-req-form" data-func-after="findRtmpList" data-func="findRtmpClearList"><i class="fa fa-refresh fa-fw"></i> <?php echo FIND_RTMP_FIND; ?></button>
        </form>
        <button type="button" class="btn btn-primary send-req-form" data-form-id="findrtmp-list" data-func-after="findRtmpAdded"><i class="fa fa-plus fa-fw"></i> <?php echo FIND_RTMP_ADD; ?></button>
    </div>
</div>
