#!/bin/bash

echo '
-- Rebranding G2W Monitor (2026-08-07) — aplica pra quem já tinha banco criado
-- antes desse commit. Só toca em GlobalSettings que o usuário normalmente não
-- edita manualmente (nome do DVR, checagem de versão); não mexe em nada que
-- já tenha sido customizado por um admin (ex: G_SMTP_EMAIL_FROM, se já
-- configurado, fica como está).
UPDATE GlobalSettings SET value = "G2W Monitor" WHERE parameter = "G_DVR_NAME" AND value = "Bluecherry DVR v3";
UPDATE GlobalSettings SET value = "1" WHERE parameter = "G_DISABLE_VERSION_CHECK";
' | mysql -h"$host" -D"$dbname" -u"$user" -p"$password"

exit 0
