# NEXUS

Sistema PHP/MySQL para controle de ativos, estoque, compras, movimentacoes e requisicoes de usuarios.

## Recursos

- Login unificado para administrador e usuario.
- Interface NEXUS minimalista e corporativa, com barra superior fixa, conteudo centralizado, paleta neutra e modais padronizados.
- Dashboard administrativo com indicadores de ativos, estoque critico e requisicoes por status.
- Cadastro de categorias com pesquisa em tempo real, edicao e exclusao.
- Cadastro de ativos em modal, com foto, estoque minimo, historico e QR Code individual.
- Modulo de estoque com pesquisa por codigo, nome ou descricao.
- Compras com dados de nota fiscal, multiplos itens, anexo PDF/XML e atualizacao automatica do estoque.
- Movimentacoes em modal com colaborador que recebe, funcionario que entrega e data/hora automatica.
- Historico completo por ativo com entradas, saidas, compras e entregas.
- Requisicoes integradas entre usuario e administrador.
- Tabelas com pesquisa em tempo real, paginacao e layout responsivo.

## Banco de dados

O arquivo `inventario/database.sql` cria a base `mcx_inventario` com:

- `usuarios`
- `categorias`
- `ativos`
- `compras`
- `compra_itens`
- `movimentacoes`
- `historico_ativos`
- `anexos_nf`
- `requisicoes`
- `purchase_requests`
- `purchase_request_audit`
- `inventory_sessions`, `inventory_balances` e `inventory_scans`

O campo `quantidade_atual` em `ativos` substitui a quantidade manual. Ele e controlado pelas compras e movimentacoes.

## Instalar no XAMPP

1. Coloque a pasta `NEXUS` em `C:\xampp\htdocs\NEXUS`.
2. Abra o phpMyAdmin.
3. Importe somente `inventario/database.sql`.
4. Acesse `http://localhost/NEXUS/inventario`.

## Modulos v3

- Solicitacoes de compra: Administrador -> Coordenacao TI -> Financeiro, com trilha de auditoria e registro somente leitura apos a compra.
- Gestor com acesso operacional restrito a categorias de Limpeza e Escritorio, sem acesso ao inventario ou a aprovacoes.
- Inventario com bloqueio operacional, leitura por QR/ID (inclusive leituras repetidas), saldos e relatorios.
- Perfil RH somente leitura, com visao de notebooks, desktops, monitores, headsets e docks.

Credenciais iniciais:

- Administrador: `admin@mcx.local` / `password`
- Usuario: `joao.silva@mcx.local` / `password`

Se o MySQL usar outra senha, edite `inventario/config.php`.

## QR Code

Cada ativo possui um QR Code que aponta para a pagina publica:

`index.php?page=qr&codigo=CODIGO_DO_ATIVO`

A imagem do QR Code usa um gerador gratuito. Se preferir `endroid/qr-code`, instale o Composer no projeto e substitua a URL externa por geracao local.
