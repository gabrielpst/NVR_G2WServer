#!/bin/bash

echo '
-- Retenção por câmera (G2W Monitor) — max_age_days
-- NULL = comportamento padrão (limpeza global por espaço em disco, não muda
-- nada pra quem já tem câmeras cadastradas). Valor definido = essa câmera
-- perde gravações mais velhas que N dias, independente do espaço em disco
-- disponível (ex: câmera A retém 3 dias, câmera B retém 7).
ALTER TABLE Devices ADD COLUMN max_age_days SMALLINT UNSIGNED DEFAULT NULL;
' | mysql -h"$host" -D"$dbname" -u"$user" -p"$password"

exit 0
