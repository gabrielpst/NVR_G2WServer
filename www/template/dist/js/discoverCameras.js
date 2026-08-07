$(function() {
});

function discoverCamerasFind(form, msg) {
    var list_bl = $('#discover-cameras-list');
    var list_bl_orig = $('#discover-cameras-list-bl-orig');

    var items_html = '';
    $.each(msg.data, function(i, val) {
        var bl_clone = list_bl_orig.clone();
        bl_clone.removeAttr('id');
        bl_clone.attr('data-id', i);

        bl_clone.find('.discover-cameras-item-manuf-name').html(val.manufacturer +' '+ val.model_name);
        bl_clone.find('.discover-cameras-item-ipv4-disp').html(val.ipv4);

        var checkbox = bl_clone.find('.discover-cameras-item-cameras');
        checkbox.attr('name', 'cameras['+i+']');
        if (val.exists == 1) {
            checkbox.prop('disabled', true);
        }

        bl_clone.find('.discover-cameras-item-ipv4-path').attr('name', 'ipv4_path['+i+']').val(val.ipv4_path);
        bl_clone.find('.discover-cameras-item-ipv4').attr('name', 'ipv4['+i+']').val(val.ipv4);
        bl_clone.find('.discover-cameras-item-ipv4-port').attr('name', 'ipv4_port['+i+']').val(val.ipv4_port);
        bl_clone.find('.discover-cameras-item-manufacturer').attr('name', 'manufacturer['+i+']').val(val.manufacturer);
        bl_clone.find('.discover-cameras-item-model-name').attr('name', 'model_name['+i+']').val(val.model_name);

        bl_clone.find('.discover-cameras-item-login').attr('name', 'login['+i+']').val('');
        bl_clone.find('.discover-cameras-item-password').attr('name', 'password['+i+']').val('');

        bl_clone.show();
        list_bl_orig.after(bl_clone);
    });

}

function discoverCamerasAdded(form, msg) {
    form.find('tr').removeClass('danger');
    form.find('tr').removeClass('warning');

    $.each(msg.data.err.ip, function(i, val) {
        var parent = form.find('input[value="'+val+'"]').closest('tr').addClass('danger');
    });

    $.each(msg.data.err.passwd_ip, function(i, val) {
        var parent = form.find('input[value="'+val+'"]').closest('tr');
        parent.addClass('danger');
        parent.find('.discover-cameras-item-auth').show();
    });

    $.each(msg.data.err.rtsp_ip, function(i, val) {
        var parent = form.find('input[value="'+val+'"]').closest('tr').addClass('danger');
    });

    $.each(msg.data.err.onvif_ip, function(i, val) {
        var parent = form.find('input[value="'+val+'"]').closest('tr').addClass('warning');
    });

    $.each(msg.data.added_ip, function(i, val) {
        var parent = form.find('input[value="'+val+'"]').closest('tr');
        var checkbox = parent.find('input[type="checkbox"]');
        checkbox.prop('checked', false);
        checkbox.prop('disabled', true);
    });
}

function discoverCamerasClearList(form) {
    $('#discover-cameras-list').find('tr').not('#discover-cameras-list-bl-orig').remove();
}

/*
 * Resultado da busca por IP. A linha entra na MESMA tabela da busca automática,
 * com usuário/senha já preenchidos, pra reaproveitar o "Add selected cameras"
 * sem mudança nenhuma — ver postProbe() em www/ajax/discoverCameras.php.
 */
function discoverCamerasProbed(form, msg) {
    var box = $('.discover-cameras-probe-result');

    // status 7 = erro; a mensagem específica (não respondeu / senha recusada /
    // sem canal) já vem do servidor, é só mostrar.
    if (!msg || parseInt(msg.status) !== 6 || !msg.data || !msg.data.length) {
        var err = (msg && msg.msg && msg.msg.length) ? msg.msg[0] : 'Search failed.';
        box.attr('class', 'discover-cameras-probe-result alert alert-danger').text(err).show();
        return;
    }

    var val = msg.data[0];
    var list_bl_orig = $('#discover-cameras-list-bl-orig');

    // Índice novo, sem colidir com as linhas que a busca automática criou.
    var idx = 'p' + $('#discover-cameras-list').find('tr').length;

    // Se o mesmo IP já está listado, substitui a linha em vez de duplicar —
    // o caso comum é procurar de novo depois de corrigir a senha.
    $('#discover-cameras-list')
        .find('.discover-cameras-item-ipv4[value="' + val.ipv4 + '"]')
        .closest('tr').remove();

    var bl_clone = list_bl_orig.clone();
    bl_clone.removeAttr('id');
    bl_clone.attr('data-id', idx);

    var label = (val.manufacturer || val.model_name)
        ? ((val.manufacturer + ' ' + val.model_name).trim())
        : 'ONVIF device';
    if (val.channels > 1) {
        label += ' — ' + val.channels + ' channels';
    }

    bl_clone.find('.discover-cameras-item-manuf-name').text(label);
    bl_clone.find('.discover-cameras-item-ipv4-disp').text(val.ipv4);

    var checkbox = bl_clone.find('.discover-cameras-item-cameras');
    checkbox.attr('name', 'cameras[' + idx + ']');

    bl_clone.find('.discover-cameras-item-ipv4-path').attr('name', 'ipv4_path[' + idx + ']').val(val.ipv4_path);
    bl_clone.find('.discover-cameras-item-ipv4').attr('name', 'ipv4[' + idx + ']').val(val.ipv4);
    bl_clone.find('.discover-cameras-item-ipv4-port').attr('name', 'ipv4_port[' + idx + ']').val(val.ipv4_port);
    bl_clone.find('.discover-cameras-item-manufacturer').attr('name', 'manufacturer[' + idx + ']').val(val.manufacturer);
    bl_clone.find('.discover-cameras-item-model-name').attr('name', 'model_name[' + idx + ']').val(val.model_name);

    // Credenciais já validadas pelo probe: preenche e deixa à vista, pra ficar
    // claro que é com elas que o cadastro vai ser feito.
    bl_clone.find('.discover-cameras-item-login').attr('name', 'login[' + idx + ']').val(val.login);
    bl_clone.find('.discover-cameras-item-password').attr('name', 'password[' + idx + ']').val(val.password);
    bl_clone.find('.discover-cameras-item-auth').show();

    if (val.exists == 1) {
        checkbox.prop('disabled', true);
    } else {
        checkbox.prop('checked', true);
    }

    bl_clone.show();
    list_bl_orig.after(bl_clone);

    box.attr('class', 'discover-cameras-probe-result alert alert-success')
       .text((msg.msg && msg.msg.length) ? msg.msg[0] : 'Device found.')
       .show();
}
