# Um aparelho por vez (sessão única por usuário)

Contrato para o aplicativo e para qualquer cliente do servidor G2W Monitor. Vale a partir da versão que trouxer a branch `sessao-unica-2026-11`.

## A regra

- É uma opção da instalação, que o administrador liga ou desliga no painel (`/gestao/usuarios`). **Padrão: desligada.** Com ela desligada nada neste documento muda o comportamento do servidor, a não ser os campos novos da resposta do login.
- Ligada: cada usuário comum usa o sistema em **um aparelho por vez**, somando aplicativo e site. Quando o mesmo usuário entra em outro aparelho, o aparelho novo passa a ser o dono e o anterior é desligado.
- Administrador (`access_setup = 1`) fica fora da regra.
- A regra nunca olha o endereço IP. Trocar de Wi-Fi para 4G, ou passar por um proxy, não desliga ninguém.
- Ao ligar a opção ninguém é desligado na hora: ela vale a partir do próximo login de cada usuário.

## Como o servidor reconhece o aparelho

| Cliente | Identidade |
|---|---|
| Aplicativo novo | o cabeçalho `X-G2W-Aparelho`, um identificador estável da instalação |
| Site | a sessão do navegador (o cookie `bluecherrydvr`) |
| Cliente que não manda o cabeçalho (app até 1.1.2, desktop) | a sessão criada no login (o cookie `bluecherrydvr`) |

### Cabeçalho `X-G2W-Aparelho`

- Um valor gerado pelo aplicativo na primeira execução e guardado na instalação: de **16 a 128 caracteres**, só `A-Z a-z 0-9 . _ -` (um UUID serve). Não use IMEI, número de série, e-mail nem nada que identifique a pessoa ou o telefone fora do aplicativo. Reinstalar pode gerar outro.
- Mande em **todos** os pedidos ao servidor: no login e depois, tanto nos que vão com cookie quanto nos que vão com Basic.
- O servidor guarda só o SHA-256 do valor, por usuário, e só o do aparelho que é o dono no momento.
- Valor fora do formato no login: o login é recusado (ver abaixo). Nos outros pedidos, valor fora do formato é tratado como aparelho diferente do dono.

## Login

`POST /ajax/loginapp.php`, como hoje (`login`, `password`, `from_client=true`, corpo `application/x-www-form-urlencoded`), mais o cabeçalho `X-G2W-Aparelho`.

Resposta de sucesso, com dois campos novos:

```json
{"success": true, "server_uuid": "…", "sessao_unica": true, "aparelho_reconhecido": true}
```

- `sessao_unica`: `true` se a regra está ligada **e** vale para este usuário (não é administrador).
- `aparelho_reconhecido`: `true` se o servidor recebeu um `X-G2W-Aparelho` válido neste login.

Efeito, com a regra ligada e usuário comum:

- o aparelho deste login vira o dono;
- se o dono anterior era **outro** aparelho, ele é desligado e as chaves de vídeo ao vivo do usuário são apagadas (o ao vivo do aparelho anterior para; o aparelho novo pede as suas depois do login, normalmente);
- se o dono anterior era **este mesmo** aparelho (mesmo `X-G2W-Aparelho`), nada é derrubado: abrir o aplicativo de novo, ou logar de novo depois de a sessão expirar, não interrompe nada.

Cabeçalho fora do formato: HTTP 200 com

```json
{"success": false, "codigo": "aparelho_invalido", "message": "Identificador do aparelho inválido."}
```

## Aparelho desligado

Qualquer pedido de um aparelho que deixou de ser o dono recebe, em qualquer rota (`/devices.php`, `/events/`, `/media/…`, `/mapa/…`, `/gravacoes/…`):

```
HTTP/1.1 401 Unauthorized
X-G2W-Sessao: substituida
Content-Type: application/json; charset=utf-8

{"ok": false, "success": false, "codigo": "sessao_substituida",
 "erro": "Sua conta foi acessada em outro aparelho.",
 "message": "Sua conta foi acessada em outro aparelho."}
```

- É sempre 401 com o cabeçalho `X-G2W-Sessao: substituida`. Não é o `302` para `/login` da sessão expirada nem o `401` com `WWW-Authenticate` da falta de credencial: dá para distinguir só pelo cabeçalho.
- A sessão do aparelho desligado é encerrada no servidor nesse pedido.
- O vídeo ao vivo que estava tocando para em até 15 segundos: o endereço com `?authtoken=` deixa de ser aceito (401 do próprio servidor de vídeo, sem o corpo acima). Ao pedir um endereço novo em `/media/hls`, vem o 401 acima.
- **O aplicativo deve:** parar os pedidos, mostrar "Sua conta foi acessada em outro aparelho" e voltar para a tela de entrada. **Não refazer o login sozinho**: um login novo toma a vez de volta e desliga o outro aparelho; dois aparelhos fazendo isso se derrubam sem parar. O login novo só acontece quando a pessoa toca em entrar.
- No site, a página vai para `/login?motivo=outro-aparelho` e mostra a mesma frase.

## Pedidos com Basic (sem cookie)

`/media/request.php` (miniatura, quadro e arquivo de gravação), `/media/trecho`, `/media/hls` e `/events/` aceitam HTTP Basic sem sessão. Com a regra ligada e usuário comum:

| Pedido | Dono atual | Resposta |
|---|---|---|
| com `X-G2W-Aparelho` igual ao do dono | aparelho identificado | atendido |
| com `X-G2W-Aparelho` diferente | qualquer | 401 `sessao_substituida` |
| com `X-G2W-Aparelho`, usuário ainda sem dono | nenhum | atendido (não vira dono; só o login faz isso) |
| sem cabeçalho | aparelho identificado | 401 `sessao_substituida` |
| sem cabeçalho | nenhum, ou um cliente sem identificação | atendido |

A última linha é a limitação dos clientes antigos, explicada abaixo.

## Sair

`GET /logout` (ou encerrar a sessão) pelo aparelho que é o dono libera a vez: o usuário fica sem dono até o próximo login. Sair por um aparelho que já não era o dono não muda nada.

## Clientes que não mandam o cabeçalho

Aplicativo publicado até a 1.1.2, o desktop e qualquer cliente antigo continuam entrando com a regra ligada. Para eles a identidade é a sessão criada no login:

- **Funciona:** um login novo, de qualquer aparelho, desliga a sessão anterior. As rotas com cookie do aparelho anterior (lista de câmeras, eventos, pedido de ao vivo) passam a responder 401 `sessao_substituida`, e o ao vivo dele para.
- **Não é derrubado à toa:** reabrir o mesmo aplicativo faz um login novo e encerra só a sessão antiga dele mesmo.
- **Limitação 1 (furo):** os pedidos com Basic sem cabeçalho não dizem de que aparelho vêm. Enquanto o dono for um cliente sem identificação, um aparelho antigo já desligado ainda consegue miniaturas e arquivos de gravação cujo endereço ele já conhece. Ele não lista câmeras nem eventos novos e não assiste ao vivo. O furo fecha quando o dono é um aparelho com o aplicativo novo: aí todo Basic sem cabeçalho é recusado.
- **Limitação 2 (mensagem):** o aplicativo até 1.1.2 não conhece a resposta nova. Desligado, ele mostra a lista vazia ou erro em vez da frase, até a pessoa fechar e abrir (e aí toma a vez de volta).
- **Limitação 3 (fora do alcance desta regra):** quem fala direto com o servidor de vídeo usando usuário e senha (RTSP na porta do motor, `/hls/<id>/index.m3u8` com Basic) não passa pelo painel e não é limitado. O aplicativo não usa esse caminho no uso normal.

## Para o administrador

- Chave em `/gestao/usuarios`, "Um aparelho por vez". `POST /gestao/sessao-unica` com `{"ligado": true|false}` (só administrador, com o token CSRF do painel).
- Guardada no banco (`GlobalSettings`, parâmetro `G2W_SESSAO_UNICA`); vale no pedido seguinte, sem reiniciar nada.
- Ligar apaga a lista de donos, para ninguém ser desligado por um login antigo. Desligar para de conferir.
- Redefinir a senha ou desativar um usuário continua funcionando como antes e também libera a vez dele.

## O que o servidor guarda

Tabela `G2WPainelSessoes`, uma linha por usuário comum que logou com a regra ligada: id do usuário, SHA-256 da identidade do dono, se o dono mandou identificador, se entrou pelo aplicativo e quando. Sem IP, sem modelo de aparelho, sem histórico. A linha some quando o dono sai, quando o administrador desativa o usuário ou redefine a senha dele, e quando a opção é ligada ou desligada.
