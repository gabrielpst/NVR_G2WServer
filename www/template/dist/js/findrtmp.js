/*
 * Find RTMP streams (G2W Monitor) — ver www/ajax/findrtmp.php.
 *
 * Mesmo padrão do discoverCameras.js: o template traz uma linha-modelo
 * escondida, e aqui ela é clonada por resultado, com os name= indexados pro
 * PHP receber arrays paralelos.
 */

$(function() {
});

function findRtmpClearList(form) {
    $('#findrtmp-list').find('tr').not('#findrtmp-list-bl-orig').remove();
    $('.findrtmp-empty').hide();
}

function findRtmpList(form, msg) {
    var list_bl_orig = $('#findrtmp-list-bl-orig');
    var count = 0;

    if (msg && msg.data && msg.data.length) {
        $.each(msg.data, function(i, val) {
            var bl_clone = list_bl_orig.clone();
            bl_clone.removeAttr('id');
            bl_clone.attr('data-id', i);

            bl_clone.find('.findrtmp-item-path-disp').text(val.path);

            var checkbox = bl_clone.find('.findrtmp-item-check');
            checkbox.attr('name', 'streams[' + i + ']');

            bl_clone.find('.findrtmp-item-path')
                .attr('name', 'path[' + i + ']')
                .val(val.path);

            // Sugestão de nome: o path sem as barras, já que os nomes gerados
            // pelas câmeras costumam vir como "live/liveStream_<serial>_0_0".
            var suggested = String(val.path).replace(/^\/+/, '').replace(/\//g, '-');
            bl_clone.find('.findrtmp-item-camname')
                .attr('name', 'camname[' + i + ']')
                .val(suggested);

            var status = bl_clone.find('.findrtmp-item-status');

            if (val.exists == 1) {
                // Já cadastrado: não deixa duplicar (o servidor rejeitaria de
                // qualquer forma, mas melhor deixar claro antes de tentar).
                status.html('<span class="text-muted">already added</span>');
                checkbox.prop('disabled', true);
                bl_clone.find('.findrtmp-item-camname').prop('disabled', true);
            } else if (val.ready == 1) {
                var tracks = (val.tracks) ? ' (' + val.tracks + ')' : '';
                status.html('<span class="text-success"><i class="fa fa-circle"></i> publishing' + tracks + '</span>');
                checkbox.prop('checked', true);
            } else {
                // Path conhecido pelo relay mas sem ninguém publicando agora —
                // cadastrar não adianta enquanto a câmera não empurrar o stream.
                status.html('<span class="text-muted">idle</span>');
            }

            bl_clone.show();
            list_bl_orig.before(bl_clone);
            count++;
        });
    }

    if (count === 0) {
        $('.findrtmp-empty')
            .text('No stream is being published to the relay right now. Check that the camera is configured to push RTMP to this server on port 1935.')
            .show();
    } else {
        $('.findrtmp-empty').hide();
    }
}

function findRtmpAdded(form, msg) {
    if (!msg || !msg.data) { return; }

    // Marca em verde o que entrou e desabilita, pra não cadastrar duas vezes se
    // o usuário clicar de novo sem atualizar a lista.
    if (msg.data.added && msg.data.added.length) {
        $.each(msg.data.added, function(i, path) {
            var row = form.find('input[value="' + path + '"]').closest('tr');
            row.addClass('success');
            row.find('.findrtmp-item-check').prop('checked', false).prop('disabled', true);
            row.find('.findrtmp-item-camname').prop('disabled', true);
            row.find('.findrtmp-item-status').html('<span class="text-muted">already added</span>');
        });
    }

    // E em vermelho o que falhou, com o motivo que o servidor devolveu (nome
    // duplicado, caminho já cadastrado, etc).
    if (msg.data.failed && msg.data.failed.length) {
        $.each(msg.data.failed, function(i, item) {
            var row = form.find('input[value="' + item.path + '"]').closest('tr');
            row.addClass('danger');
            if (item.msg) {
                row.find('.findrtmp-item-status').html('<span class="text-danger"></span>');
                row.find('.findrtmp-item-status span').text(item.msg);
            }
        });
    }
}
