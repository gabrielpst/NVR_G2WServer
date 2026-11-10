# syntax=docker/dockerfile:1
#
# G2W Monitor Server — build própria do NVR_G2WServer (fork de bluecherrydvr/bluecherry-apps)
#
# Estágio 1 compila o .deb a partir do próprio código-fonte do fork (clone git, não baixa
# pacote de terceiro). Estágio 2 é a imagem de execução: só o necessário pra rodar.
#
# Ubuntu 24.04 (noble) nos dois estágios — não 20.04/focal. Confirmado batendo o
# `/etc/os-release` do container real de referência (bluecherrydvr/bluecherry:dev-ci,
# rodando na LXC 104 do pvedom): é Noble, não Focal (PHP 8.3 nativo, sem PPA). Misturar
# build focal + runtime noble seria arriscado — dpkg-shlibdeps calcula as dependências
# de biblioteca compartilhada do .deb com base nos nomes/versões de pacote do ambiente
# de BUILD, que não batem com os do runtime se forem distros diferentes.

# ---------------------------------------------------------------------------
# Estágio de build
# ---------------------------------------------------------------------------
FROM ubuntu:24.04 AS build
ARG DEBIAN_FRONTEND=noninteractive
ARG G2W_GIT_URL=https://github.com/gabrielpst/NVR_G2WServer.git
ARG G2W_GIT_REF=master
ARG DIST=noble

# Nota: NÃO usa o repositório apt oficial do MySQL (mysql.com) — a chave GPG deles
# rotacionou e o `apt-key add` do RPM-GPG-KEY-mysql-2022 não bate mais com o pacote
# publicado (erro real de build: NO_PUBKEY B7B3B788A8D3785C). O Ubuntu já traz
# libmysqlclient-dev nativo no universe, suficiente pra compilar contra a API do
# MySQL 8 do servidor — não precisamos do repositório externo pra isso.
RUN apt-get update && apt-get install -y --no-install-recommends \
      ca-certificates gnupg wget git \
    && apt-get install -y --no-install-recommends \
      autoconf automake libtool build-essential gcc g++ debhelper ccache \
      bison flex texinfo yasm cmake libbsd-dev libopencv-dev libudev-dev libva-dev \
      rsyslog sudo gsoap libgsoap-dev libmysqlclient-dev libidn11-dev libcurl4-openssl-dev \
      cdbs devscripts equivs fakeroot \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /build
# CACHEBUST: o texto desse RUN (git clone) nunca muda entre commits novos no
# fork — sem isso, o cache de camada do Docker reusa o clone antigo e a
# imagem final não reflete commits novos (aconteceu de verdade: um build com
# EXIT_CODE=0 sem nenhum erro, mas 100% cache hit, gerou .deb da versão
# ANTIGA do código). Passar --build-arg CACHEBUST=$(date +%s) força reclonar.
ARG CACHEBUST=1
# --shallow-submodules falha aqui: pelo menos um submódulo (misc/ponvif_ext) está
# fixado num commit fora da ponta do branch remoto, e o host recusa servir esse SHA
# via fetch raso ("not our ref"). Clona raso só o repositório principal; submódulos
# são buscados por completo (mais lento, mas confiável).
RUN git clone --depth 1 --branch "${G2W_GIT_REF}" "${G2W_GIT_URL}" source
WORKDIR /build/source
# O índice do git tem gitlinks órfãos pra misc/libconfig e misc/pugixml, mas as
# entradas correspondentes no .gitmodules estão comentadas de propósito no
# upstream (vendorizados de outro jeito). Além disso o gitlink de misc/ponvif_ext
# aponta pra um commit que não existe mais no repositório remoto (branch
# reescrito/apagado no upstream, bug deles) — mas esse submódulo não é referenciado
# em nenhuma regra de build (confirmado por grep em Makefile/BCMK/scripts), então
# é seguro pular. Só misc/libav e misc/ponvif entram de fato na compilação.
RUN git submodule update --init --recursive -- misc/libav misc/ponvif

# misc/libconfig e misc/pugixml SÃO exigidos pela build (regra misc/BCMK:19), mas
# o gitlink pinado nesse commit do upstream aponta pra um SHA que não existe mais
# em nenhum dos dois repositórios reais (branch reescrito/apagado — confirmado com
# git cat-file -e contra hyperrealm/libconfig e zeux/pugixml). Sem submódulo ativo
# no .gitmodules e sem o commit original pra buscar, a fonte viável é a atual dos
# projetos — mas o master atual de cada um quebra na regra do BCMK, escrita pra
# um layout antigo de cada projeto:
#   - libconfig master: configure.ac usa AC_CHECK_INCLUDES_DEFAULT, macro do
#     autoconf mais nova que a 2.69 do Ubuntu 20.04. Uso a tag v1.7.3 (mesma
#     versão major.minor do pacote apt nativo libconfig++-dev 1.7.3-2.1) — lib
#     madura, autoconf mais novo do noble lê configure.ac antigo sem problema
#     (é o sentido contrário que quebra, não este).
#   - pugixml master: moveu o CMakeLists.txt de scripts/ pra raiz do repo faz
#     anos; a regra do BCMK (misc/BCMK:161) roda `cmake ... scripts/`, esperando
#     o layout antigo. Uso a tag v1.7 — a última antes dessa reorganização.
RUN rm -rf misc/libconfig misc/pugixml \
    && git clone --depth 1 --branch v1.7.3 https://github.com/hyperrealm/libconfig.git misc/libconfig \
    && git clone --depth 1 --branch v1.7 https://github.com/zeux/pugixml.git misc/pugixml

RUN git config --global --add safe.directory /build/source \
    && git config --global --add safe.directory /build/source/misc/libav

# server/version.h não é versionado — normalmente é gerado pelo script oficial
# scripts/build_pkg_native.sh (que a gente não usa, por depender de headers de
# kernel do host) antes de chamar debian/rules. Sem esse passo, bc-server.cpp
# não compila ("version.h: No such file or directory"). Reproduz exatamente a
# mesma linha do script oficial. --always faz git describe cair pro hash curto
# do commit se não achar tag alcançável (nosso clone raso não tem tags) — não
# falha como o `git describe` sem --always usado só pro cache de contrib.
RUN echo "#define GIT_REVISION \"$(git describe --dirty --always --long) $(git describe --all)\"" > server/version.h

# Gera o control real a partir do template pra distro alvo, compila o .deb
RUN DIST=${DIST} debian/rules debian/control \
    && DIST=${DIST} debian/rules clean binary \
    && mkdir -p /build/releases \
    && mv -v ../bluecherry*.deb /build/releases/

# ---------------------------------------------------------------------------
# Estágio de execução — reproduz o stack real (nginx + php-fpm + bc-server)
# ---------------------------------------------------------------------------
FROM ubuntu:24.04 AS runtime
ARG DEBIAN_FRONTEND=noninteractive

# Mesma decisão do estágio de build: sem repositório oficial do MySQL (chave GPG
# quebrada). mysql-client nativo do Ubuntu já fala com o MySQL 8 do bc-mysql.
#
# PHP 8.3 já é nativo no Ubuntu 24.04 (era o motivo de precisar do PPA ondrej/php
# no 20.04 — não precisa mais agora que o base image é noble).
# libopencv-core406t64 etc: sufixo "t64" é da transição de time_t 64-bit do
# noble (confirmado via apt-cache search direto num container ubuntu:24.04 —
# não existe mais libopencv-core4.2/4.5d como no 20.04).
#
# sudo é obrigatório aqui: o postinst do .deb (script oficial da bluecherry)
# faz `install ... /etc/sudoers.d/www-data`, e é o pacote sudo quem cria o
# diretório /etc/sudoers.d/ — sem ele o dpkg --configure falha (erro real:
# "cannot create regular file '/etc/sudoers.d/www-data': No such file or
# directory").
RUN apt-get update && apt-get install -y --no-install-recommends \
      ca-certificates wget gnupg \
    && apt-get install -y --no-install-recommends \
      nginx php8.3-fpm php8.3-mysql php8.3-gd php8.3-curl php8.3-mbstring \
      mysql-client rsyslog sudo gsoap dnsutils libopencv-core406t64 libopencv-imgproc406t64 \
      libopencv-videoio406t64 libva2 libudev1 \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=build /build/releases/bluecherry*.deb /tmp/
# O postinst do .deb (script oficial da bluecherry, debian/bluecherry.postinst
# → misc/postinstall.sh) cria o banco de fato durante a instalação do pacote,
# via debconf (usa os defaults do template: mysql_admin_login=root,
# db_host=localhost) + `bc_db_tool.sh new_db`, que precisa de um MySQL
# alcançável NESSE EXATO MOMENTO (erro real sem isso: postinst sai com
# "exit 1" ao tentar conectar). Isso é só formalidade do dpkg — o entrypoint.sh
# real (extraído do container de referência) REESCREVE /etc/bluecherry.conf do
# zero a cada start do container e chama bc-database-create/-upgrade direto
# contra o MySQL de produção (serviço companheiro via BLUECHERRY_DB_HOST) — ou
# seja, o banco criado aqui é 100% descartável, só serve pra passar o
# instalador. Sobe um MySQL local temporário só durante essa RUN (sem depender
# de systemd/service — bloqueado por policy-rc.d nas imagens Docker oficiais
# do Ubuntu, daemon é iniciado direto via mysqld_safe) e remove tudo no fim da
# mesma RUN, então não sobra rastro na imagem final.
# Mesmo raciocínio da parte do MySQL: o fim do postinst chama
# `update-alternatives --install /run/php/php-fpm.sock ... /run/php/php8.3-fpm.sock`,
# mas esse socket só existe quando o php-fpm está DE FATO rodando — o que só
# acontece no entrypoint.sh, não durante o build (erro real: "alternative path
# ... doesn't exist"). /run é tmpfs — some a cada restart do container — então
# criar um arquivo vazio nesse caminho só pra passar o update-alternatives
# aqui é seguro: não sobra rastro no runtime real, e o próprio entrypoint.sh já
# refaz esse symlink apontando pro socket de verdade quando sobe o php-fpm.
RUN apt-get update \
    && apt-get install -y --no-install-recommends mysql-server \
    && mkdir -p /var/run/mysqld && chown mysql:mysql /var/run/mysqld \
    && (mysqld_safe --skip-syslog &) \
    && for i in $(seq 1 30); do mysqladmin ping >/dev/null 2>&1 && break; sleep 1; done \
    && mkdir -p /run/php && touch /run/php/php8.3-fpm.sock \
    && apt-get install -y --no-install-recommends /tmp/bluecherry*.deb \
    && mysqladmin shutdown \
    && apt-get purge -y mysql-server mysql-server-8.0 mysql-server-core-8.0 \
    && apt-get autoremove -y \
    && rm -rf /var/lib/mysql /var/log/mysql* /tmp/bluecherry*.deb \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# www-data precisa conseguir ler as gravações gravadas pelo usuário bluecherry
# (achado da bancada de teste do cliente, 2026-08 — sem isso o painel dá 404
# em toda mídia)
RUN usermod -aG bluecherry www-data

COPY entrypoint.sh /entrypoint.sh
COPY nginx-bluecherry.conf /etc/nginx/sites-enabled/bluecherry.conf
# O entrypoint.sh (extraído do container de referência) chama /bin/bc-database-create
# e /bin/bc-database-upgrade com uma assinatura fixa de 4 argumentos, mas o .deb só
# instala o script real (/usr/share/bluecherry/bc_db_tool.sh, subcomandos new_db/
# upgrade_db, 7 e 4 argumentos respectivamente — confirmado lendo misc/sql/bc_db_tool.sh
# do fork). Esses dois binários não fazem parte do pacote — são wrappers finos que a
# imagem oficial deve prover por fora. Reproduz aqui (erro real sem isso: "/bin/
# bc-database-create: No such file or directory").
COPY bc-database-create /bin/bc-database-create
COPY bc-database-upgrade /bin/bc-database-upgrade
RUN chmod +x /entrypoint.sh /bin/bc-database-create /bin/bc-database-upgrade \
    && rm -f /etc/nginx/sites-enabled/default

EXPOSE 7001/tcp 7002/tcp 7003/tcp 7005/tcp 554/tcp 554/udp

ENTRYPOINT ["/entrypoint.sh"]
