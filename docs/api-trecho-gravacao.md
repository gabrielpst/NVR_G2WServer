# Rota de trecho de gravação: `GET /media/trecho`

Contrato para o aplicativo baixar, num arquivo só, um trecho de gravação escolhido por horário de início e de fim (até 20 minutos). Vale a partir da versão que trouxer a branch `trecho-2026-11`.

## Pedido

```
GET /media/trecho?id=<id da câmera>&inicio=<epoch em segundos>&fim=<epoch em segundos>
```

- **`id`**: id da câmera (o mesmo de `/mapa/cameras` e das outras rotas), não o id de uma gravação.
- **`inicio`** e **`fim`**: instantes em segundos desde 1970 (UTC), inteiros. `fim` maior que `inicio`, e `fim - inicio` de no máximo **1200** (20 minutos).
- Os três são obrigatórios e só aceitam dígitos (até 10). Sinal, decimal, espaço ou qualquer outro caractere é recusado.
- **Autenticação:** a mesma de `/media/request`: o cookie `bluecherrydvr` da sessão (`POST /ajax/loginapp.php`) **ou** HTTP Basic com usuário e senha.
- Também responde em `/media/trecho.php`, como as rotas antigas.
- Só `GET`.

## Resposta `200`

O corpo é o vídeo, MP4, sem recodificar (mesma qualidade e mesmo codec da gravação: H.264 ou H.265, com áudio quando a câmera grava áudio).

| Cabeçalho | Conteúdo |
|---|---|
| `Content-Type` | `video/mp4` |
| `Content-Length` | tamanho em bytes (sempre presente; dá para mostrar progresso) |
| `Content-Disposition` | `attachment; filename="G2W-Monitor_<câmera>_<AAAA-MM-DD>_<HH-MM-SS>_a_<HH-MM-SS>.mp4"`, na hora local do servidor |
| `X-G2W-Trecho-Inicio` | epoch (s) do começo do que foi entregue, dentro da janela pedida |
| `X-G2W-Trecho-Fim` | epoch (s) do fim do que foi entregue |
| `X-G2W-Trecho-Duracao` | duração real do vídeo, em segundos (decimal, ex.: `1080.7`) |
| `X-G2W-Trecho-Completo` | `1` se a janela pedida foi coberta inteira; `0` se faltou gravação em alguma parte |
| `Accept-Ranges` | `none`: não há retomada; download interrompido recomeça do zero |
| `Cache-Control` | `private, no-store` |

O que esperar do vídeo:

- **Começo:** o corte cai no quadro-chave anterior ao `inicio`. O vídeo pode começar alguns segundos **antes** do pedido (o intervalo de quadro-chave da câmera), nunca depois. `X-G2W-Trecho-Inicio` informa o instante pedido, não esse recuo.
- **Fim:** corta no `fim` pedido (precisão de um quadro).
- **Buraco na gravação:** vem o que existe, emendado. O vídeo fica mais curto que a janela e pula direto por cima do buraco; `X-G2W-Trecho-Completo` vem `0` e `X-G2W-Trecho-Duracao` mostra o tamanho real. Se o buraco é no começo ou no fim, `X-G2W-Trecho-Inicio` e `X-G2W-Trecho-Fim` mostram onde o conteúdo começa e termina de verdade.
- **Os últimos minutos:** o arquivo que o servidor ainda está gravando (os ~5 minutos mais recentes) não entra. Uma janela que chega até "agora" volta sem o final, com `Completo: 0`; uma janela toda dentro desses minutos dá `404`.
- **Emendas:** os arquivos de origem têm cerca de 5 minutos; em cada emenda um quadro pode ser encurtado. Não há perda perceptível.

## Erros

Todos em JSON, `{"ok": false, "erro": "<texto em português para mostrar ao usuário>"}`, exceto onde indicado.

| Status | Quando |
|---|---|
| `401` | Sem cookie de sessão e sem Basic. Vem com `WWW-Authenticate: Basic`. |
| `302` | Herdado do servidor, sem JSON: Basic com senha errada (`Location: /login`) ou sessão derrubada/expirada (`Location: /login` ou `/liveview`). Trate como "entrar de novo"; não siga o redirecionamento. |
| `403` | Usuário sem direito de ver gravações, usuário desativado, câmera não liberada para ele, ou câmera que não existe (a mesma resposta, de propósito). |
| `404` | Nenhuma gravação da câmera na janela. |
| `405` | Método diferente de `GET`. |
| `422` | Parâmetro ausente ou fora do formato, `fim` menor ou igual a `inicio`, ou janela maior que 20 minutos. |
| `429` | O mesmo usuário já tem um trecho sendo montado ou baixado. `Retry-After: 30`. |
| `503` | O servidor já atende o máximo de downloads de trecho ao mesmo tempo (2). `Retry-After: 60`. |
| `504` | A montagem passou de 45 segundos (disco do servidor ocupado). Tentar de novo ou pedir um trecho menor. |
| `507` | Sem espaço temporário no servidor para montar o trecho. |
| `500` | Falha ao montar. |

## Quem pode baixar

Conferido no servidor a cada pedido:

1. usuário autenticado e não desativado (com acesso ao site ou ao aplicativo), ou administrador;
2. direito de ver gravações (`access_backup`, a caixa "Gravações" do painel), ou administrador;
3. câmera liberada para o usuário.

## Cuidados para o aplicativo

- Um pedido por vez por usuário: desative o botão enquanto baixa e trate `429` e `503` com a espera do `Retry-After`.
- Um trecho de 20 minutos pode passar de 500 MB numa câmera de bitrate alto. Mostre o tamanho (`Content-Length`) e grave direto em arquivo, não em memória.
- Tempo limite do cliente: a resposta começa em até ~45 s (montagem) e o envio depende da rede; não use limite curto para o corpo.
- O vídeo é imagem de pessoas (LGPD): salve só onde o usuário escolheu, não guarde cópia em cache do aplicativo e não envie a terceiros.
- A hora no nome do arquivo é a do servidor (Campo Grande, UTC−4), não a do celular.

## Do lado do servidor

- Montagem com `ffmpeg` (concat, cópia direta), chamado sem shell; a lista de arquivos vem do banco e só vale dentro das pastas de gravação cadastradas.
- Temporário em `/tmp/g2w-trecho/`, apagado ao terminar, em erro e quando o cliente desiste; sobra com mais de 1 hora é varrida no pedido seguinte.
- Cada download registra no log do servidor: usuário (id), câmera, janela e tamanho.
