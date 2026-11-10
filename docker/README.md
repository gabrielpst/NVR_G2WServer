# Imagem do G2W Monitor (servidor)

Contexto de build da imagem `g2wserver`. Antes vivia só em `/root/build` da LXC 109
(`g2wserver-build`, pvedom); agora é versionado junto com o código que ele empacota.

```bash
DOCKER_BUILDKIT=0 docker build -t g2wserver:<tag> \
  --build-arg G2W_GIT_REF=<branch> --build-arg CACHEBUST=$(date +%s) docker/
```

O `Dockerfile` clona o fork no `G2W_GIT_REF` e compila o `.deb`; os demais arquivos
desta pasta entram na imagem de execução. O build leva vários minutos: rodar com
`systemd-run`, não numa sessão SSH.

Variáveis do `entrypoint.sh` além das de banco:

| Variável | Padrão | Efeito |
|---|---|---|
| `LOG_ROTATE` | `1` | `0` desliga a rotação do `/var/log/bluecherry.log` |
| `LOG_ROTATE_SIZE` | `50M` | tamanho que dispara a rotação |
| `LOG_ROTATE_KEEP` | `7` | arquivos rotacionados mantidos |
| `LOG_ROTATE_INTERVAL` | `600` | segundos entre verificações |
| `BC_DB_TOOL_TRACE` | vazio | qualquer valor liga o `set -x` do `bc_db_tool.sh` (imprime senhas; só para depurar) |
