<?php
session_start();

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
	$_SESSION = [];
	if (ini_get('session.use_cookies')) {
		$params = session_get_cookie_params();
		setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
	}
	session_destroy();
	header('Location: login.php');
	exit;
}

if (empty($_SESSION['authenticated'])) {
	header('Location: login.php');
	exit;
}

$perfilAtual = strtolower(trim((string)($_SESSION['perfil'] ?? '')));
$isAluno = $perfilAtual === 'aluno';
$isFuncionario = $perfilAtual === 'funcionario';
$isGestor = !$isAluno && !$isFuncionario;
$isStaffAcademico = $isGestor || $isFuncionario;
$tipoUtilizador = $isAluno ? 'Aluno' : ($isFuncionario ? 'Funcionario' : 'Gestor');

if ($isAluno) {
	header('Location: aluno.php');
	exit;
}

$conn = new mysqli('localhost', 'root', '', 'ipcapw');
if ($conn->connect_error) {
	die('Falha na ligação à base de dados: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');
$conn->query("ALTER TABLE ficha_aluno MODIFY Status VARCHAR(50) NOT NULL DEFAULT 'Rascunho'");

function e($value)
{
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function fotoDataUrlFromBlob($blob)
{
	if ($blob === null || $blob === '') {
		return '';
	}

	$bin = (string)$blob;
	$mime = 'application/octet-stream';
	$info = @getimagesizefromstring($bin);
	if (is_array($info) && !empty($info['mime'])) {
		$mime = (string)$info['mime'];
	} elseif (strncmp($bin, "\xFF\xD8\xFF", 3) === 0) {
		$mime = 'image/jpeg';
	} elseif (strncmp($bin, "\x89PNG", 4) === 0) {
		$mime = 'image/png';
	} elseif (strncmp($bin, 'GIF8', 4) === 0) {
		$mime = 'image/gif';
	} elseif (strncmp($bin, 'RIFF', 4) === 0 && substr($bin, 8, 4) === 'WEBP') {
		$mime = 'image/webp';
	}

	if (strpos($mime, 'image/') !== 0) {
		return '';
	}

	return 'data:' . $mime . ';base64,' . base64_encode($bin);
}

function mensagemErroBaseDados($operacao, mysqli $conn)
{
	if ((int)$conn->errno === 1062) {
		return 'Já existe um registo com os mesmos dados.';
	}
	return 'Não foi possível ' . $operacao . ' neste momento.';
}

function redirectWithMessage($type, $message)
{
	$type = urlencode($type);
	$message = urlencode($message);
	header("Location: fichas.php?type={$type}&message={$message}");
	exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'foto') {
	$idUserFoto = (int)($_GET['id_user'] ?? 0);
	$stmtFoto = $conn->prepare('SELECT foto FROM ficha_aluno WHERE IdUser = ? LIMIT 1');
	if (!$stmtFoto) {
		http_response_code(404);
		exit;
	}
	$stmtFoto->bind_param('i', $idUserFoto);
	$stmtFoto->execute();
	$fotoRow = $stmtFoto->get_result()->fetch_assoc();
	$stmtFoto->close();

	if (!$fotoRow || $fotoRow['foto'] === null) {
		http_response_code(404);
		exit;
	}

	$fotoBin = $fotoRow['foto'];
	$mimeFoto = 'application/octet-stream';
	$infoFoto = @getimagesizefromstring($fotoBin);
	if (is_array($infoFoto) && !empty($infoFoto['mime'])) {
		$mimeFoto = (string)$infoFoto['mime'];
	}

	header('Content-Type: ' . $mimeFoto);
	echo $fotoBin;
	exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!$isGestor) {
		redirectWithMessage('error', 'Sem permissões para validar fichas de aluno.');
	}

	$postAction = (string)($_POST['action'] ?? '');
	if ($postAction !== 'validate_ficha') {
		redirectWithMessage('error', 'Ação inválida.');
	}

	$idUserFicha = (int)($_POST['IdUserFicha'] ?? 0);
	$novoStatusFicha = strtolower(trim((string)($_POST['StatusFicha'] ?? '')));

	if ($idUserFicha <= 0) {
		redirectWithMessage('error', 'Aluno inválido para validar ficha.');
	}
	if (!in_array($novoStatusFicha, ['aprovada', 'rejeitada'], true)) {
		redirectWithMessage('error', 'Estado inválido para ficha de aluno.');
	}

	$statusGuardar = $novoStatusFicha === 'aprovada' ? 'Aprovada' : 'Rejeitada';
	$statusSubmetida = 'Submetida';

	$stmt = $conn->prepare('UPDATE ficha_aluno SET Status = ? WHERE IdUser = ? AND Status = ?');
	if (!$stmt) {
		redirectWithMessage('error', mensagemErroBaseDados('validar a ficha de aluno', $conn));
	}
	$stmt->bind_param('sis', $statusGuardar, $idUserFicha, $statusSubmetida);
	$ok = $stmt->execute();
	$afetadas = (int)$stmt->affected_rows;
	$erroDb = $ok ? '' : mensagemErroBaseDados('validar a ficha de aluno', $conn);
	$stmt->close();

	if (!$ok) {
		redirectWithMessage('error', $erroDb);
	}
	if ($afetadas === 0) {
		redirectWithMessage('error', 'A ficha já não está com estado Submetida.');
	}

	redirectWithMessage('success', 'Ficha de aluno validada com sucesso.');
}

$message = (string)($_GET['message'] ?? '');
$type = (string)($_GET['type'] ?? '');

$fichasTodas = [];
$resultFichas = $conn->query("SELECT fa.IdUser, fa.nome, fa.idade, fa.telefone, fa.morada, fa.nif, fa.data_nascimento, fa.Status, fa.foto FROM ficha_aluno fa ORDER BY fa.nome");
if ($resultFichas) {
	while ($row = $resultFichas->fetch_assoc()) {
		$fichasTodas[] = $row;
	}
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Fichas de Aluno - IPCA VNF</title>
	<link rel="stylesheet" href="../css/ipca-theme.css">
	<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.2/dist/jspdf.plugin.autotable.min.js"></script>
	<script src="../js/ficha-transfer.js?v=<?php echo (string)@filemtime(__DIR__ . '/../js/ficha-transfer.js'); ?>" defer></script>
	<style>
		.form-box .table-wrap {
			width: 100%;
			margin: 0;
			padding: 0;
			overflow-x: auto;
		}

		.fichas-table {
			width: 100% !important;
			min-width: 100% !important;
			display: table !important;
			table-layout: fixed !important;
			margin: 12px 0 0 0 !important;
			border-radius: 0 !important;
			box-shadow: none !important;
		}

		.fichas-table th,
		.fichas-table td {
			font: inherit !important;
			font-weight: 400 !important;
			text-align: left !important;
			vertical-align: middle !important;
			word-break: break-word;
		}

		.fichas-table th {
			font-weight: 800 !important;
		}

		.fichas-table th:last-child,
		.fichas-table td:last-child {
			width: 30% !important;
			white-space: nowrap !important;
		}

		.fichas-table td.actions {
			display: table-cell !important;
			padding-top: 14px !important;
			padding-bottom: 20px !important;
			overflow: visible;
			white-space: nowrap;
		}

		.fichas-table tr:nth-child(even) td.actions {
			background: #f7faff !important;
		}

		.fichas-table tr:nth-child(odd) td.actions {
			background: #ffffff !important;
		}

		.fichas-table td.actions > * {
			display: inline-flex;
			align-items: center;
			margin-right: 8px;
		}

		.fichas-table td.actions > *:last-child {
			margin-right: 0;
		}

		.fichas-table td.actions form {
			display: inline-flex;
			margin: 0;
		}

		.fichas-table td.actions button {
			margin-top: 0 !important;
			line-height: 1.2;
			padding: 8px 12px;
		}
	</style>
</head>
<body class="ipca-crud">
	<div class="page-shell">
		<header class="page-hero">
			<h1>Fichas de Aluno</h1>
			<p>Validação de fichas com estado Submetida.</p>
			<span class="role-badge">Tipo de utilizador: <?php echo e($tipoUtilizador); ?></span>
		</header>

		<nav>
			<?php if ($isGestor): ?>
				<a href="disciplinas.php">Unidades Curriculares</a>
				<a href="cursos.php">Cursos</a>
				<a href="matriculas.php">Matrículas</a>
				<a href="fichas.php">Fichas de Aluno</a>
				<a href="planos.php">Planos de Estudo</a>
				<a href="notas.php">Notas e Pautas</a>
			<?php else: ?>
				<a href="matriculas.php">Matrículas</a>
				<a href="fichas.php">Fichas de Aluno</a>
				<a href="notas.php">Notas e Pautas</a>
			<?php endif; ?>
			<a href="?action=logout">Terminar sessão</a>
		</nav>

		<?php if ($message !== ''): ?>
			<div class="message <?php echo e($type); ?>"><?php echo e($message); ?></div>
		<?php endif; ?>

		<div class="form-box">
			<h3>Fichas de Aluno</h3>
			<?php if (!$isStaffAcademico): ?>
				<p>Sem permissões para consultar fichas.</p>
			<?php elseif (!empty($fichasTodas)): ?>
				<?php
					$payloadFichas = ['fichas' => []];
					foreach ($fichasTodas as $fichaItem) {
						$payloadFichas['fichas'][] = [
							'id_user' => (int)$fichaItem['IdUser'],
							'nome' => (string)($fichaItem['nome'] ?? ''),
							'idade' => (int)($fichaItem['idade'] ?? 0),
							'telefone' => (string)($fichaItem['telefone'] ?? ''),
							'morada' => (string)($fichaItem['morada'] ?? ''),
							'nif' => (string)($fichaItem['nif'] ?? ''),
							'data_nascimento' => (string)($fichaItem['data_nascimento'] ?? ''),
							'status' => (string)($fichaItem['Status'] ?? ''),
							'foto_data_url' => fotoDataUrlFromBlob($fichaItem['foto'] ?? null),
							'foto_url' => 'fichas.php?action=foto&id_user=' . (int)$fichaItem['IdUser'],
						];
					}
				?>
				<script id="fichasTableData" type="application/json"><?php echo json_encode($payloadFichas, JSON_UNESCAPED_UNICODE | 
				JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
				<div class="table-wrap">
					<table class="matriculas-table fichas-table">
						<tr>
							
							<th>Nome</th>
							<th>Idade</th>
							<th>Telefone</th>
							<th>Morada</th>
							<th>NIF</th>
							<th>Data</th>
							<th>Status</th>
							<th>Ações</th>
						</tr>
						<?php foreach ($fichasTodas as $ficha): ?>
							<?php $statusLinha = strtolower(trim((string)($ficha['Status'] ?? ''))); ?>
							<tr>
								
								<td><?php echo e($ficha['nome']); ?></td>
								<td><?php echo e($ficha['idade']); ?></td>
								<td><?php echo e($ficha['telefone']); ?></td>
								<td><?php echo e($ficha['morada']); ?></td>
								<td><?php echo e($ficha['nif']); ?></td>
								<td><?php echo e($ficha['data_nascimento']); ?></td>
								<td><?php echo e($ficha['Status']); ?></td>
								<td class="actions">
									<button type="button" class="btnExportarFichaLinha" data-id-user="<?php echo e($ficha['IdUser']); ?>">&#8681;</button>
									<?php if ($isGestor && $statusLinha === 'submetida'): ?>
										<form class="inline" method="post">
											<input type="hidden" name="action" value="validate_ficha">
											<input type="hidden" name="IdUserFicha" value="<?php echo e($ficha['IdUser']); ?>">
											<input type="hidden" name="StatusFicha" value="Aprovada">
											<button type="submit">Aprovar</button>
										</form>
										<form class="inline" method="post">
											<input type="hidden" name="action" value="validate_ficha">
											<input type="hidden" name="IdUserFicha" value="<?php echo e($ficha['IdUser']); ?>">
											<input type="hidden" name="StatusFicha" value="Rejeitada">
											<button type="submit">Rejeitar</button>
										</form>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</table>
				</div>
			<?php else: ?>
				<p>Não existem fichas de aluno registadas.</p>
			<?php endif; ?>
		</div>
	</div>
</body>
</html>
<?php $conn->close(); ?>

