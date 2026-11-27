/* G2W Monitor — leitura de coordenadas coladas no diálogo de localização.
 * Só roda no navegador: o texto colado nunca vai ao servidor; o servidor
 * recebe só os dois números e confere a faixa de novo (G2WGestao::coordenada).
 *
 * Aceita:
 *   "-20,404751 ; -54,586690"        vírgula decimal, separado por ponto e vírgula
 *   "-20.404751, -54.586690"         como o Google Maps copia
 *   "-20.404751 -54.586690"          separado por espaço
 *   20°24'17.1"S 54°35'12.1"W        graus, minutos e segundos
 *   links do Google Maps com @lat,lon / !3dlat!4dlon / q= / ll= / query= / center= / destination=
 *
 * Devolve {ok:true, lat, lon, aviso?} ou {ok:false, erro, sugestao?:{lat, lon}}.
 * Com a instalação no Brasil, ponto que só cai no Brasil trocando o sinal não
 * é marcado: vem um erro com a sugestão (latitude sem o "-" iria parar no
 * hemisfério norte). */
(function (raiz) {
    'use strict';

    // retângulo folgado em volta do Brasil (ilhas oceânicas incluídas)
    var BR = { latMin: -34.0, latMax: 5.5, lonMin: -74.2, lonMax: -28.5 };
    function noBrasil(lat, lon) { return lat >= BR.latMin && lat <= BR.latMax && lon >= BR.lonMin && lon <= BR.lonMax; }
    function naFaixa(lat, lon) { return isFinite(lat) && isFinite(lon) && lat >= -90 && lat <= 90 && lon >= -180 && lon <= 180; }

    function numero(t) {
        var n = parseFloat(String(t).replace(',', '.'));
        return isFinite(n) ? n : NaN;
    }

    // "20°24'17.1\"S" -> -20.404750
    function gms(texto) {
        var re = /(\d{1,3})\s*°\s*(?:(\d{1,2}(?:[.,]\d+)?)\s*['′’]\s*)?(?:(\d{1,2}(?:[.,]\d+)?)\s*(?:["″”]|'')\s*)?([NSLOEW])/gi;
        var achados = [], m;
        while ((m = re.exec(texto)) !== null) {
            var v = numero(m[1]) + (m[2] ? numero(m[2]) / 60 : 0) + (m[3] ? numero(m[3]) / 3600 : 0);
            var h = m[4].toUpperCase();
            achados.push({ valor: (h === 'S' || h === 'O' || h === 'W') ? -v : v, eixo: (h === 'N' || h === 'S') ? 'lat' : 'lon' });
        }
        if (achados.length !== 2 || achados[0].eixo === achados[1].eixo) return null;
        var lat = achados[0].eixo === 'lat' ? achados[0].valor : achados[1].valor;
        var lon = achados[0].eixo === 'lon' ? achados[0].valor : achados[1].valor;
        return [lat, lon];
    }

    function doLink(texto) {
        var t = texto;
        try { t = decodeURIComponent(texto); } catch (e) { /* link com % solto: usa como veio */ }
        var m = /!3d(-?\d{1,3}(?:\.\d+)?)!4d(-?\d{1,3}(?:\.\d+)?)/.exec(t)          // o alfinete do lugar
            || /[?&](?:q|ll|query|center|destination|daddr)=(-?\d{1,3}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)/.exec(t)
            || /@(-?\d{1,3}(?:\.\d+)?),(-?\d{1,3}(?:\.\d+)?)/.exec(t);              // o centro da tela do mapa
        return m ? [parseFloat(m[1]), parseFloat(m[2])] : null;
    }

    function doTexto(texto) {
        var t = texto.replace(/[−–—]/g, '-');   // sinais de menos que vêm de copiar e colar
        var partes;
        if (t.indexOf(';') >= 0) {
            partes = t.split(';');
            if (partes.length !== 2) return null;
        } else {
            partes = t.match(/-?\s?\d{1,3}(?:[.,]\d+)?/g);
            if (!partes || partes.length !== 2) return null;
        }
        var nums = partes.map(function (p) {
            p = p.replace(/\s+/g, '');
            return /^-?\d{1,3}(?:[.,]\d+)?$/.test(p) ? numero(p) : NaN;
        });
        return (isFinite(nums[0]) && isFinite(nums[1])) ? nums : null;
    }

    function ler(entrada) {
        var texto = String(entrada == null ? '' : entrada).trim();
        if (!texto) return { ok: false, erro: 'Cole ou digite as coordenadas.' };
        if (texto.length > 2000) return { ok: false, erro: 'Texto longo demais para ser uma coordenada.' };

        var par = null;
        if (/^https?:\/\//i.test(texto) || /google\.[a-z.]+\/maps|maps\.google\./i.test(texto)) {
            if (/goo\.gl\/|maps\.app\.goo\.gl/i.test(texto) && !doLink(texto)) {
                return { ok: false, erro: 'Link curto do Google Maps não traz as coordenadas. Abra o link, toque e segure no ponto e copie os números que aparecem (ex.: -20.404751, -54.586690).' };
            }
            par = doLink(texto);
            if (!par) return { ok: false, erro: 'Não achei coordenadas nesse link. No Google Maps, toque e segure no ponto e copie os números (ex.: -20.404751, -54.586690).' };
        } else {
            par = /°/.test(texto) ? gms(texto) : doTexto(texto);
            if (!par) return { ok: false, erro: 'Não entendi essas coordenadas. Use, por exemplo, -20.404751, -54.586690 ou -20,404751 ; -54,586690.' };
        }

        var lat = par[0], lon = par[1];
        if (!naFaixa(lat, lon)) {
            return { ok: false, erro: 'Posição fora do mapa: a latitude vai de -90 a 90 e a longitude de -180 a 180. Confira se não estão invertidas.' };
        }
        if (noBrasil(lat, lon)) return { ok: true, lat: lat, lon: lon };

        // fora do Brasil: sinal esquecido ou números trocados de lugar?
        var tentativas = [[-lat, lon], [lat, -lon], [-lat, -lon], [lon, lat]];
        for (var i = 0; i < tentativas.length; i++) {
            if (noBrasil(tentativas[i][0], tentativas[i][1])) {
                var s = tentativas[i];
                var motivo = i >= 3 ? 'Parece que a latitude e a longitude estão trocadas de lugar.'
                    : 'Parece que faltou o sinal de menos: no Brasil a longitude é sempre negativa e a latitude quase sempre também (sul do Equador).';
                return { ok: false, erro: motivo, sugestao: { lat: s[0], lon: s[1] } };
            }
        }
        return { ok: true, lat: lat, lon: lon, aviso: 'Esse ponto fica fora do Brasil. Confira no mapa antes de salvar.' };
    }

    var api = { ler: ler, noBrasil: noBrasil };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    else raiz.G2WCoordenadas = api;
})(typeof window !== 'undefined' ? window : this);
