<?php

if (!defined('GLPI_ROOT')) {
    die('Direct access not allowed');
}

class PluginTermosTerm extends CommonDBTM {

    public static $rightname = 'plugin_termos';

    public static function getTypeName($nb = 0) {
        return _n('Termo de Responsabilidade', 'Termos de Responsabilidade', $nb);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Consultas
    // ─────────────────────────────────────────────────────────────────────────

    public static function getComputerWithUser(int $computers_id): array {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => [
                'glpi_computers.id                AS computer_id',
                'glpi_computers.name              AS computer_name',
                'glpi_computers.serial            AS serial',
                'glpi_computers.otherserial       AS patrimonio',
                'glpi_users.id                    AS user_id',
                'glpi_users.name                  AS username',
                'glpi_users.realname              AS realname',
                'glpi_users.firstname             AS firstname',
                'glpi_users.phone                 AS phone',
                'glpi_users.registration_number   AS registration_number',
                'glpi_entities.completename       AS entity',
                'glpi_usertitles.name             AS title',
                'glpi_usercategories.name         AS category',
                'glpi_computermodels.name         AS model',
                'glpi_manufacturers.name          AS manufacturer',
            ],
            'FROM'      => 'glpi_computers',
            'LEFT JOIN' => [
                'glpi_users'          => ['ON' => ['glpi_computers' => 'users_id',              'glpi_users'          => 'id']],
                'glpi_entities'       => ['ON' => ['glpi_computers' => 'entities_id',            'glpi_entities'       => 'id']],
                'glpi_usertitles'     => ['ON' => ['glpi_users'     => 'usertitles_id',          'glpi_usertitles'     => 'id']],
                'glpi_usercategories' => ['ON' => ['glpi_users'     => 'usercategories_id',      'glpi_usercategories' => 'id']],
                'glpi_computermodels' => ['ON' => ['glpi_computers' => 'computermodels_id',      'glpi_computermodels' => 'id']],
                'glpi_manufacturers'  => ['ON' => ['glpi_computers' => 'manufacturers_id',       'glpi_manufacturers'  => 'id']],
            ],
            'WHERE' => ['glpi_computers.id' => $computers_id, 'glpi_computers.is_deleted' => 0],
            'LIMIT' => 1,
        ]);

        foreach ($iterator as $row) {
            return $row;
        }
        return [];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Consulta — Celular
    // ─────────────────────────────────────────────────────────────────────────

    public static function getPhoneWithUser(int $phones_id, int $lines_id = 0): array {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => [
                'glpi_phones.id                AS computer_id',   // chave genérica
                'glpi_phones.name              AS computer_name',  // chave genérica
                'glpi_phones.serial            AS serial',
                'glpi_phones.otherserial       AS patrimonio',
                'glpi_phones.users_id          AS _phones_users_id',
                'glpi_users.id                 AS user_id',
                'glpi_users.name               AS username',
                'glpi_users.realname           AS realname',
                'glpi_users.firstname          AS firstname',
                'glpi_users.phone              AS phone',
                'glpi_users.registration_number AS registration_number',
                'glpi_entities.completename    AS entity',
                'glpi_usertitles.name          AS title',
                'glpi_usercategories.name      AS category',
                'glpi_phonemodels.name         AS model',
                'glpi_manufacturers.name       AS manufacturer',
            ],
            'FROM'      => 'glpi_phones',
            'LEFT JOIN' => [
                'glpi_users'          => ['ON' => ['glpi_phones' => 'users_id',           'glpi_users'          => 'id']],
                'glpi_entities'       => ['ON' => ['glpi_phones' => 'entities_id',         'glpi_entities'       => 'id']],
                'glpi_usertitles'     => ['ON' => ['glpi_users'  => 'usertitles_id',       'glpi_usertitles'     => 'id']],
                'glpi_usercategories' => ['ON' => ['glpi_users'  => 'usercategories_id',   'glpi_usercategories' => 'id']],
                'glpi_phonemodels'    => ['ON' => ['glpi_phones' => 'phonemodels_id',      'glpi_phonemodels'    => 'id']],
                'glpi_manufacturers'  => ['ON' => ['glpi_phones' => 'manufacturers_id',    'glpi_manufacturers'  => 'id']],
            ],
            'WHERE' => ['glpi_phones.id' => $phones_id, 'glpi_phones.is_deleted' => 0],
            'LIMIT' => 1,
        ]);

        $row = [];
        foreach ($iterator as $r) { $row = $r; break; }

        if (empty($row)) return [];

        // Linha telefônica: tenta pelo lines_id do termo; se não tiver, busca via glpi_items_lines
        $row['line_number'] = '-';
        $row['line_name']   = '-';
        $resolvedLinesId    = $lines_id;

        if ($resolvedLinesId <= 0) {
            $itemLineIt = $DB->request([
                'SELECT' => ['lines_id'],
                'FROM'   => 'glpi_items_lines',
                'WHERE'  => ['itemtype' => 'Phone', 'items_id' => $phones_id],
                'LIMIT'  => 1,
            ]);
            foreach ($itemLineIt as $il) {
                $resolvedLinesId = (int)$il['lines_id'];
            }
        }

        if ($resolvedLinesId > 0) {
            $lineIt = $DB->request([
                'SELECT' => ['name', 'caller_num'],
                'FROM'   => 'glpi_lines',
                'WHERE'  => ['id' => $resolvedLinesId, 'is_deleted' => 0],
                'LIMIT'  => 1,
            ]);
            foreach ($lineIt as $lr) {
                $row['line_number'] = $lr['caller_num'] ?: $lr['name'] ?: '-';
                $row['line_name']   = $lr['name'] ?: '-';
            }
        }

        $row['item_type'] = 'Phone';
        $row['phones_id'] = $phones_id;
        $row['lines_id']  = $lines_id;

        return $row;
    }

    /**
     * Retorna dados do equipamento e usuário de um termo,
     * independente de ser Computador ou Celular.
     */
    public static function getItemWithUser(self $term): array {
        $itemType = $term->fields['item_type'] ?? 'Computer';
        if ($itemType === 'Phone') {
            return self::getPhoneWithUser(
                (int)$term->fields['phones_id'],
                (int)($term->fields['lines_id'] ?? 0)
            );
        }
        return self::getComputerWithUser((int)$term->fields['computers_id']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Criação
    // ─────────────────────────────────────────────────────────────────────────

    public static function createTerm(
        int    $computers_id,
        string $item_type  = 'Computer',
        int    $phones_id  = 0,
        int    $lines_id   = 0
    ): int {
        if ($item_type === 'Phone') {
            $data = self::getPhoneWithUser($phones_id, $lines_id);
            if (empty($data)) {
                throw new \RuntimeException('Celular não encontrado.');
            }
            if (empty($data['user_id'])) {
                throw new \RuntimeException('Este celular não possui usuário vinculado.');
            }
        } else {
            $data = self::getComputerWithUser($computers_id);
            if (empty($data)) {
                throw new \RuntimeException('Computador não encontrado.');
            }
            if (empty($data['user_id'])) {
                throw new \RuntimeException('Este computador não possui usuário vinculado.');
            }
        }

        $term = new self();
        $id   = $term->add([
            'computers_id'  => $item_type === 'Computer' ? $computers_id : 0,
            'phones_id'     => $item_type === 'Phone'    ? $phones_id    : 0,
            'lines_id'      => $lines_id,
            'item_type'     => $item_type,
            'users_id'      => (int)$data['user_id'],
            'entities_id'   => (int)($_SESSION['glpiactive_entity'] ?? 0),
            'issuer_id'     => (int)($_SESSION['glpiID'] ?? 0),
            'status'        => 'pending',
            'date_creation' => date('Y-m-d H:i:s'),
        ]);

        if ($id === false) {
            throw new \RuntimeException('Erro ao criar o registro do termo.');
        }
        return $id;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Assinatura
    // ─────────────────────────────────────────────────────────────────────────

    public static function signTerm(int $terms_id, string $signature_base64): bool {
        $term = new self();

        if (!$term->getFromDB($terms_id)) {
            throw new \RuntimeException('Termo não encontrado.');
        }
        if ($term->fields['status'] === 'signed') {
            throw new \RuntimeException('Este termo já foi assinado.');
        }

        $data    = self::getItemWithUser($term);
        $pdfGen  = new PluginTermosPdf();
        $pdfPath = $pdfGen->generate($data, $signature_base64, $terms_id, (int)($term->fields['issuer_id'] ?? 0));

        if (!file_exists($pdfPath)) {
            throw new \RuntimeException('Falha ao gerar o PDF.');
        }

        $hash = hash_file('sha256', $pdfPath);

        return (bool)$term->update([
            'id'             => $terms_id,
            'status'         => 'signed',
            'signature_data' => $signature_base64,
            'pdf_path'       => $pdfPath,
            'pdf_hash'       => $hash,
            'date_signed'    => date('Y-m-d H:i:s'),
            'signer_ip'      => $_SERVER['REMOTE_ADDR']  ?? '',
            'signer_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512),
            'sign_token'     => null,        // invalida o token após assinar
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Token de assinatura remota
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Gera (ou regenera) um token de assinatura remota para o termo.
     * Retorna o token gerado.
     */
    public static function generateSignToken(int $terms_id): string {
        global $DB;

        $term = new self();
        if (!$term->getFromDB($terms_id)) {
            throw new \RuntimeException('Termo não encontrado.');
        }
        if ($term->fields['status'] === 'signed') {
            throw new \RuntimeException('Este termo já foi assinado e não pode ser reenviado.');
        }

        // Validade configurável (padrão 7 dias)
        $configFile = __DIR__ . '/../config/settings.json';
        $config     = file_exists($configFile) ? (json_decode(file_get_contents($configFile), true) ?? []) : [];
        $days       = (int)($config['token_validity_days'] ?? 7);
        if ($days < 1) $days = 7;

        $token   = bin2hex(random_bytes(32)); // 64 chars, 256-bit
        $expires = date('Y-m-d H:i:s', strtotime("+{$days} days"));

        $DB->update('glpi_plugin_termos_terms', [
            'sign_token'      => $token,
            'token_expires_at'=> $expires,
        ], ['id' => $terms_id]);

        return $token;
    }

    /**
     * Valida um token e retorna o termo correspondente (array com fields).
     * Lança exceção se inválido, expirado ou já usado.
     */
    public static function getTermByToken(string $token): self {
        global $DB;

        if (empty($token)) {
            throw new \RuntimeException('Token não informado.');
        }

        $iterator = $DB->request([
            'FROM'  => 'glpi_plugin_termos_terms',
            'WHERE' => ['sign_token' => $token],
            'LIMIT' => 1,
        ]);

        $row = null;
        foreach ($iterator as $r) { $row = $r; }

        if ($row === null) {
            throw new \RuntimeException('Link inválido ou já utilizado.');
        }

        if ($row['status'] === 'signed') {
            throw new \RuntimeException('Este termo já foi assinado.');
        }

        if (!empty($row['token_expires_at']) && strtotime($row['token_expires_at']) < time()) {
            throw new \RuntimeException('Este link expirou. Solicite um novo link ao setor de TI.');
        }

        $term = new self();
        $term->getFromDB((int)$row['id']);
        return $term;
    }

    /**
     * Envia o link de assinatura por email.
     */
    public static function sendSignEmail(int $terms_id, string $customEmail = ''): bool {
        global $CFG_GLPI;

        $token = self::generateSignToken($terms_id);
        $term  = new self();
        $term->getFromDB($terms_id);

        $user = new User();
        if (!$user->getFromDB((int)$term->fields['users_id'])) {
            throw new \RuntimeException('Usuário não encontrado.');
        }

        $email = !empty($customEmail) ? $customEmail : $user->getDefaultEmail();
        if (empty($email)) {
            throw new \RuntimeException('O usuário não possui e-mail cadastrado no GLPI. Informe um e-mail manualmente.');
        }

        $data         = self::getItemWithUser($term);
        $nomeCompleto = trim(($data['firstname'] ?? '') . ' ' . ($data['realname'] ?? '')) ?: ($data['username'] ?? '');

        // Monta a URL pública de assinatura
        $baseUrl = rtrim($CFG_GLPI['url_base'] ?? '', '/');
        $signUrl = $baseUrl . '/term_sign.php?token=' . $token;

        // Validade do token para mostrar no email
        $configFile = __DIR__ . '/../config/settings.json';
        $config     = file_exists($configFile) ? (json_decode(file_get_contents($configFile), true) ?? []) : [];
        $days       = (int)($config['token_validity_days'] ?? 7);

        $fromEmail = $CFG_GLPI['admin_email']      ?? '';
        $fromName  = $CFG_GLPI['admin_email_name'] ?? 'Sistema GLPI';
        if (empty($fromName)) $fromName = 'Sistema GLPI';

        // ── Monta o corpo usando o template configurável ──────────────────────
        $assunto = $config['email_assunto']
            ?? 'Assine seu Termo de Responsabilidade - {computador}';

        $corpoTemplate = $config['email_corpo']
            ?? "Olá, <strong>{nome}</strong>!\n\nVocê recebeu o equipamento abaixo e precisa assinar o <strong>Termo de Responsabilidade</strong>.";

        // Substitui variáveis no assunto e no corpo
        $vars = [
            '{nome}'          => htmlspecialchars($nomeCompleto),
            '{computador}'    => htmlspecialchars($data['computer_name'] ?? '-'),
            '{serial}'        => htmlspecialchars($data['serial']         ?? '-'),
            '{setor}'         => htmlspecialchars($data['entity']         ?? '-'),
            '{link}'          => $signUrl,
            '{dias_validade}' => (string)$days,
        ];
        $assunto = str_replace(array_keys($vars), array_values($vars), $assunto);
        $corpoHtml = str_replace(array_keys($vars), array_values($vars), $corpoTemplate);
        // Converte quebras de linha em parágrafos
        $corpoHtml = '<p>' . str_replace(["\n\n", "\n"], ['</p><p>', '<br>'], $corpoHtml) . '</p>';

        // ── Tabela dinâmica de equipamento + usuário (segue config de campos) ──
        $defaultCompFields = [
            ['enabled' => true, 'label' => 'Computador',      'field' => 'computer_name'],
            ['enabled' => true, 'label' => 'Número de Série', 'field' => 'serial'],
            ['enabled' => true, 'label' => 'Patrimônio',      'field' => 'patrimonio'],
            ['enabled' => true, 'label' => 'Setor',           'field' => 'entity'],
        ];
        $defaultUserFields = [
            ['enabled' => true, 'label' => 'Nome',     'field' => 'fullname'],
            ['enabled' => true, 'label' => 'Login',    'field' => 'username'],
            ['enabled' => true, 'label' => 'Telefone', 'field' => 'phone'],
        ];
        $emailCompFields = $config['fields_computer'] ?? $defaultCompFields;
        $emailUserFields = $config['fields_user']     ?? $defaultUserFields;

        $resolveField = function (string $field) use ($data, $nomeCompleto): string {
            if ($field === 'fullname') return $nomeCompleto ?: '-';
            return (string)($data[$field] ?? '-');
        };

        // Monta uma seção (título + tabela) com os campos habilitados
        $buildSection = function (string $titulo, array $fields) use ($resolveField): string {
            $rows = '';
            $alt  = false;
            foreach ($fields as $f) {
                if (!($f['enabled'] ?? true)) continue;
                $bg    = $alt ? ' style="background:#f8f9fa;"' : '';
                $rows .= '<tr' . $bg . '>'
                    . '<td style="padding:6px;font-weight:bold;width:40%;">' . htmlspecialchars($f['label']) . ':</td>'
                    . '<td style="padding:6px;">' . htmlspecialchars($resolveField($f['field'])) . '</td>'
                    . '</tr>';
                $alt = !$alt;
            }
            if (!$rows) return '';
            return '<p style="font-weight:700;font-size:0.82em;text-transform:uppercase;letter-spacing:.05em;color:#0d6efd;margin:14px 0 4px;">' . $titulo . '</p>'
                . '<table style="width:100%;border-collapse:collapse;margin-bottom:10px;">' . $rows . '</table>';
        };

        $tabelaEquip = $buildSection('Equipamento', $emailCompFields)
                     . $buildSection('Responsável',  $emailUserFields);

        $mmail = new GLPIMailer();
        if (!empty($fromEmail)) {
            $mmail->setFrom($fromEmail, $fromName);
        }
        $mmail->addAddress($email, (string)($nomeCompleto ?: ''));
        $mmail->Subject = $assunto;
        $mmail->isHTML(true);
        $mmail->Body =
            '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;">'
          . '<h2 style="color:#0d6efd;margin-bottom:12px;">Termo de Responsabilidade</h2>'
          . $corpoHtml
          . $tabelaEquip
          . '<p style="margin:20px 0;">'
          .   '<a href="' . $signUrl . '" style="background:#0d6efd;color:#fff;padding:12px 24px;'
          .   'text-decoration:none;border-radius:5px;font-size:16px;display:inline-block;">'
          .   'Clique aqui para assinar o termo</a>'
          . '</p>'
          . '<p style="color:#666;font-size:0.85em;">Este link e valido por <strong>' . $days . ' dia(s)</strong> a partir do envio deste e-mail.</p>'
          . '<p style="color:#666;font-size:0.85em;">Se nao conseguir clicar no botao, copie e cole o link abaixo no seu navegador:</p>'
          . '<p style="font-size:0.8em;word-break:break-all;color:#555;">' . $signUrl . '</p>'
          . '<hr style="margin:20px 0;">'
          . '<p style="color:#999;font-size:0.8em;"><em>Este e-mail foi gerado automaticamente. Nao responda.</em></p>'
          . '</div>';
        $mmail->AltBody =
            strip_tags($corpoHtml) . "\n\n"
          . "Equipamento: " . ($data['computer_name'] ?? '-') . "\n"
          . "Serie: "       . ($data['serial']         ?? '-') . "\n"
          . "Setor: "       . ($data['entity']         ?? '-') . "\n\n"
          . "Link para assinar:\n{$signUrl}\n\n"
          . "Valido por {$days} dia(s).";

        if (!$mmail->send()) {
            throw new \RuntimeException('Falha ao enviar e-mail: ' . $mmail->ErrorInfo);
        }

        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Reenvio do PDF assinado
    // ─────────────────────────────────────────────────────────────────────────

    public static function sendTermEmail(int $terms_id): bool {
        global $CFG_GLPI;

        $term = new self();
        if (!$term->getFromDB($terms_id)) {
            throw new \RuntimeException('Termo não encontrado.');
        }
        if ($term->fields['status'] !== 'signed') {
            throw new \RuntimeException('O termo ainda não foi assinado.');
        }

        $pdfPath = $term->fields['pdf_path'];
        if (empty($pdfPath) || !file_exists($pdfPath)) {
            throw new \RuntimeException('PDF não encontrado no servidor.');
        }

        $user = new User();
        if (!$user->getFromDB((int)$term->fields['users_id'])) {
            throw new \RuntimeException('Usuário não encontrado.');
        }

        $email = $user->getDefaultEmail();
        if (empty($email)) {
            throw new \RuntimeException('O usuário não possui e-mail cadastrado no GLPI.');
        }

        $data         = self::getItemWithUser($term);
        $nomeCompleto = trim(($data['firstname'] ?? '') . ' ' . ($data['realname'] ?? '')) ?: ($data['username'] ?? '');
        $dataAssinado = date('d/m/Y \a\s H:i', strtotime($term->fields['date_signed']));

        $fromEmail = $CFG_GLPI['admin_email']      ?? '';
        $fromName  = $CFG_GLPI['admin_email_name'] ?? 'Sistema GLPI';
        if (empty($fromName)) $fromName = 'Sistema GLPI';

        $mmail = new GLPIMailer();
        if (!empty($fromEmail)) {
            $mmail->setFrom($fromEmail, $fromName);
        }
        $mmail->addAddress($email, (string)($nomeCompleto ?: ''));
        $mmail->Subject = 'Termo de Responsabilidade - ' . ($data['computer_name'] ?? '');
        $mmail->isHTML(true);
        $mmail->Body =
            '<p>Ola, <strong>' . htmlspecialchars($nomeCompleto) . '</strong>!</p>'
          . '<p>Segue em anexo o seu <strong>Termo de Responsabilidade</strong>:</p>'
          . '<ul>'
          . '<li><strong>Computador:</strong> ' . htmlspecialchars($data['computer_name'] ?? '-') . '</li>'
          . '<li><strong>Numero de Serie:</strong> ' . htmlspecialchars($data['serial'] ?? '-') . '</li>'
          . '<li><strong>Assinado em:</strong> ' . $dataAssinado . '</li>'
          . '</ul>'
          . '<p>Guarde este documento para seus registros.</p>'
          . '<p><em>Este e-mail foi gerado automaticamente pelo sistema GLPI.</em></p>';
        $mmail->AltBody = strip_tags($mmail->Body);
        $mmail->addAttachment($pdfPath, 'termo_responsabilidade_' . $terms_id . '.pdf');

        if (!$mmail->send()) {
            throw new \RuntimeException('Falha ao enviar e-mail: ' . $mmail->ErrorInfo);
        }
        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    public static function getIssuerName(int $issuer_id): string {
        if ($issuer_id <= 0) return '-';
        $user = new User();
        if (!$user->getFromDB($issuer_id)) return '-';
        $nome = trim(($user->fields['firstname'] ?? '') . ' ' . ($user->fields['realname'] ?? ''));
        return $nome ?: ($user->fields['name'] ?? '-');
    }

    public static function getStatusLabel(string $status): string {
        return match ($status) {
            'pending' => '<span style="color:orange;">⏳ Pendente</span>',
            'signed'  => '<span style="color:green;">✅ Assinado</span>',
            'revoked' => '<span style="color:red;">❌ Revogado</span>',
            default   => htmlspecialchars($status),
        };
    }
}
