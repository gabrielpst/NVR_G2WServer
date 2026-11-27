# Grupos de câmeras: desenho e migração

Estado: **proposta** (27/11/2026), antes de qualquer código. Vale a partir da versão que trouxer a branch `proxima-2026-11`.

## O que muda para quem usa

- Uma câmera pode estar em nenhum, um ou vários grupos.
- No cadastro do usuário comum, o administrador marca **grupos** e, além deles, **câmeras avulsas**. O usuário vê a soma das duas coisas.
- **Câmera nova só aparece para quem tem o grupo dela ou a recebeu avulsa.** Hoje é o contrário: câmera nova aparece para todos que não a tenham bloqueada.
- Pôr uma câmera num grupo (ou tirar) vale na hora para os usuários do grupo: no painel e no aplicativo, no pedido seguinte; no vídeo ao vivo já aberto, em até 15 segundos (cache de chave do motor).
- Administrador continua vendo tudo; grupos não se aplicam a ele.
- Sem o limite atual de 255 caracteres na lista de câmeras de um usuário.

## O problema de compatibilidade

`Users.access_device_list` é uma lista de **bloqueio** (ids separados por vírgula, `varchar(255)`) e é lida por:

| Quem | Onde | Para quê |
|---|---|---|
| Motor (C++) | `lib/bc-utils.cpp` `bc_user_auth` | RTSP/HTTP do motor por usuário e senha |
| Motor (C++) | `server/hls.cpp` | chave do ao vivo (`?authtoken=`), cache de 15 s |
| Painel (PHP) | `user::camPermission()` | `/devices.php`, `/aovivo`, `/gravacoes`, `/mapa`, `/media/…`, `playback` |
| Painel (PHP) | `ajax/events/eventsIndex.php`, `ajax/liveview.php` | filtro de eventos e ao vivo antigo |
| App e desktop | indiretamente, pelo que as rotas acima devolvem | — |

Com bloqueio, câmera desconhecida = liberada. A regra nova pede o contrário.

## Desenho proposto: tabelas do painel como fonte da verdade, `access_device_list` recalculada

Nada muda no motor, no app nem no desktop. O painel guarda as permissões em tabelas próprias e **reescreve** a lista de bloqueio de cada usuário comum sempre que algo muda. Quem lê `access_device_list` continua lendo uma lista de bloqueio correta.

Tabelas novas (prefixo do painel, criadas pela migração):

```
G2WGrupos          (id PK, nome VARCHAR(60) UNIQUE, criado_em INT)
G2WGrupoCameras    (grupo_id, device_id)   PK (grupo_id, device_id)
G2WUsuarioGrupos   (user_id, grupo_id)     PK (user_id, grupo_id)
G2WUsuarioCameras  (user_id, device_id)    PK (user_id, device_id)   -- avulsas
G2WGruposAntes     (user_id PK, access_device_list TEXT, migrado_em INT)  -- cópia para a volta
```

Mais `GlobalSettings.G2W_PERMISSAO_GRUPOS = 1` quando a instalação foi migrada. Sem essa marca o painel funciona exatamente como hoje (lista de bloqueio editada direto).

Regra, para usuário comum `u`:

```
permitidas(u) = avulsas(u) ∪ câmeras dos grupos(u)
bloqueio(u)   = todos os ids de Devices − permitidas(u)
```

`todos os ids de Devices` inclui desativadas e as removidas pelo painel que ainda guardam gravação (o PHP já as nega a usuário comum; no bloqueio elas também ficam fechadas para o motor).

Recalcular (`G2WGestao::recalcularBloqueios([ids])`, uma transação) sempre que:

- uma câmera é criada (painel, importador e o cadastro antigo `/ajax/addip.php`, que chama `lib.php` → `camera::create`) ou excluída;
- um grupo ganha/perde câmera, é apagado;
- um usuário é criado (painel novo, importador **e o cadastro antigo** do modo avançado, `lib.php` `user::` → `INSERT INTO Users` sem a coluna, que nasce `''` = vê tudo) ou tem grupos/avulsas alterados (individual ou em lote).

Rede de proteção para câmera **ou usuário** criado por um caminho que não recalcula (ex.: direto no banco): o painel guarda `G2W_GRUPOS_VISTO` = maior `Devices.id` e maior `Users.id` já recalculados; em toda rota autenticada uma consulta (`SELECT MAX(id)` das duas tabelas) compara e, se houver id maior, recalcula antes de responder. Custo: uma consulta por pedido. Câmera criada por fora e nunca vista pelo painel continuaria aberta só no motor (RTSP com usuário e senha) até o próximo pedido ao painel; documentado como limitação.

Lista vazia continua significando "vê tudo" para quem a lê (motor, PHP). Por isso usuário comum sem nenhuma câmera recebe a lista de **todos** os ids, nunca vazia.

Coluna: `ALTER TABLE Users MODIFY access_device_list TEXT` (64 KB, milhares de ids). O motor lê como texto pelo cliente MySQL; `NOT IN (…)` dos eventos aceita qualquer tamanho. Feito pela migração, não pelo pedido web.

### Alternativa descartada: mudar o motor para lista de permitidas

Fecharia também o caminho RTSP direto, mas exige mudar `bc-utils.cpp`, `hls.cpp`, o painel antigo, e versões antigas do motor passariam a liberar tudo. Mais risco para pouco ganho; o caminho RTSP com senha de usuário não é usado pelo app.

## Telas

- **Câmeras** (`/gestao/cameras`): no cartão, os grupos da câmera, editáveis; na tela "Adicionar câmera", marcar grupos (nenhum marcado por padrão, conforme a regra) e aviso "sem grupo, só administradores verão".
- **Grupos** (nova, `/gestao/grupos`): criar, renomear, apagar; marcar câmeras de cada grupo; ver quantos usuários têm o grupo.
- **Usuários** (`/gestao/usuarios`): "Grupos" (marcar) + "Câmeras avulsas" (a lista que hoje se chama "Pode ver"); mostra "vê N câmeras" e avisa quando é zero. Ação em lote "Definir câmeras" passa a definir grupos e avulsas.
- Editor antigo de permissões (`/ajax/cameraperms.php` → `update.php`): com a instalação migrada, recusa e aponta para `/gestao/usuarios` (senão desfaria o cálculo).
- `tools/importar-usuarios.php`: com a instalação migrada, aceita `--grupo=<nome>`; sem ele, avisa que os usuários criados não verão câmera nenhuma.

## Migração (`tools/grupos-cameras.php`)

```
php grupos-cameras.php --simular      # só relata, não grava nada
php grupos-cameras.php --migrar       # liga os grupos
php grupos-cameras.php --conferir     # compara o que cada usuário vê com a cópia da migração
php grupos-cameras.php --desfazer     # volta ao modo antigo
```

`--migrar`, nesta ordem:

1. Recusa se já migrada.
2. `ALTER` da coluna para `TEXT` (DDL, fora da transação; idempotente).
3. Cria as tabelas; numa transação, com as linhas de `Users` travadas (`SELECT … FOR UPDATE`) para nenhum pedido mexer nas listas no meio: copia `access_device_list` de todos os usuários para `G2WGruposAntes`; para cada usuário comum calcula o que ele vê hoje (`todas as câmeras não removidas − bloqueio`) e grava conforme a **opção escolhida** (abaixo); recalcula; **confere** que, para todo usuário, o conjunto de câmeras visíveis é idêntico ao de antes; se uma diferir, desfaz a transação e para sem marcar nada.
4. Marca `G2W_PERMISSAO_GRUPOS = 1`.

Administradores: nada é gravado para eles; a lista deles não é tocada.

**Escolha para o Gabriel — como os usuários entram nos grupos:**

- **A (recomendada):** cria um grupo **"Todas as câmeras"** com todas as câmeras atuais. Quem vê todas hoje recebe esse grupo; quem tem bloqueio recebe as câmeras que vê como avulsas. Na K Soluções os 113 usuários que veem as 53 ficam com o grupo; ao cadastrar uma câmera nova, marcar "Todas as câmeras" já a entrega a todos eles. No Silvestre4, quem tem câmera bloqueada fica com avulsas.
- **B:** tudo vira avulsa, sem grupo. O que se vê também não muda, mas câmera nova exigiria marcar usuário por usuário (ou criar um grupo depois e redistribuir).

Em ambas o que cada usuário vê no dia da migração é o mesmo de antes (conferido pelo próprio roteiro).

Recomendado rodar fora do horário de uso; ainda assim a trava cobre um administrador salvando usuário ao mesmo tempo.

`--desfazer`: tira a marca `G2W_PERMISSAO_GRUPOS`. A lista de bloqueio fica a última calculada, ou seja, cada um continua vendo o que via no modo de grupos; a partir daí volta a regra antiga (câmera nova aparece para todos). Opção `--restaurar-copia` devolve as listas guardadas no dia da migração (atenção: câmera criada depois da migração não está na cópia e, pela regra antiga, aparece para todos). As tabelas ficam (apagar só com `--apagar-tabelas`). A coluna fica `TEXT` (o código antigo funciona igual); `--coluna-original` a devolve a `varchar(255)` só se todas as listas couberem.

Voltar a imagem do servidor (rollback) sem rodar `--desfazer`: o código antigo ignora as tabelas e a marca e não mexe no tipo da coluna (continua `TEXT`, sem corte); cada usuário vê o que via (a lista recalculada continua lá); câmera nova volta a aparecer para todos. O painel antigo recusa salvar lista com mais de 255 caracteres (erro na tela, não abre nada).

## Segurança e LGPD

- Toda ação nova só para administrador, com o token CSRF do painel, conferido no backend; ids de câmera e de grupo cruzados com o que existe; nome do grupo validado (1 a 60 caracteres, sem controle) e escapado na tela.
- A permissão continua aplicada num lugar só (a lista de bloqueio), lida pelo PHP e pelo motor; as tabelas não são consultadas para autorizar, só para calcular.
- Nenhum dado pessoal novo: grupos e vínculos usuário↔câmera.
