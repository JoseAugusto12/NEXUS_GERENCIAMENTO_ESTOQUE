# NEXUS

## Addendum de Documentação Técnica

*Atualização de requisitos, casos de uso, fluxos, banco de dados, permissões e regras de negócio*

**Versão:** 2.0  
**Data:** Junho de 2026

**Documento base:** `README.md` (v1 - Inventário)

## Como usar este documento

Este addendum complementa a documentação original do projeto (`README.md`). Ele descreve apenas o que muda em relação à versão atual: requisitos novos, telas novas/alteradas, tabelas de banco impactadas, permissões e regras de negócio.

Informações já vigentes e não alteradas (ex.: instalação via XAMPP, estrutura geral de pastas) não são repetidas aqui.

---

# 1. Visão Geral da Mudança

O sistema se chama "NEXUS". O escopo é redefinido para cobrir exclusivamente a gestão de equipamentos e inventário de TI, com dois perfis de acesso, requisições de equipamento por parte dos usuários, histórico completo por equipamento (incluindo fotos), QR Code individual por equipamento e dashboard administrativo.

A interface passa a ser responsiva e a seguir a identidade visual NEXUS (minimalista, paleta neutra).

## 1.1 Resumo das mudanças por área

| Área | Mudança | Tipo |
|---|---|---|
| Interface | Responsividade total (desktop, tablet, celular) | Alterado |
| Identidade visual | CSS migrado para a identidade visual NEXUS | Alterado |
| Escopo do sistema | Restrito a equipamentos e inventário de TI; módulos não relacionados são removidos | Removido / Restringido |
| Autenticação | Dois perfis (Usuário e Administrador), cada um com tela de login própria | Novo |
| Requisições | Usuário abre e acompanha requisições de equipamento | Novo |
| Equipamentos | Campo de localização estruturado (Andar / Estação) | Novo campo |
| Equipamentos | Histórico completo por equipamento, com fotos de entrega e devolução | Novo |
| Dashboard | Indicadores restritos a equipamentos e requisições | Alterado |
| QR Code | Geração de QR Code individual por equipamento, baseado no código patrimonial | Novo |

## 1.2 Funcionalidades a remover

Qualquer módulo ou tela que não esteja diretamente relacionado ao controle de equipamentos e inventário de TI deve ser removido da versão atual e do protótipo de referência (`index.html`), incluindo:

- Cadastro e gestão de **"Colaboradores"** como entidade independente (presente no protótipo `index.html`) - passa a existir apenas como dado de **"Responsável"** dentro do histórico do equipamento e da requisição, não como um módulo de cadastro próprio.
- Qualquer indicador de dashboard não relacionado a equipamentos, estoque ou requisições.
- Exportação ou tela que misture dados de equipamentos com dados administrativos não relacionados a TI (caso existam em evoluções futuras fora do escopo aqui definido).

> **Nota:** O módulo de Categorias e Movimentações de estoque permanece, pois é diretamente relacionado ao controle de equipamentos.

---

# 2. Alterações Gerais

## 2.1 Responsividade

A interface deve se adaptar a três faixas de largura, sem perda de funcionalidade:

| Dispositivo | Largura de referência | Comportamento esperado |
|---|---|---|
| Desktop | >= 1024px | Layout atual com menu lateral fixo (sidebar) e tabelas completas |
| Tablet | 768px - 1023px | Sidebar colapsável (off-canvas ou ícones); tabelas com colunas priorizadas e rolagem horizontal quando necessário |
| Celular | < 768px | Menu em formato de gaveta (hambúrguer); listagens em cartões (cards) substituindo tabelas; formulários em coluna única; QR Code e câmera otimizados para uso em pé |

> **Nota:** Os breakpoints acima são referência inicial; podem ser ajustados conforme a necessidade.

## 2.2 Identidade visual (NEXUS)

O arquivo `assets/style.css` adota a identidade NEXUS: paleta neutra (`#FFFFFF`, `#F8F9FA`, `#E5E7EB`, `#9CA3AF`, `#374151`, `#1F2937`, `#111827`), uma única cor de destaque discreta e status em tons suaves.

### Pontos de atenção

- Tokens de cor (`--primary`, `--primary-strong`, `--bg`, `--surface`, `--text`, `--muted`, `--line`) seguem a paleta NEXUS.
- Tipografia: fonte sans-serif moderna do sistema, com Segoe UI/Arial como fallback.
- Componentes (cards, botões, badges de status, tabelas) seguem o mesmo estilo minimalista, com sombras sutis e bordas discretas.


## 2.3 Escopo funcional

O sistema passa a ter como único domínio a gestão de equipamentos de TI: cadastro, localização, estoque, movimentações, requisições, histórico e identificação via QR Code.

Qualquer funcionalidade fora desse domínio é considerada fora de escopo e deve ser removida nas próximas entregas.

---

# 3. Autenticação e Perfis de Acesso

O sistema passa a ter dois perfis distintos, cada um com sua própria tela de login (telas e fluxos de autenticação separados; não há tela de login única com redirecionamento por perfil).

## 3.1 Perfil Usuário

### Tela de login

Tela de login independente da tela de login do Administrador, com campos de identificação do usuário e senha. Após autenticado, o Usuário acessa apenas as funcionalidades abaixo.

### Funcionalidades

- Abrir requisição de equipamento.
- Consultar o status das próprias requisições (o Usuário visualiza somente as requisições que ele mesmo abriu).

### Campos do formulário de abertura de requisição

| Campo | Tipo | Obrigatório |
|---|---|---|
| CPF | Texto (com máscara/validação de CPF) | Sim |
| Nome | Texto | Sim |
| Departamento | Texto ou seleção | Sim |
| Data da solicitação | Data (preenchimento automático, editável se necessário) | Sim |
| Horas de uso do computador | Numérico | Sim |
| Descrição da solicitação | Texto longo | Sim |

### Status possíveis da requisição

- Em análise
- Em processo de compra
- Dependente
- Negado
- Concluído

> **Nota:** "Em análise" é o status inicial padrão ao abrir a requisição.

## 3.2 Perfil Administrador

### Tela de login

Tela de login independente da tela de login do Usuário.

### Funcionalidades

- Visualizar todas as requisições (de todos os usuários).
- Alterar o status das requisições.
- Gerenciar estoque (criação, edição e movimentações - funcionalidade já existente, mantida).
- Gerenciar equipamentos (cadastro, edição, localização, estado - funcionalidade já existente, ampliada com os novos campos descritos na seção 4).
- Acessar dashboard administrativo (ver seção 5).
- Registrar movimentações de estoque (funcionalidade já existente, mantida).
- Solicitar envio/anexo de nota fiscal para conferência de itens adquiridos: ao registrar uma compra/entrada, o Administrador pode solicitar que o anexo da nota fiscal seja enviado para conferência antes da confirmação do recebimento do item.

## 3.3 Resumo de permissões por perfil

| Funcionalidade | Usuário | Administrador |
|---|---|---|
| Login próprio | Sim | Sim |
| Abrir requisição | Sim | Não |
| Consultar status das próprias requisições | Sim | Não se aplica (vê todas) |
| Visualizar todas as requisições | Não | Sim |
| Alterar status de requisição | Não | Sim |
| Gerenciar equipamentos | Não | Sim |
| Gerenciar estoque / movimentações | Não | Sim |
| Acessar dashboard administrativo | Não | Sim |
| Anexar/solicitar nota fiscal | Não | Sim |
| Visualizar histórico de um equipamento | Não | Sim |
| Ler QR Code de um equipamento | Sim (consulta pública autenticada) | Sim |

---

# 4. Equipamentos

## 4.1 Novo campo: Localização

O campo `localizacao` (já existente como texto livre na tabela `ativos`) passa a ter formato estruturado:

- Andar
- Número da estação

**Exemplo de exibição:** `2º Andar / Estação 15`

> **Nota:** Recomenda-se armazenar `andar` e `numero_estacao` em colunas separadas (ver seção 7) para permitir filtros e relatórios por andar; a exibição concatenada é apenas de interface.

## 4.2 Histórico de Equipamentos

Cada equipamento passa a possuir um histórico completo, consultável pelo Administrador, contendo:

- Data de entrega
- Responsável (pessoa que recebeu o equipamento)
- Data de devolução
- Movimentações (entradas, saídas e ajustes de estoque relacionados ao equipamento)
- Fotos do equipamento no momento da entrega
- Fotos do equipamento no momento da devolução

O histórico funciona como uma linha do tempo (*timeline*) por equipamento, permitindo identificar todos os responsáveis anteriores, o estado registrado em cada entrega/devolução e as movimentações de estoque associadas.

> **Nota:** As fotos devem ser armazenadas em disco/storage de arquivos (não em Base64 dentro do banco), com apenas o caminho/URL referenciado na tabela de histórico, para evitar degradação de performance do banco de dados com o crescimento do histórico.

---

# 5. Dashboard

O dashboard administrativo mantém somente indicadores relacionados a equipamentos. Indicadores não relacionados à logística de equipamentos devem ser removidos.

## 5.1 Indicadores mantidos/novos

| Indicador | Origem dos dados | Situação |
|---|---|---|
| Total de equipamentos | Contagem da tabela de equipamentos | Mantido (equivalente a "Total de ativos") |
| Equipamentos disponíveis | Equipamentos sem requisição/entrega ativa | Novo |
| Equipamentos emprestados | Equipamentos com entrega ativa (sem devolução registrada) | Novo |
| Equipamentos em manutenção | Contagem por estado = Manutenção | Mantido (equivalente a "Em manutenção") |
| Movimentações recentes | Últimas movimentações de estoque | Novo (lista no dashboard) |
| Requisições pendentes | Requisições com status diferente de Concluído/Negado | Novo |

## 5.2 Indicadores removidos

- **"Itens em estoque"** (soma total de quantidade) e **"Estoque crítico"** deixam de ser indicadores de destaque no topo do dashboard; o controle de estoque crítico passa a ser tratado dentro do módulo de estoque, não como indicador central de equipamentos.
- Qualquer indicador proveniente de módulos fora do escopo de equipamentos (ex.: indicadores de colaboradores) é removido.

---

# 6. QR Code

Cada equipamento passa a possuir um QR Code individual, gerado a partir do seu código patrimonial.

## 6.1 Integração técnica

Como o backend é em PHP, recomenda-se gerar o QR Code no servidor, evitando dependência de serviços externos com limite de requisições.

### Opções recomendadas

| Opção | Tipo | Observação |
|---|---|---|
| `endroid/qr-code` | Biblioteca PHP (Composer) | Recomendada: gera PNG/SVG localmente, sem custo por requisição, sem dependência de internet em produção |
| `chillerlan/php-qrcode` | Biblioteca PHP (Composer) | Alternativa leve, também local, sem dependências externas de rede |
| API externa (ex.: `goqr.me`) | API REST pública | Mais simples de integrar, porém depende de serviço de terceiros e de conexão com a internet; não recomendada como solução definitiva |

### Fluxo de geração

1. Ao salvar um equipamento (`novo-ativo` / `salvar-ativo`), o sistema gera o conteúdo a ser codificado: uma URL de consulta pública do equipamento, contendo o código patrimonial (ex.: `[dominio](https://dominio/nexus/equipamento/MCX-000001)`).
2. A biblioteca de QR Code converte essa URL em uma imagem (PNG ou SVG).
3. A imagem é armazenada em disco/storage e referenciada na tabela de equipamentos (campo `qrcode_path`).
4. A imagem fica disponível para impressão/etiqueta na tela de detalhes do equipamento.

## 6.2 Tela de leitura do QR Code

Ao escanear o QR Code (com leitor próprio do sistema ou câmera do dispositivo redirecionando para a URL pública de consulta), deve ser exibido:

- Código patrimonial
- Nome do equipamento
- Localização (Andar / Número da estação)
- Estado atual
- Responsável atual
- Histórico resumido (últimas entregas/devoluções, sem necessidade de login completo de Administrador para a consulta básica)

> **Nota:** Definir se a página de leitura do QR Code exige autenticação ou é uma consulta pública somente leitura; recomenda-se consulta pública somente leitura, sem dados sensíveis de outros usuários, para permitir leitura rápida em campo (ex.: por qualquer colaborador que encontre o equipamento).

---

# 7. Banco de Dados (Tabelas Impactadas)

Esta seção apresenta somente as tabelas novas e as alterações em tabelas existentes. Tabelas não mencionadas (ex.: `categorias`) permanecem sem alteração em relação ao `database.sql` atual.

## 7.1 Tabela `usuarios` - alterada

Passa a representar tanto Administradores quanto Usuários, com um campo de perfil:

| Coluna | Tipo | Observação |
|---|---|---|
| `perfil` | `ENUM('administrador','usuario')` | Novo. Define o tipo de acesso e a tela de login correspondente |
| `cpf` | `VARCHAR(14)` | Novo. Obrigatório para perfil usuário; usado também como identificador na requisição |
| `departamento` | `VARCHAR(120)` | Novo. Departamento do usuário |

> **Nota:** Colunas existentes (`id`, `nome`, `email`, `senha`, `created_at`) permanecem inalteradas.

## 7.2 Tabela `ativos` - alterada

Renomeação conceitual para **"equipamentos"** (mantendo ou migrando o nome físico da tabela, conforme decisão da equipe de desenvolvimento).

### Alterações de coluna

| Coluna | Tipo | Observação |
|---|---|---|
| `andar` | `VARCHAR(40)` | Novo. Substitui parte do campo `localizacao` (texto livre) |
| `numero_estacao` | `VARCHAR(20)` | Novo. Substitui parte do campo `localizacao` (texto livre) |
| `qrcode_path` | `VARCHAR(255)` | Novo. Caminho/URL da imagem do QR Code gerado para o equipamento |
| `responsavel_atual_id` | `INT` (FK `usuarios.id`, nullable) | Novo. Usuário responsável pela posse atual do equipamento, usado no dashboard e na leitura do QR Code |
| `localizacao` | `VARCHAR(140)` | Mantido apenas como campo derivado/legado opcional, ou removido se `andar` e `numero_estacao` substituírem totalmente a exibição |

## 7.3 Tabela `requisicoes` - nova

| Coluna | Tipo | Observação |
|---|---|---|
| `id` | `INT AUTO_INCREMENT PK` | Identificador da requisição |
| `usuario_id` | `INT` (FK `usuarios.id`) | Usuário que abriu a requisição |
| `cpf` | `VARCHAR(14)` | CPF informado no formulário |
| `nome` | `VARCHAR(120)` | Nome informado no formulário |
| `departamento` | `VARCHAR(120)` | Departamento informado no formulário |
| `data_solicitacao` | `DATE` | Data da solicitação |
| `horas_uso_computador` | `DECIMAL(6,2)` | Horas de uso do computador, conforme informado pelo usuário |
| `descricao` | `TEXT` | Descrição da solicitação |
| `status` | `ENUM('Em análise','Em processo de compra','Dependente','Negado','Concluído')` | Status atual; default `'Em análise'` |
| `created_at` | `TIMESTAMP` | Data de abertura |
| `updated_at` | `TIMESTAMP` | Data da última mudança de status |

## 7.4 Tabela `historico_equipamentos` - nova

| Coluna | Tipo | Observação |
|---|---|---|
| `id` | `INT AUTO_INCREMENT PK` | Identificador do registro de histórico |
| `equipamento_id` | `INT` (FK `ativos.id`) | Equipamento ao qual o histórico pertence |
| `responsavel_id` | `INT` (FK `usuarios.id`) | Responsável pela posse no período registrado |
| `data_entrega` | `DATE` | Data em que o equipamento foi entregue ao responsável |
| `data_devolucao` | `DATE` (nullable) | Data de devolução; nulo enquanto o equipamento estiver emprestado |
| `foto_entrega_path` | `VARCHAR(255)` | Caminho/URL da foto do equipamento no momento da entrega |
| `foto_devolucao_path` | `VARCHAR(255)` (nullable) | Caminho/URL da foto do equipamento no momento da devolução |
| `observacoes` | `TEXT` | Observações do ciclo de entrega/devolução |
| `created_at` | `TIMESTAMP` | Data de criação do registro |

> **Nota:** As movimentações de estoque (tabela `movimentacoes`, já existente) continuam vinculadas ao equipamento via `ativo_id` e devem ser exibidas junto ao `historico_equipamentos` na timeline do equipamento, sem necessidade de alteração estrutural na tabela `movimentacoes`.

## 7.5 Tabela `notas_fiscais_anexos` - nova

Suporta a funcionalidade do Administrador de solicitar e conferir o anexo da nota fiscal de itens adquiridos.

| Coluna | Tipo | Observação |
|---|---|---|
| `id` | `INT AUTO_INCREMENT PK` | Identificador do anexo |
| `movimentacao_id` | `INT` (FK `movimentacoes.id`) | Movimentação de entrada associada à compra |
| `arquivo_path` | `VARCHAR(255)` | Caminho/URL do arquivo da nota fiscal (PDF ou imagem) |
| `status_conferencia` | `ENUM('Pendente','Conferido','Rejeitado')` | Status da conferência do item recebido |
| `conferido_por` | `INT` (FK `usuarios.id`, nullable) | Administrador que conferiu o anexo |
| `created_at` | `TIMESTAMP` | Data do envio do anexo |

---

# 8. Requisitos Funcionais (Novos/Alterados)

| ID | Requisito | Perfil |
|---|---|---|
| RF-01 | O sistema deve permitir login separado para os perfis Usuário e Administrador, em telas distintas. | Ambos |
| RF-02 | O sistema deve permitir que o Usuário abra uma requisição de equipamento informando CPF, nome, departamento, data da solicitação, horas de uso do computador e descrição. | Usuário |
| RF-03 | O sistema deve permitir que o Usuário consulte o status apenas das requisições que ele mesmo abriu. | Usuário |
| RF-04 | O sistema deve permitir que o Administrador visualize todas as requisições de todos os usuários. | Administrador |
| RF-05 | O sistema deve permitir que o Administrador altere o status de qualquer requisição entre os status válidos. | Administrador |
| RF-06 | O sistema deve permitir o cadastro de equipamentos com localização estruturada em Andar e Número da estação. | Administrador |
| RF-07 | O sistema deve registrar e exibir o histórico completo de cada equipamento, incluindo datas de entrega/devolução, responsável e fotos. | Administrador |
| RF-08 | O sistema deve gerar automaticamente um QR Code individual para cada equipamento cadastrado, baseado no código patrimonial. | Administrador |
| RF-09 | O sistema deve exibir, ao escanear o QR Code, os dados resumidos do equipamento (código, nome, localização, estado, responsável e histórico resumido). | Público autenticado / Administrador |
| RF-10 | O sistema deve permitir que o Administrador solicite e confira o anexo de nota fiscal para itens adquiridos. | Administrador |
| RF-11 | O dashboard administrativo deve exibir apenas indicadores relacionados a equipamentos e requisições. | Administrador |
| RF-12 | A interface deve se adaptar corretamente a resoluções de desktop, tablet e celular. | Ambos |

---

# 9. Casos de Uso (Novos)

## UC-01 - Abrir requisição de equipamento

**Ator:** Usuário

### Fluxo principal

1. Usuário realiza login na tela própria de Usuário.
2. Usuário acessa a opção **"Abrir requisição"**.
3. Usuário preenche CPF, nome, departamento, data da solicitação, horas de uso do computador e descrição.
4. Sistema valida os campos obrigatórios e registra a requisição com status **"Em análise"**.
5. Sistema confirma a abertura e exibe o número/identificador da requisição.

### Fluxo alternativo

Se algum campo obrigatório não for preenchido, o sistema exibe mensagem de validação e mantém os dados já digitados.

## UC-02 - Consultar status de requisições (Usuário)

**Ator:** Usuário

1. Usuário realiza login.
2. Usuário acessa **"Minhas requisições"**.
3. Sistema lista somente as requisições abertas pelo próprio usuário, com status atual.

## UC-03 - Alterar status de requisição (Administrador)

**Ator:** Administrador

1. Administrador realiza login na tela própria de Administrador.
2. Administrador acessa a lista de todas as requisições.
3. Administrador seleciona uma requisição e escolhe o novo status (**Em análise**, **Em processo de compra**, **Dependente**, **Negado** ou **Concluído**).
4. Sistema registra a alteração e atualiza a data de `updated_at`.

## UC-04 - Registrar entrega e devolução de equipamento (histórico)

**Ator:** Administrador

1. Administrador acessa o equipamento e seleciona **"Registrar entrega"**.
2. Administrador informa o responsável, a data de entrega e anexa foto do equipamento no momento da entrega.
3. Sistema cria um registro em `historico_equipamentos` e atualiza `responsavel_atual_id` do equipamento.
4. Quando o equipamento é devolvido, o Administrador acessa o mesmo registro e informa data de devolução e foto de devolução.
5. Sistema encerra o ciclo daquele registro de histórico e libera o equipamento como **"disponível"**.

## UC-05 - Consultar equipamento via QR Code

**Ator:** Qualquer colaborador com acesso à câmera/leitor

1. Colaborador escaneia o QR Code fixado no equipamento.
2. Dispositivo abre a URL pública de consulta do equipamento.
3. Sistema exibe código patrimonial, nome, localização, estado atual, responsável atual e histórico resumido.

## UC-06 - Anexar e conferir nota fiscal

**Ator:** Administrador

1. Administrador registra uma movimentação de entrada (compra de equipamento/item de estoque).
2. Sistema solicita o anexo da nota fiscal correspondente.
3. Administrador envia o arquivo (PDF ou imagem).
4. Administrador (ou outro Administrador responsável pela conferência) confere os itens recebidos contra a nota fiscal e marca o status de conferência como **"Conferido"** ou **"Rejeitado"**.

---

# 10. Fluxos de Telas (Novos/Alterados)

## 10.1 Fluxo de autenticação

Tela inicial -> Seleção implícita por URL/rota própria -> Login Usuário OU Login Administrador -> Área correspondente ao perfil.

- **Login Usuário** -> Dashboard do Usuário (atalho para **"Abrir requisição"** e **"Minhas requisições"**)
- **Login Administrador** -> Dashboard Administrativo (atalho para **Equipamentos**, **Estoque**, **Requisições**, **Movimentações**)

## 10.2 Fluxo do Usuário

Login Usuário -> Dashboard do Usuário -> **[Abrir requisição]** -> Formulário de requisição -> Confirmação -> **Minhas requisições** (lista com status)

## 10.3 Fluxo do Administrador

Login Administrador -> Dashboard Administrativo -> **[Equipamentos | Estoque | Requisições | Movimentações]**

- **Equipamentos** -> Lista de equipamentos -> Detalhe do equipamento -> **[Editar dados | Ver histórico | Ver/baixar QR Code | Registrar entrega/devolução]**
- **Requisições** -> Lista de todas as requisições (com filtro por status) -> Detalhe da requisição -> Alterar status
- **Movimentações** -> Registrar movimentação -> (se Entrada/compra) Solicitar anexo de nota fiscal -> Conferência da nota fiscal

## 10.4 Fluxo de leitura de QR Code

Leitura do QR Code (câmera do dispositivo) -> URL pública do equipamento -> Tela de consulta (código, nome, localização, estado, responsável, histórico resumido)

Esta tela não exige o login completo de Administrador, apenas exibe dados de consulta do próprio equipamento.

---

# 11. Regras de Negócio (Novas/Alteradas)

1. Toda requisição é criada com status inicial **"Em análise"**, independentemente do que o Usuário informar.
2. Apenas o Administrador pode alterar o status de uma requisição; o Usuário tem acesso somente de leitura ao status.
3. Um Usuário somente pode visualizar requisições vinculadas ao seu próprio `usuario_id`; não há visualização cruzada entre usuários.
4. Cada equipamento possui exatamente um QR Code, gerado automaticamente no cadastro e regenerado caso o código patrimonial seja alterado.
5. Um equipamento só pode ser marcado como **"disponível"** quando não houver registro de histórico aberto (ou seja, com `data_entrega` preenchida e `data_devolucao` nula).
6. Toda entrega de equipamento exige foto no momento da entrega; toda devolução exige foto no momento da devolução, antes de o registro de histórico ser considerado concluído.
7. Movimentações de estoque do tipo **Entrada** vinculadas a uma compra devem ter o anexo de nota fiscal solicitado pelo Administrador antes da confirmação definitiva do recebimento.
8. O dashboard administrativo exibe apenas indicadores relacionados a equipamentos, estoque e requisições; qualquer novo indicador fora desse domínio deve ser avaliado como exceção formal ao escopo.
9. A consulta via QR Code é somente leitura e não expõe dados sensíveis de outros usuários ou de requisições.

---

# 12. Itens Pendentes de Definição

Os pontos abaixo dependem de informações ainda não fornecidas e devem ser confirmados com a equipe antes da implementação final:

- Definição se a consulta pública via QR Code exige algum nível de autenticação (ex.: rede interna apenas) ou é totalmente pública.
- Biblioteca de QR Code definitiva (`endroid/qr-code` ou `chillerlan/php-qrcode`), conforme disponibilidade de instalação via Composer no ambiente de produção.
- Política de retenção e armazenamento das fotos de entrega/devolução (tempo de retenção, limite de tamanho, formato aceito).

