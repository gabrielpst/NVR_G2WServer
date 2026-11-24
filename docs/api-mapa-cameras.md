# Rota do mapa de câmeras: `GET /mapa/cameras`

Contrato para o aplicativo (e para qualquer tela nossa) ler onde fica cada câmera. Vale a partir da versão que trouxer a branch `mapa-2026-11`.

## Pedido

```
GET /mapa/cameras
GET /mapa/cameras?localizadas=1
```

- **Autenticação:** a mesma sessão do aplicativo. Faça `POST /ajax/loginapp.php` (campos `login` e `password`) e envie de volta o cookie `bluecherrydvr` que veio na resposta. Não há token próprio nem cabeçalho extra.
- **Parâmetro opcional `localizadas=1`:** devolve só as câmeras que têm localização cadastrada. Sem ele vêm todas as que o usuário pode ver.
- Não existe parâmetro para escolher câmera. Qualquer outro parâmetro é ignorado.

## Resposta `200`

`Content-Type: application/json; charset=utf-8`, `Cache-Control: no-store`.

```json
{
  "ok": true,
  "cameras": [
    {
      "id": 1,
      "nome": "Portaria principal",
      "localizada": true,
      "latitude": -20.401235,
      "longitude": -54.598765,
      "endereco": "Rua Exemplo, 100 - Portaria"
    },
    {
      "id": 5,
      "nome": "Lateral",
      "localizada": false,
      "latitude": null,
      "longitude": null,
      "endereco": ""
    }
  ]
}
```

| Campo | Tipo | Observação |
|---|---|---|
| `id` | inteiro | o mesmo id usado em `/media/hls?id=` e no ao vivo |
| `nome` | texto | nome da câmera |
| `localizada` | booleano | `false` = ainda sem localização; `latitude` e `longitude` vêm `null` |
| `latitude` | número ou `null` | graus decimais, WGS 84, de -90 a 90, até 6 casas |
| `longitude` | número ou `null` | graus decimais, WGS 84, de -180 a 180, até 6 casas |
| `endereco` | texto | livre, até 200 caracteres, pode vir vazio. **É texto puro**: mostrar como texto, nunca interpretar como HTML |

A lista vem em ordem de nome e pode vir vazia (`"cameras": []`).

## O que entra na lista

- Só câmeras **ligadas** e **liberadas para o usuário logado**. A conferência é feita no servidor, câmera por câmera.
- Câmera bloqueada para o usuário não aparece, nem com coordenada vazia: para ele é como se não existisse.

## Erros

| Código | Corpo | Quando |
|---|---|---|
| `401` | `{"ok":false,"erro":"Sessão ausente ou expirada. Entre de novo."}` | sem cookie de sessão válido. Refazer o login |
| `405` | `{"ok":false,"erro":"Esta rota só aceita leitura (GET)."}` | qualquer `POST` |
| `302` (para `/liveview` ou `/login`) | sem JSON | a sessão foi derrubada pelo administrador (senha redefinida, usuário desativado). O pedido seguinte já responde `401`. Não seguir o redirecionamento; tratar como `401` |
| `5xx` | pode não ser JSON | falha do servidor ou do banco. Tentar de novo mais tarde |

## Cuidados para quem consome (LGPD)

- Onde a câmera fica é dado sensível. **Não guardar a resposta em disco** no aparelho; pedir de novo ao abrir o mapa.
- A permissão pode mudar a qualquer momento: uma câmera que veio agora pode não vir no próximo pedido. Remover do mapa o que deixou de vir.
- Não enviar coordenada nem endereço para serviço de terceiros além do necessário para desenhar o mapa.

## Quem escreve esses dados

Só o administrador, pelo painel web (`/gestao/cameras`, botão "Definir" ou "Ajustar" em "Localização no mapa"). O aplicativo só lê. As rotas de escrita (`POST /gestao/camera-local`) e de busca de endereço (`GET /gestao/buscar-endereco`) são do painel, exigem administrador e o token da página, e não fazem parte deste contrato.

## Onde fica no banco

Tabela `G2WPainelLocais` (`device_id`, `latitude`, `longitude`, `endereco`, `atualizado_em`), criada sozinha no primeiro uso. Câmera sem linha nessa tabela é câmera sem localização. Nenhuma tabela do projeto original foi alterada.
