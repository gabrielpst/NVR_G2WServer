#!/bin/bash

echo '
-- Troca a fonte da lista de câmeras pra "Local list" (G2W Monitor, 2026-08-07).
-- A opção "Live" consulta a API da própria Bluecherry (cam-api.bluecherry.
-- workers.dev), exige internet e não é editável por nós. A lista local vem
-- embutida na imagem (cameras.db) e é onde cadastramos a Intelbras.
-- Só mexe se ainda estiver no valor padrão "live" — respeita quem já escolheu
-- outra coisa manualmente.
UPDATE GlobalSettings SET value = "local" WHERE parameter = "G_DATA_SOURCE" AND value = "live";
' | mysql -h"$host" -D"$dbname" -u"$user" -p"$password"

exit 0
