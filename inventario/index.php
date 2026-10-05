<?php

declare(strict_types=1);

session_start();
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/nova_modules.php';

function db(): PDO
{
    static $pdo = null;
    global $config;
    if (!$pdo) {
        $dsn = "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function check_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        exit('Sessao expirada. Atualize a pagina.');
    }
}

function redirect(string $page, array $params = []): void
{
    $query = $params ? '&' . http_build_query($params) : '';
    header('Location: index.php?page=' . $page . $query);
    exit;
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_profile(string $profile): void
{
    if (empty($_SESSION['user']) || $_SESSION['user']['perfil'] !== $profile) {
        redirect('login');
    }
}

function require_profiles(array $profiles): void
{
    if (empty($_SESSION['user']) || !in_array($_SESSION['user']['perfil'], $profiles, true)) {
        redirect('login');
    }
}

function current_profile(): ?string
{
    return current_user()['perfil'] ?? null;
}

function is_gestor(): bool
{
    return current_profile() === 'gestor';
}

function is_diretoria(): bool
{
    return current_profile() === 'diretoria';
}

/**
 * Categorias que o perfil "gestor" pode enxergar/gerenciar.
 * Administrador nao tem restricao (retorna null = sem filtro).
 */
function categoria_tipos_permitidos(): ?array
{
    if (is_gestor()) {
        return ['limpeza', 'escritorio'];
    }
    return null;
}

/**
 * Retorna os IDs de categoria permitidos para o perfil atual, ou null se nao houver restricao.
 */
function categorias_permitidas_ids(): ?array
{
    $tipos = categoria_tipos_permitidos();
    if ($tipos === null) {
        return null;
    }
    if (!$tipos) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($tipos), '?'));
    $stmt = db()->prepare("SELECT id FROM categorias WHERE tipo IN ({$placeholders})");
    $stmt->execute($tipos);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Verifica se uma categoria esta dentro do escopo permitido para o usuario atual.
 * Categoria nula (sem categoria) e tratada como fora do escopo para o gestor.
 */
function categoria_no_escopo(?int $categoriaId): bool
{
    $tipos = categoria_tipos_permitidos();
    if ($tipos === null) {
        return true;
    }
    if (!$categoriaId) {
        return false;
    }
    $stmt = db()->prepare('SELECT tipo FROM categorias WHERE id = ?');
    $stmt->execute([$categoriaId]);
    $tipo = $stmt->fetchColumn();
    return $tipo !== false && in_array($tipo, $tipos, true);
}

/**
 * Garante que o ativo informado esta dentro do escopo do usuario atual (gestor). Redireciona caso contrario.
 */
function require_ativo_no_escopo(int $ativoId, string $voltarPara): void
{
    if (categoria_tipos_permitidos() === null) {
        return;
    }
    $stmt = db()->prepare('SELECT categoria_id FROM ativos WHERE id = ?');
    $stmt->execute([$ativoId]);
    $categoriaId = $stmt->fetchColumn();
    if ($categoriaId === false || !categoria_no_escopo($categoriaId !== null ? (int) $categoriaId : null)) {
        $_SESSION['flash'] = 'Voce nao tem permissao para acessar este ativo.';
        redirect($voltarPara);
    }
}

/** SQL: clausula de filtro de categoria (AND) para o perfil atual, usando a coluna de categoria_id informada. */
function categoria_scope_sql(string $colunaCategoriaId): array
{
    $ids = categorias_permitidas_ids();
    if ($ids === null) {
        return ['', []];
    }
    if (!$ids) {
        return [' AND 1=0', []];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    return [" AND {$colunaCategoriaId} IN ({$placeholders})", $ids];
}

function next_code(string $table, string $column, string $prefix): string
{
    $stmt = db()->prepare("SELECT {$column} FROM {$table} WHERE {$column} LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '-%']);
    $last = (string) ($stmt->fetchColumn() ?: '');
    $num = 1;
    if (preg_match('/(\d+)$/', $last, $m)) {
        $num = ((int) $m[1]) + 1;
    }
    return $prefix . '-' . str_pad((string) $num, 6, '0', STR_PAD_LEFT);
}

function upload_file(string $field, array $allowedExts, string $dir): ?string
{
    if (empty($_FILES[$field]['name']) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Falha no upload do arquivo.');
    }
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExts, true)) {
        throw new RuntimeException('Tipo de arquivo nao permitido.');
    }
    $targetDir = __DIR__ . '/' . trim($dir, '/');
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0775, true);
    }
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    $absolute = $targetDir . '/' . $name;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $absolute)) {
        throw new RuntimeException('Nao foi possivel salvar o arquivo.');
    }
    return trim($dir, '/') . '/' . $name;
}

function get_user_data(int $userId): ?array
{
    $stmt = db()->prepare('SELECT * FROM usuarios WHERE id = ?');
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

function add_history(int $ativoId, string $tipo, int $quantidade, string $descricao, ?int $movimentacaoId = null, ?int $compraId = null): void
{
    db()->prepare('
        INSERT INTO historico_ativos (ativo_id, movimentacao_id, compra_id, tipo, quantidade, descricao, usuario_id)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ')->execute([$ativoId, $movimentacaoId, $compraId, $tipo, $quantidade, $descricao, current_user()['id'] ?? null]);
}

/** Conclui uma requisicao de estoque sem permitir baixa concorrente duplicada. */
function concluir_requisicao_estoque(int $requisicaoId): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $req = $pdo->prepare('SELECT r.*, a.codigo, a.nome AS ativo_nome, a.quantidade_atual FROM requisicoes r JOIN ativos a ON a.id = r.ativo_id WHERE r.id = ? FOR UPDATE');
        $req->execute([$requisicaoId]);
        $requisicao = $req->fetch();
        if (!$requisicao || !$requisicao['ativo_id']) throw new RuntimeException('Requisicao ou equipamento nao encontrado.');
        if ($requisicao['status'] === 'Concluído') throw new RuntimeException('Esta requisicao ja foi concluida.');
        if ((int) $requisicao['quantidade_atual'] < 1) throw new RuntimeException('Estoque insuficiente para concluir esta solicitacao.');

        $updateStock = $pdo->prepare('UPDATE ativos SET quantidade_atual = quantidade_atual - 1 WHERE id = ? AND quantidade_atual > 0');
        $updateStock->execute([(int) $requisicao['ativo_id']]);
        if ($updateStock->rowCount() !== 1) throw new RuntimeException('Estoque insuficiente para concluir esta solicitacao.');

        $tecnico = current_user()['nome'] ?? '';
        $movimento = $pdo->prepare("INSERT INTO movimentacoes (ativo_id, tipo, quantidade, colaborador_recebe, funcionario_entrega, observacao, origem, solicitacao_id) VALUES (?, 'Saida', ?, ?, ?, ?, 'Requisicao', ?)");
        $movimento->execute([(int) $requisicao['ativo_id'], -1, $requisicao['nome'], $tecnico, 'Saida automatica pela requisicao ' . $requisicao['codigo_requisicao'], $requisicaoId]);
        $movimentoId = (int) $pdo->lastInsertId();
        add_history((int) $requisicao['ativo_id'], 'Saida por requisicao', -1, 'Entrega a ' . $requisicao['nome'] . ' pela requisicao ' . $requisicao['codigo_requisicao'] . '. Tecnico responsavel: ' . $tecnico . '.', $movimentoId);

        $finalizar = $pdo->prepare("UPDATE requisicoes SET status = 'Concluído', aprovado_por = ?, data_finalizacao = NOW() WHERE id = ? AND status <> 'Concluído'");
        $finalizar->execute([$tecnico ?: null, $requisicaoId]);
        if ($finalizar->rowCount() !== 1) throw new RuntimeException('Esta requisicao nao esta disponivel para conclusao.');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function render_status_badge(string $status): string
{
    $classes = [
        'Concluído' => 'status-concluido',
        'Negado' => 'status-negado',
        'Recusada' => 'status-negado',
        'Aprovada' => 'status-concluido',
        'Em processo de compra' => 'status-processo',
        'Em análise' => 'status-analise',
        'Dependente' => 'status-dependente',
    ];
    $labels = ['Concluído' => 'Concluído', 'Em análise' => 'Em análise'];
    $class = $classes[$status] ?? 'status-analise';
    return '<span class="status-indicator ' . $class . '"><span class="status-dot"></span>' . e($labels[$status] ?? $status) . '</span>';
}

function money_br(mixed $value): string
{
    return 'R$ ' . number_format((float) $value, 2, ',', '.');
}

function image_tag(?string $path, string $alt = 'Foto do ativo'): string
{
    if ($path) {
        return '<img class="asset-photo" src="' . e($path) . '" alt="' . e($alt) . '">';
    }
    return '<div class="asset-photo placeholder">Sem foto</div>';
}

function column_label(string $column): string
{
    $labels = [
        'codigo' => 'Codigo',
        'nome' => 'Nome',
        'categoria' => 'Categoria',
        'quantidade_atual' => 'Quantidade atual',
        'estoque_minimo' => 'Estoque minimo',
        'estoque_maximo' => 'Estoque maximo',
        'observacoes' => 'Observacoes',
        'colaborador' => 'Colaborador',
        'quantidade_atribuida' => 'Quantidade atribuida',
        'data_atribuicao' => 'Data de atribuicao',
        'opening_stock' => 'Estoque inicial',
        'counted' => 'Contado',
        'balance' => 'Saldo',
        'tipo' => 'Tipo',
        'quantidade' => 'Quantidade',
        'descricao' => 'Descricao',
        'created_at' => 'Criado em',
    ];
    return $labels[$column] ?? ucfirst(str_replace('_', ' ', $column));
}

function table_view(array $rows, string $empty = 'Nenhum registro encontrado.', string $tableId = ''): void
{
    if (!$rows) {
        echo '<p class="muted">' . e($empty) . '</p>';
        return;
    }
    echo '<div class="table-wrap"><table' . ($tableId !== '' ? ' id="' . e($tableId) . '"' : '') . ' class="data-table"><thead><tr>';
    foreach (array_keys($rows[0]) as $col) {
        if ($col !== 'id') {
            echo '<th>' . e(column_label((string) $col)) . '</th>';
        }
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($row as $col => $value) {
            if ($col !== 'id') {
                echo '<td>' . e($value) . '</td>';
            }
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

function render_topbar(string $area, array $links): void
{
    $user = current_user();
    echo '<header class="topbar">';
    echo '<div class="topbar-inner">';
    echo '<a class="brand" href="index.php?page=' . ($area === 'usuario' ? 'abrir-requisicao' : 'dashboard') . '"><span class="brand-name">NEXUS</span></a>';
    echo '<nav class="topnav">';
    foreach ($links as $href => $label) {
        if (is_array($label)) {
            $active = in_array(($_GET['page'] ?? 'dashboard'), array_keys($label), true) ? ' active-link' : '';
            echo '<div class="nav-dropdown"><button type="button" class="nav-dropdown-toggle' . $active . '">' . e((string) $href) . '</button><div class="nav-dropdown-menu">';
            foreach ($label as $childHref => $childLabel) {
                echo '<a href="index.php?page=' . e($childHref) . '">' . e($childLabel) . '</a>';
            }
            echo '</div></div>';
            continue;
        }
        $active = (($_GET['page'] ?? 'dashboard') === $href) ? ' active-link' : '';
        echo '<a href="index.php?page=' . e($href) . '" class="' . $active . '">' . e($label) . '</a>';
    }
    echo '</nav>';
    echo '<div class="top-actions">';
    echo '<button type="button" class="notify-btn" aria-label="Notificacoes">!</button>';
    echo '<div class="user-area"><span>' . e($user['nome'] ?? 'Usuario') . '</span><small>' . e($user['perfil'] ?? $area) . '</small></div>';
    echo '<a class="logout-link" href="index.php?page=logout">Sair</a>';
    echo '</div></div></header>';
}

$page = $_GET['page'] ?? 'dashboard';
check_csrf();

$novaPages = ['usuarios', 'user-save', 'purchase-requests', 'purchase-request-save', 'it-dashboard', 'purchase-it-review', 'requisicoes-ti', 'concluir-requisicao-ti', 'finance-dashboard', 'purchase-complete', 'rh-dashboard', 'hiring', 'hiring-save', 'hiring-review', 'inventory', 'inventory-start', 'inventory-scan', 'inventory-finish', 'inventory-reports', 'stock-info'];

$userPages = ['abrir-requisicao', 'minhas-requisicoes', 'salvar-requisicao'];

// Paginas exclusivas do administrador (envolvem TI, categorias e configuracoes gerais)
$adminOnlyPages = [
    'categorias', 'salvar-categoria', 'excluir-categoria',
    'salvar-edicao-requisicao', 'excluir-requisicao', 'exportar', 'importar',
];

// Paginas de estoque/ativos/movimentacoes/compras: administrador (sem restricao) e gestor (escopo limitado)
$estoquePages = [
    'estoque', 'ativos', 'salvar-ativo', 'excluir-ativo',
    'movimentacoes', 'movimentar', 'salvar-edicao-movimentacao', 'excluir-movimentacao',
    'salvar-compra', 'salvar-edicao-compra', 'excluir-compra',
    'qrcode',
];

$adminPages = array_merge(['dashboard', 'requisicoes'], $adminOnlyPages, $estoquePages);
$gestorPages = array_merge(['dashboard', 'requisicoes', 'categorias', 'salvar-categoria', 'excluir-categoria', 'exportar', 'importar'], $estoquePages);
$diretoriaPages = ['dashboard', 'requisicoes'];

$publicPages = ['login', 'logout', 'qr'];

if (!in_array($page, $publicPages, true)) {
    if (in_array($page, $novaPages, true)) {
        if (in_array($page, ['inventory', 'inventory-start', 'inventory-scan', 'inventory-finish', 'inventory-reports'], true)) {
            require_profiles(['administrador']);
        } elseif ($page === 'usuarios' || $page === 'user-save' || $page === 'purchase-requests' || $page === 'purchase-request-save') {
            require_profiles(['administrador']);
        } elseif (in_array($page, ['it-dashboard', 'purchase-it-review', 'requisicoes-ti', 'concluir-requisicao-ti'], true)) {
            require_profiles(['coordenacao_ti']);
        } elseif ($page === 'finance-dashboard' || $page === 'purchase-complete') {
            require_profiles(['financeiro']);
        } elseif ($page === 'rh-dashboard' || $page === 'hiring-save') {
            require_profiles(['rh']);
        } elseif ($page === 'hiring' || $page === 'hiring-review') {
            require_profiles(['administrador', 'rh']);
        } elseif ($page === 'stock-info') {
            require_profiles(['administrador', 'gestor']);
        }
    } elseif (in_array($page, $userPages, true)) {
        require_profiles(['usuario']);
    } elseif (in_array($page, $adminOnlyPages, true)) {
        require_profiles(['administrador', 'gestor']);
    } elseif (in_array($page, $estoquePages, true)) {
        require_profiles(['administrador', 'gestor']);
    } elseif ($page === 'dashboard') {
        require_profiles(['administrador', 'gestor', 'diretoria', 'coordenacao_ti', 'financeiro', 'rh']);
    } elseif ($page === 'requisicoes') {
        require_profiles(['administrador', 'gestor', 'diretoria']);
    } else {
        require_profiles(['administrador']);
    }
}

if ($page === 'logout') {
    session_destroy();
    redirect('login');
}

if ($page === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = db()->prepare('SELECT * FROM usuarios WHERE email = ?');
    $stmt->execute([trim($_POST['email'] ?? '')]);
    $user = $stmt->fetch();
    if ($user && password_verify($_POST['senha'] ?? '', $user['senha'])) {
        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'nome' => $user['nome'],
            'perfil' => $user['perfil'],
            'matricula' => $user['matricula'] ?? '',
            'cpf' => $user['cpf'] ?? '',
            'departamento' => $user['departamento'] ?? '',
            'funcao' => $user['funcao'] ?? '',
        ];
        redirect($user['perfil'] === 'usuario' ? 'abrir-requisicao' : 'dashboard');
    }
    $_SESSION['flash'] = 'E-mail ou senha invalidos.';
}

nova_handle_action($page);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'salvar-requisicao') {
    $userData = get_user_data((int) current_user()['id']);
    $descricao = trim($_POST['descricao'] ?? '');
    $ativoId = (int) ($_POST['ativo_id'] ?? 0);
    if ($descricao === '' || !$ativoId) {
        $_SESSION['flash'] = 'Informe o tipo de equipamento e a descricao da solicitacao.';
        redirect('abrir-requisicao');
    }
    $ativoStmt = db()->prepare('SELECT id FROM ativos WHERE id = ?');
    $ativoStmt->execute([$ativoId]);
    if (!$ativoStmt->fetchColumn()) { $_SESSION['flash'] = 'O equipamento selecionado nao esta mais disponivel no cadastro.'; redirect('abrir-requisicao'); }
    $codigo = next_code('requisicoes', 'codigo_requisicao', 'REQ');
    db()->prepare('
        INSERT INTO requisicoes
        (codigo_requisicao, usuario_id, ativo_id, matricula, cpf, nome, departamento, funcao, descricao, status, data_solicitacao, hora_solicitacao)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), CURTIME())
    ')->execute([
        $codigo,
        current_user()['id'],
        $ativoId,
        $userData['matricula'] ?? '',
        $userData['cpf'] ?? '',
        $userData['nome'] ?? current_user()['nome'],
        $userData['departamento'] ?? '',
        $userData['funcao'] ?? '',
        $descricao,
            'Em análise',
    ]);
    $_SESSION['flash'] = 'Requisicao aberta com sucesso. Codigo: ' . $codigo;
    redirect('minhas-requisicoes');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'salvar-edicao-requisicao') {
    $id = (int) ($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $statusValidos = ['Em análise', 'Em processo de compra', 'Dependente', 'Negado', 'Concluído'];
    $reqAtivoId = null;
    if ($id) {
        $reqAtivo = db()->prepare('SELECT ativo_id FROM requisicoes WHERE id = ?');
        $reqAtivo->execute([$id]);
        $reqAtivoId = $reqAtivo->fetchColumn();
    }
    if ($status === 'Concluído' && $reqAtivoId !== null) {
        $_SESSION['flash'] = 'A conclusao de requisicoes de equipamento deve ser feita no Painel da TI.';
    } elseif ($id && in_array($status, $statusValidos, true)) {
        $finalizada = in_array($status, ['Concluído', 'Negado'], true) ? date('Y-m-d H:i:s') : null;
        db()->prepare('
            UPDATE requisicoes
            SET status = ?, descricao = ?, aprovado_por = ?, data_finalizacao = ?
            WHERE id = ?
        ')->execute([
            $status,
            trim($_POST['descricao'] ?? ''),
            trim($_POST['aprovado_por'] ?? '') ?: null,
            $finalizada,
            $id,
        ]);
        $_SESSION['flash'] = 'Requisicao atualizada com sucesso.';
    }
    redirect('requisicoes');
}

if ($page === 'excluir-requisicao' && !empty($_GET['id'])) {
    db()->prepare('DELETE FROM requisicoes WHERE id = ?')->execute([(int) $_GET['id']]);
    $_SESSION['flash'] = 'Requisicao excluida com sucesso.';
    redirect('requisicoes');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'salvar-categoria') {
    $id = (int) ($_POST['id'] ?? 0);
    $nome = trim($_POST['nome'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $tipo = in_array($_POST['tipo'] ?? '', ['ti', 'limpeza', 'escritorio'], true) ? $_POST['tipo'] : 'ti';
    if (is_gestor() && !in_array($tipo, ['limpeza', 'escritorio'], true)) {
        $_SESSION['flash'] = 'O Gestor pode administrar somente categorias de Limpeza e Escritorio.';
        redirect('categorias');
    }
    if ($nome !== '') {
        if ($id) {
            if (is_gestor() && !categoria_no_escopo($id)) {
                $_SESSION['flash'] = 'Voce nao tem permissao para editar esta categoria.';
                redirect('categorias');
            }
            db()->prepare('UPDATE categorias SET nome = ?, descricao = ?, tipo = ? WHERE id = ?')->execute([$nome, $descricao, $tipo, $id]);
            $_SESSION['flash'] = 'Categoria atualizada.';
        } else {
            db()->prepare('INSERT INTO categorias (nome, descricao, tipo) VALUES (?, ?, ?)')->execute([$nome, $descricao, $tipo]);
            $_SESSION['flash'] = 'Categoria criada.';
        }
    }
    redirect('categorias');
}

if ($page === 'excluir-categoria' && !empty($_GET['id'])) {
    if (is_gestor() && !categoria_no_escopo((int) $_GET['id'])) {
        $_SESSION['flash'] = 'Voce nao tem permissao para excluir esta categoria.';
        redirect('categorias');
    }
    db()->prepare('DELETE FROM categorias WHERE id = ?')->execute([(int) $_GET['id']]);
    $_SESSION['flash'] = 'Categoria excluida.';
    redirect('categorias');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'salvar-ativo') {
    require_inventory_unlocked('ativos');
    $id = (int) ($_POST['id'] ?? 0);
    if ($id) {
        require_ativo_no_escopo($id, 'ativos');
    }
    try {
        $foto = upload_file('foto', ['jpg', 'jpeg', 'png', 'webp'], 'uploads/ativos');
        $fields = ['codigo', 'categoria_id', 'nome', 'marca', 'modelo', 'fornecedor', 'nota_fiscal', 'valor_compra', 'data_compra', 'garantia', 'estoque_minimo', 'estado', 'localizacao', 'observacoes'];
        $data = [];
        foreach ($fields as $field) {
            $data[$field] = $_POST[$field] ?? null;
        }
        $data['categoria_id'] = $data['categoria_id'] !== '' ? (int) $data['categoria_id'] : null;
        if (!categoria_no_escopo($data['categoria_id'])) {
            $_SESSION['flash'] = 'Voce nao tem permissao para cadastrar/editar ativos nesta categoria.';
            redirect('ativos');
        }
        $data['valor_compra'] = $data['valor_compra'] !== '' ? (float) $data['valor_compra'] : 0;
        $data['estoque_minimo'] = max(0, (int) $data['estoque_minimo']);
        if ($id) {
            $sets = implode(', ', array_map(fn ($f) => "{$f} = :{$f}", $fields));
            if ($foto) {
                $sets .= ', foto = :foto';
                $data['foto'] = $foto;
            }
            $data['id'] = $id;
            db()->prepare("UPDATE ativos SET {$sets} WHERE id = :id")->execute($data);
            $_SESSION['flash'] = 'Ativo atualizado.';
        } else {
            $data['foto'] = $foto;
            $data['quantidade_atual'] = 0;
            $cols = implode(', ', [...$fields, 'foto', 'quantidade_atual']);
            $params = ':' . implode(', :', [...$fields, 'foto', 'quantidade_atual']);
            db()->prepare("INSERT INTO ativos ({$cols}) VALUES ({$params})")->execute($data);
            add_history((int) db()->lastInsertId(), 'Cadastro', 0, 'Ativo cadastrado.');
            $_SESSION['flash'] = 'Ativo cadastrado.';
        }
    } catch (Throwable $e) {
        $_SESSION['flash'] = $e->getMessage();
    }
    redirect('ativos');
}

if ($page === 'excluir-ativo' && !empty($_GET['id'])) {
    require_inventory_unlocked('ativos');
    require_ativo_no_escopo((int) $_GET['id'], 'ativos');
    db()->prepare('DELETE FROM ativos WHERE id = ?')->execute([(int) $_GET['id']]);
    $_SESSION['flash'] = 'Ativo excluido.';
    redirect('ativos');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'movimentar') {
    require_inventory_unlocked('movimentacoes');
    $ativoId = (int) ($_POST['ativo_id'] ?? 0);
    require_ativo_no_escopo($ativoId, 'movimentacoes');
    $tipo = $_POST['tipo'] ?? '';
    $quantidade = max(1, (int) ($_POST['quantidade'] ?? 1));
    $colaborador = trim($_POST['colaborador_recebe'] ?? '');
    $entregador = trim($_POST['funcionario_entrega'] ?? '');
    $obs = trim($_POST['observacao'] ?? '');
    if ($ativoId && in_array($tipo, ['Entrada', 'Saida', 'Ajuste'], true)) {
        $delta = $tipo === 'Saida' ? -$quantidade : $quantidade;
        if ($tipo === 'Ajuste') {
            $delta = (int) ($_POST['quantidade'] ?? 0);
        }
        db()->beginTransaction();
        db()->prepare('UPDATE ativos SET quantidade_atual = GREATEST(0, quantidade_atual + ?) WHERE id = ?')->execute([$delta, $ativoId]);
        db()->prepare('
            INSERT INTO movimentacoes (ativo_id, tipo, quantidade, colaborador_recebe, funcionario_entrega, observacao)
            VALUES (?, ?, ?, ?, ?, ?)
        ')->execute([$ativoId, $tipo, $delta, $colaborador, $entregador, $obs]);
        $movId = (int) db()->lastInsertId();
        add_history($ativoId, $tipo, $delta, trim($tipo . ' registrada. ' . $obs), $movId);
        db()->commit();
        $_SESSION['flash'] = 'Movimentacao registrada.';
    }
    redirect('movimentacoes');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'salvar-edicao-movimentacao') {
    require_inventory_unlocked('movimentacoes');
    $id = (int) ($_POST['id'] ?? 0);
    $ativoId = (int) ($_POST['ativo_id'] ?? 0);
    require_ativo_no_escopo($ativoId, 'movimentacoes');
    $tipo = $_POST['tipo'] ?? '';
    $quantidade = max(1, (int) ($_POST['quantidade'] ?? 1));
    $colaborador = trim($_POST['colaborador_recebe'] ?? '');
    $entregador = trim($_POST['funcionario_entrega'] ?? '');
    $obs = trim($_POST['observacao'] ?? '');
    if ($id && $ativoId && in_array($tipo, ['Entrada', 'Saida', 'Ajuste'], true)) {
        $stmt = db()->prepare('SELECT ativo_id, quantidade FROM movimentacoes WHERE id = ?');
        $stmt->execute([$id]);
        $old = $stmt->fetch();
        if ($old) {
            require_ativo_no_escopo((int) $old['ativo_id'], 'movimentacoes');
            $delta = $tipo === 'Saida' ? -$quantidade : $quantidade;
            if ($tipo === 'Ajuste') {
                $delta = (int) ($_POST['quantidade'] ?? 0);
            }
            db()->beginTransaction();
            db()->prepare('UPDATE ativos SET quantidade_atual = GREATEST(0, quantidade_atual - ?) WHERE id = ?')->execute([(int) $old['quantidade'], (int) $old['ativo_id']]);
            db()->prepare('UPDATE ativos SET quantidade_atual = GREATEST(0, quantidade_atual + ?) WHERE id = ?')->execute([$delta, $ativoId]);
            db()->prepare('
                UPDATE movimentacoes
                SET ativo_id = ?, tipo = ?, quantidade = ?, colaborador_recebe = ?, funcionario_entrega = ?, observacao = ?
                WHERE id = ?
            ')->execute([$ativoId, $tipo, $delta, $colaborador, $entregador, $obs, $id]);
            add_history($ativoId, 'Edicao de movimentacao', $delta, 'Movimentacao #' . $id . ' editada; saldo anterior revertido e novo saldo aplicado. ' . $obs, $id);
            db()->commit();
            $_SESSION['flash'] = 'Movimentacao atualizada.';
        }
    }
    redirect('movimentacoes');
}

if ($page === 'excluir-movimentacao' && !empty($_GET['id'])) {
    require_inventory_unlocked('movimentacoes');
    $id = (int) $_GET['id'];
    $stmt = db()->prepare('SELECT ativo_id, quantidade FROM movimentacoes WHERE id = ?');
    $stmt->execute([$id]);
    $mov = $stmt->fetch();
    if ($mov) {
        require_ativo_no_escopo((int) $mov['ativo_id'], 'movimentacoes');
        db()->beginTransaction();
        db()->prepare('UPDATE ativos SET quantidade_atual = GREATEST(0, quantidade_atual - ?) WHERE id = ?')->execute([(int) $mov['quantidade'], (int) $mov['ativo_id']]);
        add_history((int) $mov['ativo_id'], 'Exclusao de movimentacao', -(int) $mov['quantidade'], 'Movimentacao #' . $id . ' excluida.');
        db()->prepare('DELETE FROM movimentacoes WHERE id = ?')->execute([$id]);
        db()->commit();
        $_SESSION['flash'] = 'Movimentacao excluida e estoque ajustado.';
    }
    redirect('movimentacoes');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'salvar-compra') {
    require_inventory_unlocked('estoque');
    $ativos = $_POST['item_ativo_id'] ?? [];
    $quantidades = $_POST['item_quantidade'] ?? [];
    $valores = $_POST['item_valor_unitario'] ?? [];
    foreach ($ativos as $ativoIdCheck) {
        if ((int) $ativoIdCheck > 0) {
            require_ativo_no_escopo((int) $ativoIdCheck, 'estoque');
        }
    }
    try {
        db()->beginTransaction();
        $total = 0;
        foreach ($ativos as $i => $ativoId) {
            if ((int) $ativoId > 0) {
                $total += max(1, (int) ($quantidades[$i] ?? 1)) * (float) ($valores[$i] ?? 0);
            }
        }
        db()->prepare('
            INSERT INTO compras
            (cnpj, razao_social, chave_acesso, numero_nf, data_emissao, data_entrada, observacao, valor_total, usuario_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            trim($_POST['cnpj'] ?? ''),
            trim($_POST['razao_social'] ?? ''),
            trim($_POST['chave_acesso'] ?? ''),
            trim($_POST['numero_nf'] ?? ''),
            $_POST['data_emissao'] ?: null,
            $_POST['data_entrada'] ?: date('Y-m-d'),
            trim($_POST['observacao'] ?? ''),
            $total,
            current_user()['id'] ?? null,
        ]);
        $compraId = (int) db()->lastInsertId();
        foreach ($ativos as $i => $ativoId) {
            $ativoId = (int) $ativoId;
            if (!$ativoId) {
                continue;
            }
            $qtd = max(1, (int) ($quantidades[$i] ?? 1));
            $unit = (float) ($valores[$i] ?? 0);
            $itemTotal = $qtd * $unit;
            db()->prepare('
                INSERT INTO compra_itens (compra_id, ativo_id, quantidade, valor_unitario, valor_total)
                VALUES (?, ?, ?, ?, ?)
            ')->execute([$compraId, $ativoId, $qtd, $unit, $itemTotal]);
            db()->prepare('UPDATE ativos SET quantidade_atual = quantidade_atual + ? WHERE id = ?')->execute([$qtd, $ativoId]);
            db()->prepare('INSERT INTO movimentacoes (ativo_id, tipo, quantidade, observacao) VALUES (?, ?, ?, ?)')->execute([$ativoId, 'Entrada', $qtd, 'Entrada por compra #' . $compraId]);
            $movId = (int) db()->lastInsertId();
            add_history($ativoId, 'Compra', $qtd, 'Entrada registrada pela compra #' . $compraId, $movId, $compraId);
        }
        $anexo = upload_file('nota_fiscal_arquivo', ['pdf', 'xml'], 'uploads/notas');
        if ($anexo) {
            db()->prepare('INSERT INTO anexos_nf (compra_id, nome_original, caminho, tipo) VALUES (?, ?, ?, ?)')->execute([
                $compraId,
                $_FILES['nota_fiscal_arquivo']['name'],
                $anexo,
                strtolower(pathinfo($anexo, PATHINFO_EXTENSION)),
            ]);
        }
        db()->commit();
        $_SESSION['flash'] = 'Compra salva e estoque atualizado.';
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        $_SESSION['flash'] = $e->getMessage();
    }
    redirect('estoque');
}

/** Garante que TODOS os itens de uma compra estao dentro do escopo do usuario atual (gestor). */
function require_compra_no_escopo(int $compraId, string $voltarPara): void
{
    if (categoria_tipos_permitidos() === null) {
        return;
    }
    $stmt = db()->prepare('SELECT DISTINCT ativo_id FROM compra_itens WHERE compra_id = ?');
    $stmt->execute([$compraId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $ativoId) {
        require_ativo_no_escopo((int) $ativoId, $voltarPara);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'salvar-edicao-compra') {
    require_inventory_unlocked('estoque');
    $id = (int) ($_POST['id'] ?? 0);
    if ($id) {
        require_compra_no_escopo($id, 'estoque');
        db()->prepare('
            UPDATE compras
            SET cnpj = ?, razao_social = ?, chave_acesso = ?, numero_nf = ?, data_emissao = ?, data_entrada = ?, observacao = ?
            WHERE id = ?
        ')->execute([
            trim($_POST['cnpj'] ?? ''),
            trim($_POST['razao_social'] ?? ''),
            trim($_POST['chave_acesso'] ?? ''),
            trim($_POST['numero_nf'] ?? ''),
            $_POST['data_emissao'] ?: null,
            $_POST['data_entrada'] ?: date('Y-m-d'),
            trim($_POST['observacao'] ?? ''),
            $id,
        ]);
        $_SESSION['flash'] = 'Compra atualizada.';
    }
    redirect('estoque');
}

if ($page === 'excluir-compra' && !empty($_GET['id'])) {
    require_inventory_unlocked('estoque');
    $id = (int) $_GET['id'];
    require_compra_no_escopo($id, 'estoque');
    db()->beginTransaction();
    $items = db()->prepare('SELECT ativo_id, quantidade FROM compra_itens WHERE compra_id = ?');
    $items->execute([$id]);
    foreach ($items->fetchAll() as $item) {
        db()->prepare('UPDATE ativos SET quantidade_atual = GREATEST(0, quantidade_atual - ?) WHERE id = ?')->execute([(int) $item['quantidade'], (int) $item['ativo_id']]);
        add_history((int) $item['ativo_id'], 'Exclusao de compra', -(int) $item['quantidade'], 'Compra #' . $id . ' excluida.');
    }
    db()->prepare('DELETE FROM compras WHERE id = ?')->execute([$id]);
    db()->commit();
    $_SESSION['flash'] = 'Compra excluida e estoque ajustado.';
    redirect('estoque');
}

if ($page === 'exportar') {
    $rows = db()->query('
        SELECT a.codigo, a.nome, c.nome categoria, a.marca, a.modelo, a.quantidade_atual, a.estoque_minimo, a.estado, a.localizacao
        FROM ativos a LEFT JOIN categorias c ON c.id = a.categoria_id
        ORDER BY a.codigo
    ')->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="inventario.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, array_keys($rows[0] ?? ['codigo' => '']), ';');
    foreach ($rows as $row) {
        fputcsv($out, $row, ';');
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'importar') {
    require_inventory_unlocked('importar');
    if (empty($_FILES['arquivo_csv']['tmp_name']) || ($_FILES['arquivo_csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $_SESSION['flash'] = 'Selecione um arquivo CSV valido.';
        redirect('importar');
    }
    $handle = fopen($_FILES['arquivo_csv']['tmp_name'], 'r');
    $header = $handle ? fgetcsv($handle, 0, ';') : false;
    $expected = ['codigo', 'nome', 'categoria', 'quantidade'];
    $header = is_array($header) ? array_map(fn ($v) => strtolower(trim((string) $v)), $header) : [];
    if ($header !== $expected) {
        $_SESSION['flash'] = 'CSV invalido. Use o cabecalho: codigo;nome;categoria;quantidade';
        if ($handle) fclose($handle);
        redirect('importar');
    }
    try {
        db()->beginTransaction(); $count = 0;
        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            if (count($row) !== 4) throw new RuntimeException('Cada linha deve conter codigo, nome, categoria e quantidade.');
            [$codigo, $nome, $categoriaNome, $quantidade] = array_map(fn ($v) => trim((string) $v), $row);
            $qtd = (int) $quantidade;
            if ($codigo === '' || $nome === '' || $categoriaNome === '' || $qtd < 0) throw new RuntimeException('Existem dados invalidos no arquivo.');
            $cat = db()->prepare('SELECT id, tipo FROM categorias WHERE nome = ?'); $cat->execute([$categoriaNome]); $categoria = $cat->fetch();
            if (!$categoria || !categoria_no_escopo((int) $categoria['id'])) throw new RuntimeException('Categoria invalida ou fora do seu escopo: ' . $categoriaNome);
            $asset = db()->prepare('SELECT id FROM ativos WHERE codigo = ?'); $asset->execute([$codigo]); $ativoId = (int) ($asset->fetchColumn() ?: 0);
            if ($ativoId) {
                require_ativo_no_escopo($ativoId, 'importar');
                db()->prepare('UPDATE ativos SET nome=?, categoria_id=?, quantidade_atual=quantidade_atual+? WHERE id=?')->execute([$nome, $categoria['id'], $qtd, $ativoId]);
            } else {
                db()->prepare('INSERT INTO ativos (codigo,nome,categoria_id,quantidade_atual) VALUES (?,?,?,?)')->execute([$codigo, $nome, $categoria['id'], $qtd]);
                $ativoId = (int) db()->lastInsertId();
            }
            if ($qtd > 0) add_history($ativoId, 'Importacao CSV', $qtd, 'Entrada importada por CSV.');
            $count++;
        }
        fclose($handle); $handle = null; db()->commit(); $_SESSION['flash'] = $count . ' produto(s) importado(s) com sucesso.';
    } catch (Throwable $e) { if ($handle) fclose($handle); if (db()->inTransaction()) db()->rollBack(); $_SESSION['flash'] = $e->getMessage(); }
    redirect('importar');
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($config['app_name']) ?></title>
  <meta name="theme-color" content="#111827">
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='12' fill='%23111827'/%3E%3Ctext x='32' y='44' font-family='Arial,Helvetica,sans-serif' font-size='36' font-weight='700' text-anchor='middle' fill='%23FFFFFF'%3EN%3C/text%3E%3C/svg%3E">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="assets/style.css?v=<?= (int) @filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body>
<?php if ($page === 'login'): ?>
  <main class="login">
    <form method="post" class="card login-card">
      <h1>NEXUS</h1>
      <p>Login unico para administradores e usuarios</p>
      <?php if ($flash): ?><div class="alert"><?= e($flash) ?></div><?php endif; ?>
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
      <label>E-mail<input name="email" type="email" required autocomplete="email"></label>
      <label>Senha<input name="senha" type="password" required autocomplete="current-password"></label>
      <button>Entrar</button>
    </form>
  </main>

<?php elseif ($page === 'qr'):
    $codigo = trim($_GET['codigo'] ?? '');
    $stmt = db()->prepare('
        SELECT a.*, c.nome categoria
        FROM ativos a LEFT JOIN categorias c ON c.id = a.categoria_id
        WHERE a.codigo = ?
    ');
    $stmt->execute([$codigo]);
    $ativo = $stmt->fetch();
?>
  <main class="public-page">
    <?php if (!$ativo): ?>
      <section class="card"><h1>Ativo não encontrado.</h1></section>
    <?php else:
        $hist = db()->prepare('SELECT tipo, quantidade, descricao, created_at FROM historico_ativos WHERE ativo_id = ? ORDER BY id DESC');
        $hist->execute([(int) $ativo['id']]);
    ?>
      <section class="qr-profile">
        <?= image_tag($ativo['foto'] ?? null) ?>
        <div>
          <h1><?= e($ativo['codigo']) ?> - <?= e($ativo['nome']) ?></h1>
          <p><?= e($ativo['categoria'] ?? 'Sem categoria') ?> | <?= e($ativo['localizacao'] ?? '') ?> | <?= e($ativo['estado'] ?? '') ?></p>
          <p>Estoque atual: <?= e($ativo['quantidade_atual']) ?> | Cadastrado em: <?= e(date('d/m/Y H:i', strtotime($ativo['created_at']))) ?></p>
          <?php if (!empty($ativo['observacoes'])): ?><p><?= e($ativo['observacoes']) ?></p><?php endif; ?>
        </div>
      </section>
      <section class="card">
        <h2>Historico completo</h2>
        <?php table_view($hist->fetchAll()); ?>
      </section>
    <?php endif; ?>
  </main>

<?php elseif (in_array($page, ['abrir-requisicao', 'minhas-requisicoes'], true)): ?>
  <?php render_topbar('usuario', ['abrir-requisicao' => 'Abrir requisicao', 'minhas-requisicoes' => 'Minhas requisicoes']); ?>
  <main class="workspace">
    <?php if ($page === 'abrir-requisicao'):
        $userData = get_user_data((int) current_user()['id']);
        $ativosDisponiveis = db()->query('SELECT a.id, a.codigo, a.nome, c.nome AS categoria, a.quantidade_atual FROM ativos a LEFT JOIN categorias c ON c.id = a.categoria_id ORDER BY c.nome, a.nome, a.codigo')->fetchAll();
    ?>
      <h1>Abrir Requisicao</h1>
      <?php if ($flash): ?><div class="alert-success"><?= e($flash) ?></div><?php endif; ?>
      <section class="card">
        <form method="post" action="index.php?page=salvar-requisicao" class="req-form">
          <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
          <div><span class="label">Matricula</span><strong><?= e($userData['matricula'] ?? '') ?></strong></div>
          <div><span class="label">CPF</span><strong><?= e($userData['cpf'] ?? '') ?></strong></div>
          <div><span class="label">Nome</span><strong><?= e($userData['nome'] ?? '') ?></strong></div>
          <div><span class="label">Departamento</span><strong><?= e($userData['departamento'] ?? '') ?></strong></div>
           <label>Tipo de Equipamento<select name="ativo_id" required><option value="">Selecione</option><?php foreach ($ativosDisponiveis as $ativo): ?><option value="<?= e($ativo['id']) ?>"><?= e(($ativo['categoria'] ? $ativo['categoria'] . ' - ' : '') . $ativo['nome'] . ' (' . $ativo['codigo'] . ')') ?></option><?php endforeach; ?></select></label>
           <label class="wide">Descricao da solicitacao<textarea name="descricao" required></textarea></label>
          <button>Enviar requisicao</button>
        </form>
      </section>
    <?php else:
        $stmt = db()->prepare('SELECT r.codigo_requisicao, r.data_solicitacao, r.hora_solicitacao, r.descricao, r.status, a.nome AS equipamento FROM requisicoes r LEFT JOIN ativos a ON a.id = r.ativo_id WHERE r.usuario_id = ? ORDER BY r.id DESC');
        $stmt->execute([current_user()['id']]);
        $minhas = $stmt->fetchAll();
    ?>
      <h1>Minhas Requisicoes</h1>
      <?php if ($flash): ?><div class="alert-success"><?= e($flash) ?></div><?php endif; ?>
      <section class="card">
        <div class="toolbar"><input class="table-search" data-target="req-user-table" placeholder="Pesquisar requisicoes"></div>
        <div class="table-wrap">
          <table id="req-user-table" class="data-table">
            <thead><tr><th>Codigo</th><th>Data</th><th>Hora</th><th>Equipamento</th><th>Descricao</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($minhas as $req): ?>
              <tr>
                <td><?= e($req['codigo_requisicao']) ?></td>
                <td><?= e(date('d/m/Y', strtotime($req['data_solicitacao']))) ?></td>
                <td><?= e(date('H:i', strtotime($req['hora_solicitacao']))) ?></td>
                <td><?= e($req['equipamento'] ?? '-') ?></td><td><?= e($req['descricao']) ?></td>
                <td><?= render_status_badge($req['status']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endif; ?>
  </main>

<?php else:
    $menuLinks = [];
    if (current_profile() === 'administrador') {
        $menuLinks = [
            'dashboard' => 'Painel',
            'Inventario' => ['estoque' => 'Estoque', 'purchase-requests' => 'Solicitacoes de compra', 'inventory' => 'Auditoria de inventario'],
            'Operacoes' => ['movimentacoes' => 'Movimentacoes', 'ativos' => 'Ativos', 'qrcode' => 'QR Codes', 'categorias' => 'Categorias'],
            'requisicoes' => 'Requisicoes',
            'hiring' => 'Contratacoes',
            'Relatorios' => ['inventory-reports' => 'Relatorios de compra', 'movimentacoes' => 'Relatorio de itens em uso', 'importar' => 'Importar CSV', 'exportar' => 'Exportar CSV'],
        ];
    } elseif (current_profile() === 'gestor') {
        $menuLinks = [
            'dashboard' => 'Painel',
            'Inventario' => ['estoque' => 'Estoque'],
            'Operacoes' => ['movimentacoes' => 'Movimentacoes', 'ativos' => 'Ativos', 'qrcode' => 'QR Codes', 'categorias' => 'Categorias'],
            'requisicoes' => 'Requisicoes',
            'Relatorios' => ['movimentacoes' => 'Relatorio de itens em uso', 'importar' => 'Importar CSV', 'exportar' => 'Exportar CSV'],
        ];
    } elseif (current_profile() === 'diretoria') {
        $menuLinks = [
            'dashboard' => 'Painel',
            'requisicoes' => 'Requisicoes',
        ];
    } elseif (current_profile() === 'coordenacao_ti') {
        $menuLinks = ['dashboard' => 'Painel', 'requisicoes-ti' => 'Requisicoes TI', 'it-dashboard' => 'Aprovacoes TI'];
    } elseif (current_profile() === 'financeiro') {
        $menuLinks = ['dashboard' => 'Painel', 'finance-dashboard' => 'Compras'];
    } elseif (current_profile() === 'rh') {
        $menuLinks = ['rh-dashboard' => 'Equipamentos', 'hiring' => 'Contratacoes'];
    }
    ?>
  <?php render_topbar('administrador', $menuLinks); ?>
  <main class="workspace">
    <?php if (nova_render_page($page, $flash)): ?>
    <?php elseif ($page === 'dashboard' && current_profile() === 'rh'):
        $hrRows = db()->query("SELECT nome, quantidade_atual FROM ativos WHERE LOWER(nome) REGEXP 'notebook|desktop|monitor|headset|dock' ORDER BY nome")->fetchAll();
    ?>
      <h1>Painel RH</h1>
      <?php if ($flash): ?><div class="alert-success"><?= e($flash) ?></div><?php endif; ?>
      <section class="metrics"><?php foreach ($hrRows as $item): ?><div class="metric"><span><?= e($item['nome']) ?></span><strong><?= e($item['quantidade_atual']) ?></strong></div><?php endforeach; ?></section>
      <?php if (!$hrRows): ?><section class="card"><p>Nenhum dos itens disponiveis foi encontrado.</p></section><?php endif; ?>
    <?php elseif ($page === 'dashboard'):
        [$scopeSql, $scopeParams] = categoria_scope_sql('a.categoria_id');

        $totalAtivosStmt = db()->prepare("SELECT COUNT(*) FROM ativos a WHERE 1=1{$scopeSql}");
        $totalAtivosStmt->execute($scopeParams);

        $disponiveisStmt = db()->prepare("SELECT COALESCE(SUM(a.quantidade_atual),0) FROM ativos a WHERE 1=1{$scopeSql}");
        $disponiveisStmt->execute($scopeParams);

        $criticoStmt = db()->prepare("SELECT COUNT(*) FROM ativos a WHERE a.quantidade_atual <= a.estoque_minimo{$scopeSql}");
        $criticoStmt->execute($scopeParams);

        // Itens em uso: agregados a partir das saidas registradas para um colaborador (sem retorno explicito no modelo atual).
        $emUsoQtdStmt = db()->prepare("
            SELECT COALESCE(SUM(-m.quantidade), 0)
            FROM movimentacoes m
            JOIN ativos a ON a.id = m.ativo_id
            WHERE m.tipo = 'Saida' AND m.colaborador_recebe IS NOT NULL AND m.colaborador_recebe <> ''{$scopeSql}
        ");
        $emUsoQtdStmt->execute($scopeParams);

        $cards = [
            'Total de ativos' => $totalAtivosStmt->fetchColumn(),
            'Operacoes' => $emUsoQtdStmt->fetchColumn(),
            'Estoque critico' => $criticoStmt->fetchColumn(),
        ];

        if (!is_gestor()) {
            $cards['Requisicoes em analise'] = db()->query("SELECT COUNT(*) FROM requisicoes WHERE status='Em análise'")->fetchColumn();
            $cards['Em processo de compra'] = db()->query("SELECT COUNT(*) FROM requisicoes WHERE status='Em processo de compra'")->fetchColumn();
            $cards['Requisicoes negadas'] = db()->query("SELECT COUNT(*) FROM requisicoes WHERE status='Negado'")->fetchColumn();
            $cards['Requisicoes concluidas'] = db()->query("SELECT COUNT(*) FROM requisicoes WHERE status='Concluído'")->fetchColumn();
        }

        $criticosStmt = db()->prepare("SELECT a.codigo, a.nome, a.quantidade_atual, a.estoque_minimo FROM ativos a WHERE a.quantidade_atual <= a.estoque_minimo{$scopeSql} ORDER BY a.quantidade_atual ASC");
        $criticosStmt->execute($scopeParams);
        $criticos = $criticosStmt->fetchAll();

        // Itens disponiveis em estoque (quantidade atual > 0)
        $disponiveisListaStmt = db()->prepare("
            SELECT a.codigo, a.nome, a.quantidade_atual AS quantidade_disponivel
            FROM ativos a
            WHERE a.quantidade_atual > 0{$scopeSql}
            ORDER BY a.codigo
        ");
        $disponiveisListaStmt->execute($scopeParams);
        $disponiveisLista = $disponiveisListaStmt->fetchAll();

        // Itens atualmente em uso: agrupados por ativo + colaborador que recebeu, somando saidas registradas.
        $emUsoStmt = db()->prepare("
            SELECT
                a.codigo,
                a.nome,
                m.colaborador_recebe AS colaborador,
                SUM(-m.quantidade) AS quantidade_atribuida,
                MAX(m.created_at) AS data_atribuicao
            FROM movimentacoes m
            JOIN ativos a ON a.id = m.ativo_id
            WHERE m.tipo = 'Saida' AND m.colaborador_recebe IS NOT NULL AND m.colaborador_recebe <> ''{$scopeSql}
            GROUP BY a.id, a.codigo, a.nome, m.colaborador_recebe
            HAVING SUM(-m.quantidade) > 0
            ORDER BY data_atribuicao DESC
        ");
        $emUsoStmt->execute($scopeParams);
        $emUsoLista = array_map(function ($row) {
            $row['data_atribuicao'] = date('d/m/Y H:i', strtotime($row['data_atribuicao']));
            return $row;
        }, $emUsoStmt->fetchAll());
    ?>
      <h1>Painel</h1>
      <?php if ($flash): ?><div class="alert-success"><?= e($flash) ?></div><?php endif; ?>
      <section class="metrics"><?php foreach ($cards as $label => $value): ?><div class="metric"><span><?= e($label) ?></span><strong><?= e($value) ?></strong></div><?php endforeach; ?></section>
      <section class="card"><h2>Estoque critico</h2><?php table_view($criticos); ?></section>
      <section class="card"><h2>Operacoes</h2><div class="toolbar"><input class="table-search" data-target="operations-table" placeholder="Pesquisar operacoes"></div><?php table_view($emUsoLista, 'Sem itens.', 'operations-table'); ?></section>
      <section class="card"><h2>Itens disponiveis</h2><div class="toolbar"><input class="table-search" data-target="available-items-table" placeholder="Pesquisar itens disponiveis"></div><?php table_view($disponiveisLista, 'Sem itens.', 'available-items-table'); ?></section>
      <section class="card"><h2>Relatorio de itens em uso</h2><?php table_view($emUsoLista); ?></section>

    <?php elseif ($page === 'estoque'):
        [$scopeSql, $scopeParams] = categoria_scope_sql('a.categoria_id');
        $ativosStmt = db()->prepare("SELECT a.id, a.codigo, a.nome, a.observacoes, a.quantidade_atual, a.estoque_minimo FROM ativos a WHERE 1=1{$scopeSql} ORDER BY a.codigo");
        $ativosStmt->execute($scopeParams);
        $ativos = $ativosStmt->fetchAll();
        if (categoria_tipos_permitidos() === null) {
            $compras = db()->query('SELECT id, numero_nf, razao_social, data_entrada, valor_total FROM compras ORDER BY id DESC LIMIT 20')->fetchAll();
        } else {
            $allowedIds = categorias_permitidas_ids();
            $idsPlaceholder = $allowedIds ? implode(',', array_fill(0, count($allowedIds), '?')) : '0';
            $comprasStmt = db()->prepare("
                SELECT DISTINCT co.id, co.numero_nf, co.razao_social, co.data_entrada, co.valor_total
                FROM compras co
                JOIN compra_itens ci ON ci.compra_id = co.id
                JOIN ativos a ON a.id = ci.ativo_id
                WHERE a.categoria_id IN ({$idsPlaceholder})
                ORDER BY co.id DESC LIMIT 20
            ");
            $comprasStmt->execute($allowedIds ?: []);
            $compras = $comprasStmt->fetchAll();
        }
    ?>
      <h1>Estoque</h1>
      <?php if ($flash): ?><div class="alert-success"><?= e($flash) ?></div><?php endif; ?>
      <section class="card">
        <div class="toolbar">
          <input class="table-search" data-target="estoque-table" placeholder="Pesquisar por codigo, nome ou descricao">
          <button type="button" data-open-modal="compra-modal">Nova Compra</button>
        </div>
        <div class="table-wrap">
          <table id="estoque-table" class="data-table">
            <thead><tr><th>Codigo</th><th>Nome</th><th>Quantidade disponivel</th><th>Estoque minimo</th><th>Descricao</th></tr></thead>
            <tbody>
            <?php foreach ($ativos as $a): ?>
              <tr><td><?= e($a['codigo']) ?></td><td><?= e($a['nome']) ?></td><td><?= e($a['quantidade_atual']) ?></td><td><?= e($a['estoque_minimo']) ?></td><td><?= e($a['observacoes']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
      <section class="card">
        <h2>Ultimas compras</h2>
        <div class="table-wrap">
          <table class="data-table" id="compras-table">
            <thead><tr><th>NF</th><th>Razao Social</th><th>Entrada</th><th>Total</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($compras as $c): ?>
              <tr>
                <td><?= e($c['numero_nf']) ?></td>
                <td><?= e($c['razao_social']) ?></td>
                <td><?= e(date('d/m/Y', strtotime($c['data_entrada']))) ?></td>
                <td><?= e(money_br($c['valor_total'])) ?></td>
                <td class="actions"><a class="mini danger" href="index.php?page=excluir-compra&id=<?= e($c['id']) ?>" onclick="return confirm('Excluir esta compra e ajustar o estoque?')">Excluir Compra</a></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>

      <div class="modal" id="compra-modal" aria-hidden="true">
        <div class="modal-panel wide-modal">
          <button type="button" class="modal-close" data-close-modal>&times;</button>
          <h2>Nova Compra</h2>
          <form method="post" action="index.php?page=salvar-compra" enctype="multipart/form-data" class="modal-form">
            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
            <div class="form-grid">
              <label>CNPJ<input name="cnpj" required></label>
              <label>Razao Social<input name="razao_social" required></label>
              <label>Chave de Acesso<input name="chave_acesso"></label>
              <label>Numero da NF<input name="numero_nf" required></label>
              <label>Data de Emissao<input type="date" name="data_emissao"></label>
              <label>Data de Entrada<input type="date" name="data_entrada" value="<?= e(date('Y-m-d')) ?>" required></label>
            </div>
            <h3>Itens da compra</h3>
            <div id="purchase-items"></div>
            <button type="button" class="secondary" id="add-purchase-item">Adicionar item</button>
            <div class="table-wrap compact"><table><thead><tr><th>Codigo</th><th>Descricao</th><th>Quantidade</th><th>Valor Unitario</th><th>Valor Total</th></tr></thead><tbody id="purchase-preview"></tbody></table></div>
            <label>Observacao<textarea name="observacao"></textarea></label>
            <label>Upload de Nota Fiscal (PDF ou XML)<input type="file" name="nota_fiscal_arquivo" accept=".pdf,.xml,application/pdf,text/xml"></label>
            <div class="form-actions">
              <button>Salvar Compra</button>
              <button type="button" class="danger" data-close-modal>Excluir Compra</button>
            </div>
          </form>
        </div>
      </div>

    <?php elseif ($page === 'importar'): ?>
      <h1>Importar produtos</h1>
      <?php if ($flash): ?><div class="alert-success"><?= e($flash) ?></div><?php endif; ?>
      <section class="card">
        <p>Envie um CSV separado por ponto e virgula. O cabecalho obrigatorio e <code>codigo;nome;categoria;quantidade</code>. Categorias devem existir previamente.</p>
        <form method="post" action="index.php?page=importar" enctype="multipart/form-data" class="asset-form">
          <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
          <label>Arquivo CSV<input type="file" name="arquivo_csv" accept=".csv,text/csv" required></label>
          <button>Importar</button>
        </form>
      </section>

    <?php elseif ($page === 'categorias'):
        $edit = null;
        if (!empty($_GET['id'])) {
            if (is_gestor() && !categoria_no_escopo((int) $_GET['id'])) {
                $_SESSION['flash'] = 'Voce nao tem permissao para acessar esta categoria.';
                redirect('categorias');
            }
            $stmt = db()->prepare('SELECT * FROM categorias WHERE id = ?');
            $stmt->execute([(int) $_GET['id']]);
            $edit = $stmt->fetch();
        }
        [$catScopeSql, $catScopeParams] = categoria_scope_sql('id');
        $rowsStmt = db()->prepare("SELECT * FROM categorias WHERE 1=1{$catScopeSql} ORDER BY nome");
        $rowsStmt->execute($catScopeParams);
        $rows = $rowsStmt->fetchAll();
    ?>
      <h1>Categorias</h1>
      <?php if ($flash): ?><div class="alert-success"><?= e($flash) ?></div><?php endif; ?>
      <section class="grid2">
        <form class="card" method="post" action="index.php?page=salvar-categoria">
          <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
          <input type="hidden" name="id" value="<?= e($edit['id'] ?? '') ?>">
          <label>Nome<input name="nome" value="<?= e($edit['nome'] ?? '') ?>" required></label>
          <label>Descricao<textarea name="descricao"><?= e($edit['descricao'] ?? '') ?></textarea></label>
          <label>Tipo (escopo de acesso)
            <select name="tipo">
              <?php $tipoAtual = $edit['tipo'] ?? (is_gestor() ? 'limpeza' : 'ti'); $tiposCategoria = is_gestor() ? ['limpeza' => 'Limpeza', 'escritorio' => 'Escritorio / Material de Escritorio'] : ['ti' => 'TI (Tecnologia da Informacao)', 'limpeza' => 'Limpeza', 'escritorio' => 'Escritorio / Material de Escritorio']; foreach ($tiposCategoria as $val => $label): ?>
              <option value="<?= e($val) ?>" <?= $tipoAtual === $val ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <button>Salvar</button>
        </form>
        <section class="card">
          <div class="toolbar"><input class="table-search" data-target="categorias-table" placeholder="Pesquisar categorias"></div>
          <div class="table-wrap"><table id="categorias-table" class="data-table"><thead><tr><th>Nome</th><th>Tipo</th><th>Descricao</th><th></th></tr></thead><tbody>
            <?php $tipoLabels = ['ti' => 'TI', 'limpeza' => 'Limpeza', 'escritorio' => 'Escritorio'];
            foreach ($rows as $r): ?><tr><td><?= e($r['nome']) ?></td><td><?= e($tipoLabels[$r['tipo'] ?? 'ti'] ?? $r['tipo']) ?></td><td><?= e($r['descricao']) ?></td><td class="actions"><a class="mini" href="index.php?page=categorias&id=<?= e($r['id']) ?>">Editar</a><a class="mini danger" href="index.php?page=excluir-categoria&id=<?= e($r['id']) ?>" onclick="return confirm('Excluir categoria?')">Excluir</a></td></tr><?php endforeach; ?>
          </tbody></table></div>
        </section>
      </section>

    <?php elseif ($page === 'ativos'):
        $defaultCategoriaId = is_gestor() ? (categorias_permitidas_ids()[0] ?? null) : null;
        $ativo = ['codigo' => next_code('ativos', 'codigo', 'MCX'), 'estado' => 'Bom', 'estoque_minimo' => 1, 'categoria_id' => $defaultCategoriaId];
        if (!empty($_GET['id'])) {
            require_ativo_no_escopo((int) $_GET['id'], 'ativos');
            $stmt = db()->prepare('SELECT * FROM ativos WHERE id = ?');
            $stmt->execute([(int) $_GET['id']]);
            $ativo = $stmt->fetch() ?: $ativo;
        }
        [$catScopeSql, $catScopeParams] = categoria_scope_sql('id');
        $categoriasStmt = db()->prepare("SELECT * FROM categorias WHERE 1=1{$catScopeSql} ORDER BY nome");
        $categoriasStmt->execute($catScopeParams);
        $categorias = $categoriasStmt->fetchAll();
        [$scopeSql, $scopeParams] = categoria_scope_sql('a.categoria_id');
        $rowsStmt = db()->prepare("SELECT a.*, c.nome categoria FROM ativos a LEFT JOIN categorias c ON c.id = a.categoria_id WHERE 1=1{$scopeSql} ORDER BY a.codigo");
        $rowsStmt->execute($scopeParams);
        $rows = $rowsStmt->fetchAll();
    ?>
      <h1>Ativos</h1>
      <?php if ($flash): ?><div class="alert-success"><?= e($flash) ?></div><?php endif; ?>
      <section class="card">
        <div class="toolbar">
          <input class="table-search" data-target="ativos-table" placeholder="Pesquisar por codigo, nome ou categoria">
          <button type="button" data-open-modal="ativo-modal">Novo Ativo</button>
        </div>
        <div class="table-wrap"><table id="ativos-table" class="data-table"><thead><tr><th>Codigo</th><th>Nome</th><th>Categoria</th><th>Estoque</th><th>Estado</th><th>Localizacao</th><th></th></tr></thead><tbody>
          <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= e($r['codigo']) ?></td><td><?= e($r['nome']) ?></td><td><?= e($r['categoria']) ?></td><td><?= e($r['quantidade_atual']) ?></td><td><?= e($r['estado']) ?></td><td><?= e($r['localizacao']) ?></td>
            <td class="actions">
              <a class="mini" href="index.php?page=ativos&id=<?= e($r['id']) ?>">Editar</a>
              <a class="mini danger" href="index.php?page=excluir-ativo&id=<?= e($r['id']) ?>" onclick="return confirm('Excluir ativo?')">Excluir</a>
              <button type="button" class="mini" data-open-modal="historico-<?= e($r['id']) ?>">Historico</button>
              <button type="button" class="mini" data-open-modal="qr-<?= e($r['id']) ?>">QR Code</button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody></table></div>
      </section>

      <div class="modal <?= !empty($_GET['id']) ? 'is-open' : '' ?>" id="ativo-modal" aria-hidden="<?= !empty($_GET['id']) ? 'false' : 'true' ?>">
        <div class="modal-panel wide-modal">
          <a class="modal-close" href="index.php?page=ativos">&times;</a>
          <h2><?= !empty($ativo['id']) ? 'Editar Ativo' : 'Novo Ativo' ?></h2>
          <form class="asset-form" method="post" action="index.php?page=salvar-ativo" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
            <input type="hidden" name="id" value="<?= e($ativo['id'] ?? '') ?>">
            <label>Codigo<input name="codigo" value="<?= e($ativo['codigo'] ?? '') ?>" required></label>
            <label>Categoria<select name="categoria_id"><?php if (!is_gestor()): ?><option value="">Sem categoria</option><?php endif; ?><?php foreach ($categorias as $c): ?><option value="<?= e($c['id']) ?>" <?= (($ativo['categoria_id'] ?? '') == $c['id']) ? 'selected' : '' ?>><?= e($c['nome']) ?></option><?php endforeach; ?></select></label>
            <label>Nome<input name="nome" value="<?= e($ativo['nome'] ?? '') ?>" required></label>
            <label>Marca<input name="marca" value="<?= e($ativo['marca'] ?? '') ?>"></label>
            <label>Modelo<input name="modelo" value="<?= e($ativo['modelo'] ?? '') ?>"></label>
            <label>Fornecedor<input name="fornecedor" value="<?= e($ativo['fornecedor'] ?? '') ?>"></label>
            <label>Nota Fiscal<input name="nota_fiscal" value="<?= e($ativo['nota_fiscal'] ?? '') ?>"></label>
            <label>Valor<input type="number" step="0.01" name="valor_compra" value="<?= e($ativo['valor_compra'] ?? '') ?>"></label>
            <label>Data de compra<input type="date" name="data_compra" value="<?= e($ativo['data_compra'] ?? '') ?>"></label>
            <label>Garantia<input type="date" name="garantia" value="<?= e($ativo['garantia'] ?? '') ?>"></label>
            <label>Estoque minimo<input type="number" name="estoque_minimo" value="<?= e($ativo['estoque_minimo'] ?? 1) ?>"></label>
            <label>Estado<select name="estado"><?php foreach (['Otimo','Bom','Medio','Ruim','Manutencao','Inutilizado'] as $estado): ?><option <?= (($ativo['estado'] ?? '') === $estado) ? 'selected' : '' ?>><?= e($estado) ?></option><?php endforeach; ?></select></label>
            <label>Localizacao<input name="localizacao" value="<?= e($ativo['localizacao'] ?? '') ?>"></label>
            <label>Foto do Ativo<input type="file" name="foto" accept="image/png,image/jpeg,image/webp"></label>
            <label class="wide">Observacoes<textarea name="observacoes"><?= e($ativo['observacoes'] ?? '') ?></textarea></label>
            <button>Salvar ativo</button>
          </form>
        </div>
      </div>

      <?php foreach ($rows as $r):
          $hist = db()->prepare('SELECT tipo, quantidade, descricao, created_at FROM historico_ativos WHERE ativo_id = ? ORDER BY id DESC');
          $hist->execute([(int) $r['id']]);
          $url = rtrim($config['base_url'], '/') . '/index.php?page=qr&codigo=' . urlencode($r['codigo']);
          $qr = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . urlencode($url);
      ?>
      <div class="modal" id="historico-<?= e($r['id']) ?>" aria-hidden="true"><div class="modal-panel wide-modal"><button type="button" class="modal-close" data-close-modal>&times;</button><h2>Historico do Ativo</h2><div class="asset-detail"><?= image_tag($r['foto'] ?? null) ?><div><h3><?= e($r['codigo']) ?> - <?= e($r['nome']) ?></h3><p><?= e($r['categoria']) ?> | <?= e($r['localizacao']) ?> | <?= e($r['estado']) ?></p><p><?= e($r['observacoes']) ?></p></div></div><?php table_view($hist->fetchAll()); ?></div></div>
      <div class="modal" id="qr-<?= e($r['id']) ?>" aria-hidden="true"><div class="modal-panel qr-modal"><button type="button" class="modal-close" data-close-modal>&times;</button><h2>QR Code</h2><img class="qr-img" src="<?= e($qr) ?>" alt="QR Code"><a class="mini" href="<?= e($url) ?>" target="_blank">Abrir pagina publica</a></div></div>
      <?php endforeach; ?>

    <?php elseif ($page === 'movimentacoes'):
        [$scopeSqlA, $scopeParamsA] = categoria_scope_sql('categoria_id');
        $ativosStmt = db()->prepare("SELECT id, codigo, nome, quantidade_atual, estoque_minimo, estoque_maximo FROM ativos WHERE 1=1{$scopeSqlA} ORDER BY codigo");
        $ativosStmt->execute($scopeParamsA);
        $ativos = $ativosStmt->fetchAll();
        [$scopeSqlM, $scopeParamsM] = categoria_scope_sql('a.categoria_id');
        $rowsStmt = db()->prepare("
            SELECT m.id, m.ativo_id, m.created_at, a.codigo, a.nome ativo, m.tipo, m.quantidade, m.colaborador_recebe, m.funcionario_entrega, m.origem, m.solicitacao_id, m.observacao
            FROM movimentacoes m JOIN ativos a ON a.id = m.ativo_id
            WHERE 1=1{$scopeSqlM}
            ORDER BY m.id DESC
            LIMIT 200
        ");
        $rowsStmt->execute($scopeParamsM);
        $rows = $rowsStmt->fetchAll();
    ?>
      <h1>Movimentacoes</h1>
      <?php if ($flash): ?><div class="alert-success"><?= e($flash) ?></div><?php endif; ?>
      <section class="card">
        <div class="toolbar"><input class="table-search" data-target="mov-table" placeholder="Pesquisar por colaborador, data ou ativo"><button type="button" data-open-modal="mov-modal">Nova Movimentacao</button></div>
        <div class="table-wrap"><table id="mov-table" class="data-table"><thead><tr><th>Data</th><th>Ativo</th><th>Tipo</th><th>Quantidade</th><th>Colaborador</th><th>Entregue por</th><th>Origem</th><th>Observacao</th><th></th></tr></thead><tbody>
          <?php foreach ($rows as $r): ?><tr><td><?= e(date('d/m/Y H:i', strtotime($r['created_at']))) ?></td><td><?= e($r['codigo'] . ' - ' . $r['ativo']) ?></td><td><?= e($r['tipo']) ?></td><td><?= e($r['quantidade']) ?></td><td><?= e($r['colaborador_recebe']) ?></td><td><?= e($r['funcionario_entrega']) ?></td><td><?= e($r['origem'] ?: '-') ?><?= $r['solicitacao_id'] ? ' #' . e($r['solicitacao_id']) : '' ?></td><td><?= e($r['observacao']) ?></td><td><button type="button" class="mini" data-open-modal="mov-edit-<?= e($r['id']) ?>">Editar</button></td></tr><?php endforeach; ?>
        </tbody></table></div>
      </section>
      <div class="modal" id="mov-modal" aria-hidden="true"><div class="modal-panel"><button type="button" class="modal-close" data-close-modal>&times;</button><h2>Nova Movimentacao</h2><form method="post" action="index.php?page=movimentar" class="modal-form"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><label>Ativo<select id="movement-asset" name="ativo_id"><?php foreach ($ativos as $a): ?><option value="<?= e($a['id']) ?>"><?= e($a['codigo'] . ' - ' . $a['nome']) ?></option><?php endforeach; ?></select></label><div class="card" id="stock-summary">Selecione um ativo para consultar o estoque.</div><label>Tipo<select name="tipo"><option>Entrada</option><option>Saida</option><option>Ajuste</option></select></label><label>Quantidade<input type="number" name="quantidade" value="1" required></label><label>Colaborador que recebe<input name="colaborador_recebe"></label><label>Funcionario que entrega<input name="funcionario_entrega" value="<?= e(current_user()['nome'] ?? '') ?>"></label><label>Observacao<textarea name="observacao"></textarea></label><button>Registrar</button></form></div></div>
      <?php foreach ($rows as $r): ?><div class="modal" id="mov-edit-<?= e($r['id']) ?>" aria-hidden="true"><div class="modal-panel"><button type="button" class="modal-close" data-close-modal>&times;</button><h2>Editar Movimentacao</h2><form method="post" action="index.php?page=salvar-edicao-movimentacao" class="modal-form"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= e($r['id']) ?>"><input type="hidden" name="ativo_id" value="<?= e($r['ativo_id']) ?>"><p><strong>Ativo:</strong> <?= e($r['codigo'] . ' - ' . $r['ativo']) ?></p><label>Tipo<select name="tipo"><?php foreach (['Entrada','Saida','Ajuste'] as $tipo): ?><option value="<?= e($tipo) ?>" <?= $r['tipo'] === $tipo ? 'selected' : '' ?>><?= e($tipo) ?></option><?php endforeach; ?></select></label><label>Quantidade<input type="number" name="quantidade" value="<?= e($r['tipo'] === 'Ajuste' ? (int) $r['quantidade'] : abs((int) $r['quantidade'])) ?>" required></label><label>Colaborador que recebe<input name="colaborador_recebe" value="<?= e($r['colaborador_recebe']) ?>"></label><label>Funcionario que entrega<input name="funcionario_entrega" value="<?= e($r['funcionario_entrega']) ?>"></label><label>Observacao<textarea name="observacao"><?= e($r['observacao']) ?></textarea></label><button>Salvar alteracoes</button></form></div></div><?php endforeach; ?>

    <?php elseif ($page === 'requisicoes'):
        if (is_diretoria()) {
            $rows = db()->query("SELECT * FROM requisicoes WHERE status = 'Em processo de compra' ORDER BY id DESC")->fetchAll();
        } else {
            $rows = db()->query('SELECT * FROM requisicoes ORDER BY id DESC')->fetchAll();
        }
        $statusOptions = ['Em análise', 'Em processo de compra', 'Dependente', 'Negado', 'Concluído'];
    ?>
      <h1>Requisicoes<?= is_diretoria() ? ' - Em processo de compra' : '' ?></h1>
      <?php if ($flash): ?><div class="alert-success"><?= e($flash) ?></div><?php endif; ?>
      <section class="card">
        <div class="toolbar"><input class="table-search" data-target="req-admin-table" placeholder="Pesquisar requisicoes"></div>
        <div class="table-wrap"><table id="req-admin-table" class="data-table"><thead><tr><th>Codigo</th><th>Data</th><th>Solicitante</th><th>Departamento</th><th>Status</th><?php if (!is_diretoria()): ?><th></th><?php endif; ?></tr></thead><tbody>
          <?php foreach ($rows as $req): ?>
          <tr><td><?= e($req['codigo_requisicao']) ?></td><td><?= e(date('d/m/Y H:i', strtotime($req['data_solicitacao'] . ' ' . $req['hora_solicitacao']))) ?></td><td><?= e($req['nome']) ?></td><td><?= e($req['departamento']) ?></td><td><?= render_status_badge($req['status']) ?></td><?php if (!is_diretoria()): ?><td class="actions"><button type="button" class="mini" data-open-modal="req-<?= e($req['id']) ?>">Editar</button><a class="mini danger" href="index.php?page=excluir-requisicao&id=<?= e($req['id']) ?>" onclick="return confirm('Excluir requisicao?')">Excluir</a></td><?php endif; ?></tr>
          <?php endforeach; ?>
        </tbody></table></div>
      </section>
      <?php if (!is_diretoria()): foreach ($rows as $req): ?><div class="modal" id="req-<?= e($req['id']) ?>" aria-hidden="true"><div class="modal-panel wide-modal"><button type="button" class="modal-close" data-close-modal>&times;</button><h2>Editar Requisicao</h2><form method="post" action="index.php?page=salvar-edicao-requisicao" class="modal-form"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= e($req['id']) ?>"><div class="form-grid"><div><span class="label">Matricula</span><strong><?= e($req['matricula']) ?></strong></div><div><span class="label">CPF</span><strong><?= e($req['cpf']) ?></strong></div><div><span class="label">Nome</span><strong><?= e($req['nome']) ?></strong></div><div><span class="label">Departamento</span><strong><?= e($req['departamento']) ?></strong></div><label>Status<select name="status"><?php foreach ($statusOptions as $st): ?><option value="<?= e($st) ?>" <?= $req['status'] === $st ? 'selected' : '' ?>><?= e($st) ?></option><?php endforeach; ?></select></label><label>Aprovado por<input name="aprovado_por" value="<?= e($req['aprovado_por']) ?>"></label></div><label>Descricao<textarea name="descricao"><?= e($req['descricao']) ?></textarea></label><button>Salvar</button></form></div></div><?php endforeach; endif; ?>
    <?php endif; ?>
  </main>
<?php endif; ?>

<script>
<?php
$perfil = current_user()['perfil'] ?? '';
if (in_array($perfil, ['administrador', 'gestor'], true)) {
    [$scopeSqlJs, $scopeParamsJs] = categoria_scope_sql('categoria_id');
    $jsStmt = db()->prepare("SELECT id, codigo, nome, observacoes FROM ativos WHERE 1=1{$scopeSqlJs} ORDER BY codigo");
    $jsStmt->execute($scopeParamsJs);
    $jsAtivos = array_map(fn ($a) => ['id' => $a['id'], 'codigo' => $a['codigo'], 'nome' => $a['nome'], 'descricao' => $a['observacoes'] ?? ''], $jsStmt->fetchAll());
} else {
    $jsAtivos = [];
}
?>
const assetOptions = <?= json_encode($jsAtivos, JSON_UNESCAPED_UNICODE) ?>;

document.querySelectorAll('main h1').forEach((title) => {
  if (!title.dataset.title) {
    title.dataset.title = title.textContent.trim();
  }
});

document.querySelectorAll('[data-open-modal]').forEach((btn) => {
  btn.addEventListener('click', () => {
    const modal = document.getElementById(btn.dataset.openModal);
    if (modal) {
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
    }
  });
});
document.querySelectorAll('[data-close-modal]').forEach((btn) => {
  btn.addEventListener('click', () => {
    const modal = btn.closest('.modal');
    if (modal) {
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
    }
  });
});
document.querySelectorAll('.modal').forEach((modal) => {
  modal.addEventListener('click', (event) => {
    if (event.target === modal) {
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
    }
  });
});

document.querySelectorAll('.table-search').forEach((input) => {
  input.addEventListener('input', () => {
    const table = document.getElementById(input.dataset.target);
    const term = input.value.toLocaleLowerCase();
    if (!table) return;
    table.querySelectorAll('tbody tr').forEach((row) => {
      row.hidden = !row.textContent.toLocaleLowerCase().includes(term);
    });
    paginate(table);
  });
});


document.querySelectorAll('.table-filter').forEach((filter) => {
  filter.addEventListener('input', () => {
    const table = document.getElementById(filter.dataset.target);
    if (!table) return;
    const filters = Array.from(document.querySelectorAll(`.table-filter[data-target="${filter.dataset.target}"]`));
    table.querySelectorAll('tbody tr').forEach((row) => {
      row.hidden = filters.some((activeFilter) => {
        const value = activeFilter.value.toLocaleLowerCase();
        if (!value) return false;
        const column = Number(activeFilter.dataset.column || 0);
        return !(row.children[column]?.textContent || '').toLocaleLowerCase().includes(value);
      });
    });
    paginate(table);
  });
});

document.querySelectorAll('.kpi-link').forEach((link) => {
  link.addEventListener('click', (event) => {
    event.preventDefault();
    const table = document.getElementById('finance-purchase-table');
    if (!table) return;
    const status = link.dataset.status;
    table.querySelectorAll('tbody tr').forEach((row) => {
      row.hidden = status !== 'ALL' && row.dataset.status !== status;
    });
    paginate(table);
  });
});

function paginate(table, pageSize = 10) {
  const rows = Array.from(table.querySelectorAll('tbody tr')).filter((row) => !row.hidden);
  let pager = table.closest('.card, .modal-panel')?.querySelector(`.pager[data-for="${table.id}"]`);
  if (!pager) {
    pager = document.createElement('div');
    pager.className = 'pager';
    pager.dataset.for = table.id;
    table.closest('.table-wrap').after(pager);
  }
  const pages = Math.max(1, Math.ceil(rows.length / pageSize));
  let current = Number(pager.dataset.page || 1);
  current = Math.min(current, pages);
  pager.dataset.page = String(current);
  Array.from(table.querySelectorAll('tbody tr')).forEach((row) => {
    if (row.hidden) return;
    const idx = rows.indexOf(row);
    row.style.display = idx >= (current - 1) * pageSize && idx < current * pageSize ? '' : 'none';
  });
  pager.innerHTML = '';
  for (let i = 1; i <= pages; i++) {
    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = i;
    button.className = i === current ? 'active' : '';
    button.addEventListener('click', () => {
      pager.dataset.page = String(i);
      paginate(table, pageSize);
    });
    pager.append(button);
  }
}
document.querySelectorAll('.data-table').forEach((table) => paginate(table));

const movementAsset = document.getElementById('movement-asset');
const stockSummary = document.getElementById('stock-summary');
async function updateMovementStock() {
  if (!movementAsset || !stockSummary) return;
  stockSummary.textContent = 'Atualizando estoque...';
  try {
    const response = await fetch(`index.php?page=stock-info&ativo_id=${encodeURIComponent(movementAsset.value)}`);
    const stock = await response.json();
    stockSummary.innerHTML = `<strong>Estoque do produto</strong><br>Atual: ${stock.quantidade_atual} &nbsp; | &nbsp; Reservado: ${stock.reserved} &nbsp; | &nbsp; Disponivel: ${stock.available}<br>Minimo: ${stock.estoque_minimo} &nbsp; | &nbsp; Maximo: ${stock.estoque_maximo}`;
  } catch (_) { stockSummary.textContent = 'Nao foi possivel consultar o estoque.'; }
}
movementAsset?.addEventListener('change', updateMovementStock);
if (movementAsset) updateMovementStock();

function addPurchaseItem() {
  const list = document.getElementById('purchase-items');
  if (!list) return;
  const row = document.createElement('div');
  row.className = 'purchase-row';
  row.innerHTML = `
    <label>Ativo<select name="item_ativo_id[]" required>${assetOptions.map((a) => `<option value="${a.id}" data-code="${a.codigo}" data-name="${a.nome}" data-desc="${a.descricao || ''}">${a.codigo} - ${a.nome}</option>`).join('')}</select></label>
    <label>Quantidade<input type="number" name="item_quantidade[]" value="1" min="1" required></label>
    <label>Valor Unitario<input type="number" step="0.01" name="item_valor_unitario[]" value="0.00" required></label>
    <label>Valor Total<input type="number" step="0.01" name="item_valor_total[]" value="0.00" readonly></label>
    <button type="button" class="mini danger">Remover</button>
  `;
  row.querySelector('.danger').addEventListener('click', () => {
    row.remove();
    updatePurchasePreview();
  });
  row.querySelectorAll('select,input').forEach((el) => el.addEventListener('input', updatePurchasePreview));
  list.append(row);
  updatePurchasePreview();
}

function updatePurchasePreview() {
  const preview = document.getElementById('purchase-preview');
  if (!preview) return;
  preview.innerHTML = '';
  document.querySelectorAll('.purchase-row').forEach((row) => {
    const select = row.querySelector('select');
    const qty = Number(row.querySelector('[name="item_quantidade[]"]').value || 0);
    const unit = Number(row.querySelector('[name="item_valor_unitario[]"]').value || 0);
    const total = qty * unit;
    row.querySelector('[name="item_valor_total[]"]').value = total.toFixed(2);
    const selected = select.options[select.selectedIndex];
    preview.insertAdjacentHTML('beforeend', `<tr><td>${selected.dataset.code || ''}</td><td>${selected.dataset.name || ''}</td><td>${qty}</td><td>${unit.toFixed(2)}</td><td>${total.toFixed(2)}</td></tr>`);
  });
}

function addHiringPerson(name = '') {
  const list = document.getElementById('hiring-people-list');
  if (!list) return;
  const row = document.createElement('div');
  row.className = 'hiring-person-row';
  row.innerHTML = `
    <label>Nome da pessoa<input name="person_name[]" required placeholder="Informe o nome da pessoa"></label>
    <button type="button" class="mini danger">Remover</button>
  `;
  row.querySelector('input').value = name;
  row.querySelector('.danger').addEventListener('click', () => {
    if (list.children.length > 1) row.remove();
  });
  list.append(row);
}
document.getElementById('add-hiring-person')?.addEventListener('click', () => addHiringPerson());
if (document.getElementById('hiring-people-list')) addHiringPerson();

document.getElementById('add-purchase-item')?.addEventListener('click', addPurchaseItem);
if (document.getElementById('purchase-items')) addPurchaseItem();
</script>
</body>
</html>
