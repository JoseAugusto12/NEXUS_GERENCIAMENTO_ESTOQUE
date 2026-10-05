# MCX Nexus Inventario

Aplicacao PHP 8+ e MySQL para gestao de ativos, estoque, compras, movimentacoes, requisicoes e historico.

## Modulos

- Painel com cards de ativos, estoque critico e requisicoes em analise, compra, negadas e concluidas.
- Estoque com pesquisa instantanea e compra em modal.
- Compras com nota fiscal, multiplos itens, anexo PDF/XML e atualizacao automatica de estoque.
- Categorias com pesquisa, edicao e exclusao.
- Ativos com foto, pesquisa por codigo/nome/categoria, historico e QR Code.
- Movimentacoes com colaborador que recebe, funcionario que entrega e horario automatico.
- Requisicoes com fluxo usuario/administrador integrado.
- Solicitacoes de compra: Administrador -> Coordenacao TI -> Financeiro, com auditoria e bloqueio de alteracao apos a compra.
- Gestor com o mesmo acesso operacional do Administrador, exceto Inventario e fluxo de aprovacao, limitado a Limpeza e Escritorio.
- Inventario com leituras repetidas permitidas.
- Login unificado usando o campo `perfil` da tabela `usuarios`.
- Identidade visual alinhada ao Consult/Grupo MCX, com topo horizontal fixo, breadcrumb, tabelas turquesa, botoes por cor de acao e modais padronizados.

## Banco

Importe `database.sql` no phpMyAdmin. O script recria a base `mcx_inventario` e inclui relacionamentos, chaves estrangeiras e indices.

Tabelas principais:

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
- `inventory_sessions`, `inventory_balances`, `inventory_scans`

O estoque fica em `ativos.quantidade_atual` e nao deve ser alterado manualmente no cadastro do ativo.

## Acesso

URL local padrao:

`http://localhost/NEXUS/inventario`

Credenciais:

- Administrador: `admin@mcx.local` / `password`
- Usuario: `joao.silva@mcx.local` / `password`

## Configuracao

Edite `config.php` se o host, banco, usuario, senha ou `base_url` forem diferentes.
