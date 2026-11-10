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
- Câmera fora do ar: reconexão com recuo (10 a 60 s) e uma linha de log por mudança de estado.
- Parar o gravador de uma câmera (agenda, troca de endereço, parada do servidor) não aborta mais o processo; banco fora do ar também não.
- Rota `/hls`: token escapado, só gravações registradas são servidas, nginx aceita só playlist e segmento.
- Container: rotação do `bluecherry.log`, senhas fora do `docker logs`, SIGTERM repassado ao bc-server.

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
