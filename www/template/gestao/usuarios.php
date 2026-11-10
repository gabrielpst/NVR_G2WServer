<?php defined('INDVR') or exit();
$h = array('G2WGestao', 'h');
$json = json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<h1 class="titulo-pagina">Usuários</h1>
<p class="intro">Quem pode entrar no aplicativo e no site, e quais câmeras cada um vê.</p>

<script type="application/json" id="dados-usuarios"><?php echo $json; ?></script>

<section class="bloco" aria-label="Lista de usuários">
    <div class="barra-ferramentas">
        <div class="campo campo-busca">
            <label for="busca" class="sr">Buscar por login ou nome</label>
            <input id="busca" name="busca" type="search" placeholder="Buscar por login ou nome…" autocomplete="off" spellcheck="false">
        </div>
        <div class="grupo-botoes">
            <button type="button" class="botao botao-principal" data-abrir="dlg-usuario" data-novo>Novo usuário</button>
            <button type="button" class="botao" data-abrir="dlg-lote">Criar vários</button>
        </div>
    </div>

    <div class="filtros" role="group" aria-label="Filtrar usuários">
        <button type="button" class="filtro" data-filtro="todos" aria-pressed="true">Todos <span data-conta="todos"></span></button>
        <button type="button" class="filtro" data-filtro="trocar" aria-pressed="false">Sem senha própria <span data-conta="trocar"></span></button>
        <button type="button" class="filtro" data-filtro="nunca" aria-pressed="false">Sem acesso registrado <span data-conta="nunca"></span></button>
        <button type="button" class="filtro" data-filtro="desativados" aria-pressed="false">Desativados <span data-conta="desativados"></span></button>
        <button type="button" class="filtro" data-filtro="admins" aria-pressed="false">Administradores <span data-conta="admins"></span></button>
    </div>
    <p class="nota" id="nota-acessos"></p>

    <div class="selecao" id="barra-selecao" hidden>
        <strong id="selecao-conta" aria-live="polite"></strong>
        <div class="grupo-botoes">
            <button type="button" class="botao" data-acao="exigir_troca">Exigir nova senha</button>
            <button type="button" class="botao" data-acao="dispensar_troca">Dispensar troca</button>
            <button type="button" class="botao" data-abrir="dlg-senha">Redefinir senha</button>
            <button type="button" class="botao" data-abrir="dlg-cameras">Câmeras</button>
            <button type="button" class="botao" data-acao="ativar">Ativar</button>
            <button type="button" class="botao botao-perigo" data-acao="desativar">Desativar</button>
            <button type="button" class="botao botao-leve" id="limpar-selecao">Limpar seleção</button>
        </div>
    </div>

    <div class="tabela-rolagem">
        <table class="tabela" id="tabela-usuarios">
            <caption class="sr">Usuários do sistema</caption>
            <thead>
                <tr>
                    <th scope="col" class="col-marca"><input type="checkbox" id="marcar-todos" aria-label="Selecionar todos os usuários da lista"></th>
                    <th scope="col">Login</th>
                    <th scope="col">Nome</th>
                    <th scope="col">Situação</th>
                    <th scope="col">Último acesso</th>
                    <th scope="col">Câmeras</th>
                    <th scope="col"><span class="sr">Ações</span></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
    <p class="vazio" id="lista-vazia" hidden>Nenhum usuário encontrado com esse filtro.</p>
    <p class="nota" id="lista-total" aria-live="polite"></p>
</section>

<dialog id="dlg-usuario" class="dialogo" aria-labelledby="dlg-usuario-t">
    <form method="dialog" data-form-usuario autocomplete="off" novalidate>
        <h2 id="dlg-usuario-t">Usuário</h2>
        <input type="hidden" name="id">
        <div class="campos-grade">
            <div class="campo">
                <label for="u-login">Login</label>
                <input id="u-login" name="login" type="text" required maxlength="40" spellcheck="false" autocapitalize="off" autocomplete="off">
            </div>
            <div class="campo">
                <label for="u-nome">Nome</label>
                <input id="u-nome" name="nome" type="text" maxlength="60" autocomplete="off" placeholder="Ex.: Casa 12…">
            </div>
            <div class="campo">
                <label for="u-senha">Senha</label>
                <input id="u-senha" name="senha" type="password" maxlength="64" autocomplete="new-password" aria-describedby="u-senha-ajuda">
                <small id="u-senha-ajuda"></small>
            </div>
            <div class="campo">
                <label for="u-tel">Telefone</label>
                <input id="u-tel" name="telefone" type="tel" maxlength="15" autocomplete="off" inputmode="tel">
            </div>
            <div class="campo campo-largo">
                <label for="u-email">E-mail</label>
                <input id="u-email" name="email" type="email" maxlength="60" autocomplete="off" spellcheck="false">
            </div>
            <div class="campo campo-largo">
                <label for="u-obs">Observação</label>
                <input id="u-obs" name="obs" type="text" maxlength="255" autocomplete="off">
            </div>
        </div>
        <fieldset class="opcoes">
            <legend>O que pode fazer</legend>
            <label class="opcao"><input type="checkbox" name="app"> Entrar pelo aplicativo</label>
            <label class="opcao"><input type="checkbox" name="site"> Entrar pelo site</label>
            <label class="opcao"><input type="checkbox" name="gravacoes"> Ver gravações</label>
            <label class="opcao"><input type="checkbox" name="trocar_senha"> Criar uma senha nova no próximo acesso</label>
            <label class="opcao"><input type="checkbox" name="admin"> Administrador (acesso total a este painel)</label>
        </fieldset>
        <fieldset class="opcoes" data-cameras>
            <legend>Câmeras que pode ver</legend>
            <div class="lista-cameras"></div>
            <small>Câmera nova entra liberada para todos — volte aqui se for preciso restringir.</small>
        </fieldset>
        <p class="erro-form" role="alert" hidden></p>
        <div class="dialogo-acoes">
            <button type="button" class="botao" data-fechar>Cancelar</button>
            <button type="submit" class="botao botao-principal">Salvar</button>
        </div>
    </form>
</dialog>

<dialog id="dlg-lote" class="dialogo" aria-labelledby="dlg-lote-t">
    <form method="dialog" data-form-lote autocomplete="off" novalidate>
        <h2 id="dlg-lote-t">Criar vários usuários</h2>
        <p class="intro">Cria uma sequência de logins, por exemplo <strong>casa1</strong> a <strong>casa176</strong>. Logins que já existem não são alterados.</p>
        <div class="campos-grade">
            <div class="campo">
                <label for="l-prefixo">Prefixo do login</label>
                <input id="l-prefixo" name="prefixo" type="text" required maxlength="30" spellcheck="false" autocapitalize="off" autocomplete="off" placeholder="Ex.: casa…">
            </div>
            <div class="campo">
                <label for="l-nome">Nome (opcional)</label>
                <input id="l-nome" name="nome_base" type="text" maxlength="50" autocomplete="off" placeholder="Ex.: Casa…">
            </div>
            <div class="campo">
                <label for="l-de">Do número</label>
                <input id="l-de" name="de" type="number" inputmode="numeric" min="0" max="99999" required value="1">
            </div>
            <div class="campo">
                <label for="l-ate">Até o número</label>
                <input id="l-ate" name="ate" type="number" inputmode="numeric" min="0" max="99999" required>
            </div>
            <div class="campo campo-largo">
                <label for="l-senha">Senha inicial (igual para todos)</label>
                <input id="l-senha" name="senha" type="text" maxlength="64" autocomplete="off" spellcheck="false" required aria-describedby="l-senha-ajuda">
                <small id="l-senha-ajuda">Mínimo de <?php echo G2WGestao::SENHA_MIN; ?> caracteres. Com a opção abaixo marcada, cada pessoa cria a própria senha no primeiro acesso.</small>
            </div>
        </div>
        <p class="previa" id="l-previa" aria-live="polite"></p>
        <fieldset class="opcoes">
            <legend>O que podem fazer</legend>
            <label class="opcao"><input type="checkbox" name="trocar_senha" checked> Criar uma senha nova no primeiro acesso</label>
            <label class="opcao"><input type="checkbox" name="app" checked> Entrar pelo aplicativo</label>
            <label class="opcao"><input type="checkbox" name="site" checked> Entrar pelo site</label>
            <label class="opcao"><input type="checkbox" name="gravacoes"> Ver gravações</label>
        </fieldset>
        <fieldset class="opcoes" data-cameras>
            <legend>Câmeras que podem ver</legend>
            <div class="lista-cameras"></div>
        </fieldset>
        <p class="erro-form" role="alert" hidden></p>
        <div class="dialogo-acoes">
            <button type="button" class="botao" data-fechar>Cancelar</button>
            <button type="submit" class="botao botao-principal">Criar usuários</button>
        </div>
    </form>
</dialog>

<dialog id="dlg-senha" class="dialogo" aria-labelledby="dlg-senha-t">
    <form method="dialog" data-form-senha autocomplete="off" novalidate>
        <h2 id="dlg-senha-t">Redefinir senha</h2>
        <p class="intro" data-alvo></p>
        <div class="campo">
            <label for="s-senha">Nova senha</label>
            <input id="s-senha" name="senha" type="text" maxlength="64" autocomplete="off" spellcheck="false" required>
            <small>Mínimo de <?php echo G2WGestao::SENHA_MIN; ?> caracteres. Quem estiver conectado será desconectado.</small>
        </div>
        <label class="opcao"><input type="checkbox" name="trocar_senha" checked> Pedir que cada um crie a própria senha no próximo acesso</label>
        <p class="erro-form" role="alert" hidden></p>
        <div class="dialogo-acoes">
            <button type="button" class="botao" data-fechar>Cancelar</button>
            <button type="submit" class="botao botao-principal">Redefinir senha</button>
        </div>
    </form>
</dialog>

<dialog id="dlg-cameras" class="dialogo" aria-labelledby="dlg-cameras-t">
    <form method="dialog" data-form-cameras autocomplete="off" novalidate>
        <h2 id="dlg-cameras-t">Câmeras que podem ver</h2>
        <p class="intro" data-alvo></p>
        <fieldset class="opcoes" data-cameras>
            <legend class="sr">Câmeras</legend>
            <div class="lista-cameras"></div>
            <small>O que for marcado aqui substitui a escolha atual de cada usuário selecionado.</small>
        </fieldset>
        <p class="erro-form" role="alert" hidden></p>
        <div class="dialogo-acoes">
            <button type="button" class="botao" data-fechar>Cancelar</button>
            <button type="submit" class="botao botao-principal">Aplicar</button>
        </div>
    </form>
</dialog>
