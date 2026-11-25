# G2W Monitor Server

Servidor de gravação e monitoramento de câmeras (NVR) da **[G2W Tecnologia](https://monitor.g2wseguranca.com.br)** — fork do [`bluecherry-apps`](https://github.com/bluecherrydvr/bluecherry-apps), aberto sob **GPL v2** desde 2019.

Cliente correspondente (app desktop/mobile): [`unity_g2w`](https://github.com/gabrielpst/unity_g2w) — "G2W Monitor".

## O que é diferente do projeto original

Este fork mantém compatibilidade com o `bluecherry-apps` original, mas com correções e recursos próprios:

- **Correção de segurança**: autenticação RTSP/HLS/API (`lib/bc-utils.cpp`) tinha um bug de fallthrough em `switch` que permitia acesso remoto a qualquer usuário com login válido, mesmo sem a permissão `access_remote` marcada. Corrigido.
- **Retenção de gravação por câmera**: cada câmera pode ter seu próprio prazo de retenção (`max_age_days`, configurável no painel), independente da limpeza padrão por espaço em disco — ex.: câmera A retém 3 dias, câmera B retém 7.
- **Ingestão RTMP**: câmeras/encoders que só falam RTMP são recebidas via relay ([MediaMTX](https://github.com/bluenviron/mediamtx)), sem precisar de mudança no servidor — ver `docker-compose.yml`.

## Build e execução

O jeito suportado é via Docker (contexto de build em `docker/`, ver `docker/README.md`) — compila o `.deb` a partir do código-fonte deste repositório (clona o branch indicado, não baixa pacote de terceiro) e monta a imagem de execução (nginx + PHP-FPM + bc-server, mesmo stack usado em produção).

```bash
DOCKER_BUILDKIT=0 docker build -t g2wserver:<tag> --build-arg G2W_GIT_REF=<branch> --build-arg CACHEBUST=$(date +%s) docker/
```

No compose de produção, dar ao `bc-server` `stop_grace_period: 30s`: na parada ele fecha os arquivos de gravação abertos, e é nesse fechamento que o MP4 ganha o índice.

### Mudanças do motor em 2026-11 (branch `servidor-2026-11`)

- Servidor HLS não trava mais depois de o container ser congelado (snapshot de backup do Proxmox).
- Ao vivo isolado da gravação: falha do muxer ao vivo recria só o muxer daquela câmera; timestamps corrigidos em vez de descartados; áudio no HLS só AAC/MP3; playlist conforme a RFC 8216.
- Câmera fora do ar: reconexão com recuo (10 a 30 s) e uma linha de log por mudança de estado.
- Parar o gravador de uma câmera (agenda, troca de endereço, parada do servidor) não aborta mais o processo; banco fora do ar também não.
- Rota `/hls`: token escapado, só gravações registradas são servidas, nginx aceita só playlist e segmento.
- Container: rotação do `bluecherry.log`, senhas fora do `docker logs`, SIGTERM repassado ao bc-server.

### Versão de 2026-11-10: ao vivo com credencial em tudo e tela "Ao vivo" nova

- O segmento de vídeo do ao vivo (`/hls/<câmera>/0/<n>/payload.ts`) só sai com credencial; antes saía para qualquer um que soubesse o endereço. A playlist traz a chave em cada segmento, então os players não mudam.
- A chave do ao vivo vale por câmera (respeita as câmeras bloqueadas do usuário), deixou de ser o identificador da sessão do painel e é renovada enquanto alguém assiste.
- Tela "Ao vivo" nova em `/aovivo`, no lugar da `/liveview`: abre com as câmeras permitidas, 1/2/4/6/9 por tela, troca de câmera por quadro.

### Próxima versão (2026-11-19, ainda sem build): tela de entrada nova e ícone v3

- `/login` e `/changepwd` refeitas em português, sem Bootstrap, com folha e script próprios (`entrar.css`, `entrar.js`). O envio e os campos não mudaram; as frases de erro do servidor seguem em inglês (o app usa as mesmas) e só a tela traduz.
- Ícone v3 do app (câmera + anel) no logo do topo, na tela de entrada e no favicon.

### Adicionar e remover câmera pelo painel: `/gestao/cameras` (branch `cadastro-camera-2026-11`, 2026-11-24, ainda sem build)

Antes a tela mandava para o modo avançado. Agora o administrador cadastra e remove câmera em português, na mesma aba.

- **Adicionar câmera:** nome, endereço IP ou nome, porta RTSP (554), usuário, senha, caminho do vídeo, dias de gravação, grava ou só ao vivo, gravar som (desligado por padrão) e localização no mapa (opcional, com o mesmo diálogo de busca e marcador). O fabricante é um atalho que preenche o caminho: quatro opções fixas (Intelbras/Dahua e Hikvision, imagem principal e leve) e a lista local do servidor (`/usr/share/bluecherry/sqlite/cameras.db`, a mesma do modo avançado; nada é buscado na internet). Câmera nova fica liberada para todos os usuários, e a tela avisa.
- **Testar conexão** (`POST /gestao/camera-testar`), no formulário e em cada câmera já cadastrada (aí com a senha guardada): abre o RTSP por até 10 s e responde em linguagem simples o que veio (H.264 ou H.265, resolução, som AAC ou outro) ou o motivo da falha (sem resposta, porta fechada, não é RTSP, senha recusada, caminho errado, nome não achado). Avisa quando o som não é AAC (a gravação fica sem som) e quando o vídeo é H.265. O aviso não impede salvar.
- **Remover câmera** (`POST /gestao/camera-remover`): por padrão as gravações ficam. A câmera é desativada, perde endereço, usuário e senha, sai do painel, do mapa e do aplicativo, e as gravações passam a ser vistas só por administrador (aba Gravações, "nome (removida)"), seguindo o prazo em dias que a câmera tinha e a limpeza por espaço. O administrador pode apagar na hora da remoção ou depois (`POST /gestao/removida-apagar`). Quando a última gravação sai, a câmera é excluída de vez.
- **Por que a câmera removida continua no banco:** a tabela `Media` tem chave estrangeira em cascata para `Devices`. Excluir a linha da câmera apaga as linhas de gravação e deixa os vídeos soltos no disco, sem dono. Por isso a remoção é em duas fases (tabela `G2WPainelRemovidas` guarda o nome original e a data).
- **Por que apagar é em dois passos:** o PHP não consegue excluir arquivo de gravação (as pastas são do motor, `drwxr-x---`); consegue esvaziar. O painel esvazia cada vídeo (o espaço volta na hora) e marca as linhas como vencidas; a limpeza por prazo do motor, que roda a cada 5 minutos, exclui os arquivos vazios. Efeito colateral conhecido: a exclusão de câmera pelo modo avançado (`ipCamera::remove`) nunca apagou os vídeos do disco nesta imagem.
- **Motor:** criar, remover, desativar e trocar endereço não reiniciam o `bc-server` na imagem atual (conferido na bancada: mesmo processo, as outras câmeras seguem no mesmo arquivo). A câmera nova começa a gravar em cerca de 10 s.
- **Segurança:** só administrador, conferido no servidor, com o mesmo CSRF das outras ações. A senha da câmera nunca volta em resposta, HTML ou log; no teste ela não vai na linha de comando (fica num arquivo temporário 0600 lido pelo `ffprobe` e apagado). O teste só fala RTSP, uma porta, um teste por administrador e dois no servidor, e devolve só categorias, nunca texto da câmera. `user::camPermission()` passou a negar câmera removida a quem não é administrador, o que fecha de uma vez todas as rotas que entregam gravação.
- **A tela não avisa reinício:** nenhuma mensagem diz que o gravador reinicia ou que as outras câmeras param, porque isso não acontece mais (o aviso antigo da edição saiu).
- **Importar em lote** (`tools/importar-cameras.php`, linha de comando, fora do alcance da web): cria câmeras RTSP a partir de uma lista JSON pela mesma função da tela (`G2WGestao::criarCamera`). Uso dentro do container: `php importar-cameras.php [--simular] [--dias=3] [--pausa=3] <arquivo.json | ->`; com `-` a lista vem pela entrada padrão e não precisa ser copiada para o container. Pula RTMP, não duplica (chave: host, porta e caminho), resolve nome repetido com o nome do dispositivo e um número, mantém desativada a que vier inativa e leva latitude e longitude para o mapa. A saída traz só nome, resultado e motivo; nunca endereço, usuário ou senha. A senha pode ter `@` e `:` (a credencial vai até o último `@` antes da primeira `/`); o motor codifica usuário e senha ao montar o endereço.
- Arquivos: `www/ajax/gestao.php`, `www/lib/Gestao.php`, `www/lib/lib.php` (só `camPermission`), `www/template/gestao/cameras.php`, `www/template/dist/js/gestao.js`, `www/template/dist/css/gestao.css`. Tabela nova criada sozinha no primeiro acesso: `G2WPainelRemovidas`.

### Tela "Gravações" para quem assiste: `/gravacoes` (branch `acesso-gravacoes-2026-11`, 2026-11-24, ainda sem build)

Substitui a `/playback` antiga para o usuário comum (`/playback` leva a ela; o administrador continua com as telas dele). Mesmo visual do `/aovivo`.

- **Lista** das câmeras liberadas ao usuário, com a foto mais recente e "Ver gravações".
- **Por câmera**: vídeo grande, data e hora do ponto atual (hora do servidor), tocar e pausar, voltar e avançar 10 s, velocidade (0,5× a 8×), som (começa desligado), tela cheia; escolha do dia, "ir para a hora" e linha do tempo do dia com as faixas gravadas (dia inteiro, 6 h ou 1 h), por clique, arrasto ou teclado. Os arquivos de ~5 min são emendados sozinhos; onde não há gravação, pula para a próxima e avisa.
- **Baixar trecho**: marca início e fim (até 20 min) e baixa um arquivo só pela rota `/media/trecho`, com andamento e com as mensagens do contrato (sem gravação, últimos 5 minutos, um pedido por vez, servidor ocupado, demora).
- **Permissão conferida no servidor** em tudo: "Gravações" no cadastro do usuário (ou administrador) e câmera liberada. Sem a permissão, o link some do `/aovivo` e a tela explica o motivo.
- Rotas: `GET /gravacoes` (tela), `GET /gravacoes/dia?camera=&dia=` (JSON com os trechos do dia), `GET /gravacoes/midia?id=` (arquivo do trecho para o tocador, com `Range`).
- **Limite conhecido**: gravação em H.265 não toca em todo navegador; a tela avisa e oferece baixar o trecho.

### Painel antigo fechado para quem não é administrador (branch `acesso-gravacoes-2026-11`, 2026-11-24, ainda sem build)

Um usuário comum (sem "administrador") abria `/monitor` com o menu inteiro do painel antigo. As telas de administração em si já recusavam; o que estava aberto, e foi fechado no servidor, rota por rota:

- `/monitor`, `/profile`, `/devices` (tela): agora só administrador; o usuário comum é levado a `/aovivo`.
- `/downloadlogs`: entregava os logs do servidor a qualquer usuário logado. Só administrador.
- `/devices?XML=1` e `?JSON=1` (a lista de câmeras que o aplicativo lê): continuam para todos, só com as câmeras liberadas, mas para quem não é administrador saem apenas `id`, `device_name`, `protocol`, `resolutionX`, `resolutionY`, `disabled`, `status` e `oldest_recording`. Antes iam também o endereço e o caminho da câmera e todos os ajustes.
- `POST /devices/...`: qualquer usuário logado renomeava câmera. Só administrador (403).
- `/media/index`: listava as gravações de todas as câmeras, com o caminho no disco. Agora só das câmeras liberadas, e sem o caminho para quem não é administrador.
- `/redirect?id=`: revelava o endereço de qualquer câmera. Só administrador.
- `/layouts`: só a `/liveview` antiga usava, e monta SQL com texto do pedido. Só administrador.
- `/media/trigger`: confere a câmera liberada e limpa o texto.

O que o aplicativo usa não mudou: `loginapp.php`, `/devices.php?XML=1` (campos que ele lê preservados), `/events/`, `/media/request.php`, `/media/hls`, `/media/trecho`, `/mapa/cameras`.

### Marca por instalação (branch `marca-2026-11`, 2026-11-24, ainda sem build)

Cada cliente roda a mesma imagem na sua LXC; o nome mostrado nas telas pode ser trocado por instalação, sem imagem própria.

- **Arquivo opcional `/etc/g2w/marca.json`**, fora do `www`, montado pelo compose (somente leitura, legível pelo usuário `www-data`):

  ```json
  { "nome": "K Soluções", "logo": "logo.png" }
  ```

  Campos, todos opcionais: `nome` (até 40 caracteres), `subtitulo` (até 80; a frase sob o nome na tela de entrada), `rodape` (até 60; o rodapé das telas de entrada) `icone` (caminho de imagem dentro de `/img/`, ex.: `/img/arquivo.png`) e `logo`.
- **`logo`**: nome de um arquivo de imagem na mesma pasta `/etc/g2w/` (só o nome, sem caminho), montado junto com o `marca.json`. PNG, JPG ou SVG, reconhecido pelo conteúdo, até 512 KB. É servido em `/marca/logo`, sem login (a tela de entrada usa) e sem parâmetro que escolha arquivo. Com logo, a tela de entrada mostra a imagem no lugar do ícone e do nome, e o cabeçalho de `/gestao` e `/aovivo` também; o nome vira o texto alternativo. Logo ausente, grande demais, de outro tipo ou SVG com script: é ignorada e vale o nome em texto.
- **Sem o arquivo, tudo sai como sempre** ("G2W Monitor"). JSON inválido, arquivo maior que 4 KB, sem permissão de leitura ou campo fora do formato: vale o padrão daquele campo, sem quebrar a tela. O valor é escapado na saída.
- **Onde o nome aparece**: título da aba e nome na tela de entrada (`/login`), título da aba em `/changepwd`, e em `/gestao` e `/aovivo` o título da aba, o cabeçalho e o rodapé. Com nome próprio, o cabeçalho de `/gestao` e `/aovivo` mostra o ícone e o nome em texto no lugar do logotipo "G2W Monitor" (que é uma imagem).
- **Onde não muda**: o que o aplicativo lê (realm do Basic, mensagens, nome do arquivo de trecho baixado), o painel antigo em `/monitor`, o favicon.
- Código: `www/lib/Marca.php` (classe `G2WMarca`) e `www/ajax/marca.php` (rota `/marca/logo`).

### Baixar trecho de gravação por horário (branch `trecho-2026-11`, 2026-11-24, ainda sem build)

- **`GET /media/trecho?id=<câmera>&inicio=<epoch>&fim=<epoch>`**: junta, sem recodificar, os arquivos gravados (~5 min cada) que cobrem a janela e devolve um MP4 só, de até 20 minutos. É a rota que o aplicativo usa para "baixar das 13h03 às 13h21". Contrato em [`docs/api-trecho-gravacao.md`](docs/api-trecho-gravacao.md).
- **Quem pode**: usuário não desativado, com direito de ver gravações, e só de câmera liberada para ele; conferido no servidor. Cookie de sessão ou Basic.
- **Limites para não pesar no servidor**: um pedido por usuário, 2 downloads ao mesmo tempo no servidor todo, 45 s para montar, conferência de espaço livre; temporário em `/tmp/g2w-trecho/`, apagado no fim, em erro e na desistência.
- **O que não faz**: não inclui o arquivo ainda em gravação (os ~5 min mais recentes), não retoma download interrompido, e emenda por cima de buraco de gravação (avisa em cabeçalho).
- **Pendente na rota antiga `/media/request`** (não alterada aqui): ela não confere o direito de ver gravações nem se o usuário foi desativado quando a autenticação é por Basic.

### Mapa de câmeras (branch `mapa-2026-11`, 2026-11-23, ainda sem build)

- **Localização por câmera** (latitude, longitude, endereço) numa tabela própria, `G2WPainelLocais`, criada sozinha no primeiro uso. Instalação existente não precisa de migração manual; câmera sem linha na tabela é câmera sem localização.
- **`GET /mapa/cameras`**: devolve só as câmeras que o usuário logado pode ver, com id, nome, coordenada e endereço. É a rota que o aplicativo consome. Contrato em [`docs/api-mapa-cameras.md`](docs/api-mapa-cameras.md).
- **Painel `/gestao`**: em "Câmeras", cada câmera ganhou "Localização no mapa" (endereço com busca, clique no mapa ou marcador arrastável); aba nova "Mapa" com todas as câmeras localizadas.
- **Serviços de fora usados pelas telas de mapa** (e só por elas):
  - imagens do mapa: `tile.openstreetmap.org`, pedidas pelo navegador do administrador. Vai para lá o IP dele e a origem da página (`Referer` só com esquema, endereço e porta).
  - busca de endereço: Nominatim (`nominatim.openstreetmap.org`), pedida **pelo servidor** quando o administrador aperta "Buscar no mapa". Vai para lá só o texto digitado e o IP do servidor. No máximo 1 pedido por segundo por instalação; a resposta fica guardada por um dia.
- Leaflet 1.9.4 (BSD-2-Clause) servido pelo próprio servidor, em `www/template/dist/js/vendor/leaflet-1.9.4/`. Sem CDN.

### Painel de gestão (branch `painel-gestao-2026-11`, junto na mesma imagem)

Telas novas em português em `/gestao` (visão geral com a saúde das câmeras, câmeras, usuários com criação e ações em lote, gravações com linha do tempo). O painel original continua disponível como modo avançado em `/monitor`.

Painel web: `https://<host>:7001` (login inicial `Admin` / `bluecherry` — trocar a senha assim que entrar).

Build alternativo direto na máquina (sem Docker) via `scripts/build_pkg_native.sh` — script original do projeto, depende de headers de kernel do host. Não usar comandos `dh` diretamente: os scripts geram alguns arquivos necessários (`version.h`, por exemplo) e os arquivos de controle do Debian antes da build.

**Dependências (servidor):**
- mysql (debian: `libmysqlclient-dev`)
- libbsd (debian: `libbsd-dev`)
- php (debian: `php-dev`)
- udev (debian: `libudev-dev`)

**Dependências (libav):**
- yasm
- alsa-lib (debian: `libasound2-dev`)

**Bibliotecas incluídas:**
- libconfig
- libav

## Licença

GPL v2, herdada do projeto original. Ver `LICENSE`.

## Contato

**G2W Tecnologia** — [monitor.g2wseguranca.com.br](https://monitor.g2wseguranca.com.br)
WhatsApp: [+55 67 99608-6281](https://wa.me/5567996086281) · E-mail: [contato@g2wseguranca.com.br](mailto:contato@g2wseguranca.com.br)
