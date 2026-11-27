# Formato da imagem por câmera

Contrato para o aplicativo (e qualquer cliente) ler como mostrar a imagem de cada câmera. Vale a partir da versão que trouxer a branch `proxima-2026-11`.

## O que é

No painel (`/gestao/cameras`) o administrador escolhe, por câmera, o **Formato da imagem**:

| `formato` | Na tela | `proporcao` | Como mostrar |
|---|---|---|---|
| `auto` (padrão) | Automático | `null` | Imagem **8:9 com altura até 1080** (960×1080, 640×720, 480×540) vira **16:9**; qualquer outra, como chega (inclusive 8:9 maior, como 2304×2592 ou 1920×2160) |
| `16:9` | 16:9 | `"16:9"` | esticar para 16:9 |
| `4:3` | 4:3 | `"4:3"` | esticar para 4:3 |
| `empe` | Em pé (9:16) | `"9:16"` | esticar para 9:16 |
| `original` | Como chega | `null` | como o vídeo chega, sem esticar |

"Esticar" é encher uma janela daquela proporção com a imagem inteira, sem cortar (no ExoPlayer, `resizeMode` de preenchimento numa caixa com a proporção certa; no navegador, `object-fit: fill` numa caixa com a proporção). A sobra do quadro em volta fica escura, como hoje.

**Regra exata do automático** (a mesma no servidor e no aplicativo), com `L` e `A` = largura e altura do vídeo decodificado:

```
esticar para 16:9  se  L × 9 == A × 8  e  A <= 1080
senão              como chega
```

Por que até 1080: câmera de **lente dupla** entrega duas imagens 16:9 empilhadas (ex.: 2304×2592 = 2 × 2304×1296), que também é 8:9 mas não está espremida; esticar achataria as duas metades (achado na câmera 8 do Silvestre4, 27/11). Para ela, o automático já deixa como chega.

Por que 8:9: canais de gravador Dahua/Intelbras entregam 960×1080 com a cena 16:9 espremida pela metade na horizontal e sem marcação de proporção (SAR). A NUV estica, e é o que o automático faz.

O formato só muda a exibição. A gravação e o vídeo enviado não mudam.

## Rota

`GET /media/formatos`, com o cookie de sessão **ou** com HTTP Basic (como `/media/request.php`). Com "um aparelho por vez" ligado, vale a mesma regra das outras rotas (mande o `X-G2W-Aparelho`).

Resposta `200`:

```json
{"ok": true, "cameras": [
  {"id": 4,   "formato": "4:3",  "proporcao": "4:3"},
  {"id": 101, "formato": "auto", "proporcao": null}
]}
```

- Só as câmeras ligadas que o usuário pode ver, em ordem de id.
- `proporcao` é `"L:A"` quando o formato é fixo; `null` quando depende do tamanho real do vídeo (`auto`, `original`). No `auto`, o aplicativo aplica a regra exata acima à largura e à altura do vídeo decodificado (`L × 9 == A × 8` e `A <= 1080` → 16:9).
- Câmera sem formato escolhido vem como `auto`.
- Usuário desativado: `403` `{"ok": false, "erro": "Usuário desativado."}`. Sem credencial: `401`. Credencial errada: `302` para `/login` (herdado).
- Não devolve endereço, usuário nem senha de câmera.

Ler ao abrir o aplicativo e ao voltar para a lista é suficiente: o formato muda raramente e vale no próximo vídeo aberto.

## Miniatura

A miniatura do ao vivo (`/media/request.php?mode=livesnapshot`) já sai no formato da câmera (ex.: 960×1080 no automático vira um JPEG 640×360; 2304×2592 fica 640×720). Quem mostra a miniatura como chega (o aplicativo até hoje) já vê certo, sem atualização. Depois de trocar o formato no painel, a miniatura é refeita na volta seguinte do atualizador de miniaturas (até ~2 minutos; com horários fixos de miniatura, também na volta seguinte).

## Vídeo e marcação de proporção (SAR)

Se o próprio vídeo trouxer a marca de proporção (SAR no H.264), o navegador já mostra a imagem na proporção certa: o tamanho que o player informa já vem corrigido (ex.: 1920×1080 para um 960×1080 com SAR 2:1), e o automático não estica de novo. Ver no registro da versão o resultado do teste no ExoPlayer.
