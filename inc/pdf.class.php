<?php

if (!defined('GLPI_ROOT')) {
    die('Direct access not allowed');
}

class PluginTermosPdf {

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function loadConfig(): array {
        $configFile = __DIR__ . '/../config/settings.json';
        if (file_exists($configFile)) {
            return json_decode(file_get_contents($configFile), true) ?? [];
        }
        return [];
    }

    private function loadLib(): string {
        $tcpdfPath = __DIR__ . '/../lib/tcpdf/tcpdf.php';
        $fpdfPath  = __DIR__ . '/../lib/fpdf/fpdf.php';

        if (file_exists($tcpdfPath)) {
            require_once $tcpdfPath;
            return 'tcpdf';
        }
        if (file_exists($fpdfPath)) {
            require_once $fpdfPath;
            return 'fpdf';
        }
        throw new \RuntimeException(
            'Nenhuma biblioteca PDF encontrada. ' .
            'Copie TCPDF para lib/tcpdf/ ou FPDF para lib/fpdf/ dentro do plugin.'
        );
    }

    private function fontName(string $lib): string {
        return $lib === 'tcpdf' ? 'helvetica' : 'Arial';
    }

    private function makePdf(string $lib, string $title): object {
        if ($lib === 'tcpdf') {
            $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8');
            $pdf->SetCreator('GLPI - Termos de Responsabilidade');
            $pdf->SetAuthor('Sistema GLPI');
            $pdf->SetTitle($title);
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->SetMargins(20, 20, 20);
            $pdf->SetAutoPageBreak(true, 20);
            $pdf->AddPage();
        } else {
            $pdf = new \FPDF('P', 'mm', 'A4');
            $pdf->SetMargins(20, 20, 20);
            $pdf->SetAutoPageBreak(true, 20);
            $pdf->AddPage();
        }
        return $pdf;
    }

    private function savePdf(object $pdf, int $termsId, string $lib, string $prefix = 'termo'): string {
        $dir = GLPI_PLUGIN_DOC_DIR . '/termosresponsabilidade/';
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new \RuntimeException('Não foi possível criar o diretório de PDFs: ' . $dir);
        }
        $filename = $dir . $prefix . '_' . $termsId . '_' . time() . '.pdf';

        if ($lib === 'tcpdf') {
            $pdf->Output($filename, 'F');
        } else {
            $pdf->Output('F', $filename);
        }
        return $filename;
    }

    /** Remove acentos para FPDF (não suporta UTF-8 nativo) */
    private function enc(string $lib, string $str): string {
        if ($lib === 'fpdf') {
            return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $str) ?: $str;
        }
        return $str;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Termo de Responsabilidade
    // ─────────────────────────────────────────────────────────────────────────

    public function generate(array $data, string $signatureBase64, int $termsId, int $issuerId = 0): string {
        $lib = $this->loadLib();
        $pdf = $this->makePdf($lib, 'Termo de Responsabilidade');
        $this->renderResponsability($pdf, $lib, $data, $signatureBase64, $termsId, $issuerId);
        return $this->savePdf($pdf, $termsId, $lib, 'termo');
    }

    private function addLogo(object $pdf, string $lib): void {
        foreach (['png','jpg','jpeg','gif','webp'] as $ext) {
            $logo = realpath(__DIR__ . '/../config/logo.' . $ext);
            if ($logo && file_exists($logo)) {
                try {
                    $pageW = method_exists($pdf, 'getPageWidth') ? $pdf->getPageWidth() : 210;
                    $logoW = 45;
                    $logoX = ($pageW - $logoW) / 2;
                    if ($lib === 'tcpdf') {
                        $pdf->Image($logo, $logoX, null, $logoW, 0, '', '', 'N', false, 300);
                    } else {
                        $pdf->Image($logo, $logoX, $pdf->GetY(), $logoW, 0);
                    }
                    $pdf->Ln(20);
                } catch (\Throwable $ignored) {}
                return;
            }
        }
    }

    private function row(object $pdf, string $lib, string $font, string $label, string $value, int $labelW = 42): void {
        $pdf->SetFont($font, 'B', 9);
        $pdf->Cell($labelW, 6, $this->enc($lib, $label . ':'), 0);
        $pdf->SetFont($font, '', 9);
        $pdf->MultiCell(0, 6, $this->enc($lib, $value), 0, 'L');
    }

    private function sectionTitle(object $pdf, string $lib, string $font, string $title): void {
        $pdf->SetFont($font, 'B', 9);
        $pdf->SetFillColor(230, 235, 245);
        $pdf->Cell(0, 6, $this->enc($lib, '  ' . $title), 0, 1, 'L', true);
        $pdf->Ln(1);
    }

    /** Resolve o valor de um campo dinâmico a partir dos dados do computador/usuário */
    private function resolveField(array $data, string $field): string {
        if ($field === 'fullname') {
            return trim(($data['firstname'] ?? '') . ' ' . ($data['realname'] ?? '')) ?: ($data['username'] ?? '-');
        }
        return (string)($data[$field] ?? '-');
    }

    private function renderResponsability(object $pdf, string $lib, array $data, string $signatureBase64, int $termsId, int $issuerId = 0): void {
        $config       = $this->loadConfig();
        $font         = $this->fontName($lib);
        $nomeCompleto = trim(($data['firstname'] ?? '') . ' ' . ($data['realname'] ?? '')) ?: ($data['username'] ?? '-');
        $hoje         = date('d/m/Y');
        $agora        = date('d/m/Y \a\s H:i:s');
        $issuerNome   = $issuerId > 0 ? PluginTermosTerm::getIssuerName($issuerId) : '-';

        // Campos dinâmicos com defaults
        $compFields = $config['fields_computer'] ?? [
            ['enabled' => true, 'label' => 'Nome',       'field' => 'computer_name'],
            ['enabled' => true, 'label' => 'Serie',      'field' => 'serial'],
            ['enabled' => true, 'label' => 'Patrimonio', 'field' => 'patrimonio'],
            ['enabled' => true, 'label' => 'Setor',      'field' => 'entity'],
        ];
        $userFields = $config['fields_user'] ?? [
            ['enabled' => true, 'label' => 'Nome',     'field' => 'fullname'],
            ['enabled' => true, 'label' => 'Login',    'field' => 'username'],
            ['enabled' => true, 'label' => 'Telefone', 'field' => 'phone'],
        ];

        $clausulas = array_filter(array_map('trim', explode(
            "\n",
            $config['termos_texto'] ?? implode("\n", [
                "Recebi o equipamento acima em perfeito estado de funcionamento e conservacao.",
                "Comprometo-me a zelar pela guarda, conservacao e uso adequado, utilizando-o exclusivamente para fins profissionais.",
                "Estou ciente de que nao devo instalar softwares nao autorizados ou acessar conteudos inadequados.",
                "Comunicarei imediatamente ao setor de TI qualquer avaria, perda, furto ou roubo.",
                "Serei responsabilizado por danos causados por mau uso, negligencia ou extravio.",
            ])
        )));

        $rodapeTexto = $config['rodape_texto']
            ?? 'As partes concordam que este termo e assinado por meio eletronico, reconhecendo sua validade juridica conforme art. 10, par. 2 da MP n. 2.200-2/2001 e Lei n. 14.063/2020.';

        // Logo centralizada
        $this->addLogo($pdf, $lib);

        // Título
        $pdf->SetFont($font, 'B', 13);
        $pdf->Cell(0, 8, $this->enc($lib, 'TERMO DE RESPONSABILIDADE DE EQUIPAMENTO'), 0, 1, 'C');
        $pdf->SetFont($font, '', 8);
        $pdf->Cell(0, 5, $this->enc($lib, 'No ' . $termsId . '   |   ' . $hoje), 0, 1, 'C');
        $pdf->Ln(3);

        // Duas colunas: Equipamento | Responsável
        $colW   = 85;
        $yStart = $pdf->GetY();

        // Coluna esquerda — Equipamento (campos dinâmicos)
        $this->sectionTitle($pdf, $lib, $font, 'EQUIPAMENTO');
        foreach ($compFields as $cf) {
            if (!($cf['enabled'] ?? true)) continue;
            $this->row($pdf, $lib, $font, $cf['label'], $this->resolveField($data, $cf['field']));
        }
        // Linha telefônica (apenas para celulares)
        if (($data['item_type'] ?? '') === 'Phone' && ($data['line_number'] ?? '-') !== '-') {
            $lineLabel = ($data['line_name'] ?? '-') !== ($data['line_number'] ?? '-') && ($data['line_name'] ?? '-') !== '-'
                ? $data['line_number'] . ' (' . $data['line_name'] . ')'
                : $data['line_number'];
            $this->row($pdf, $lib, $font, 'Linha Telefonica', $lineLabel);
        }
        $yLeft = $pdf->GetY();

        // Coluna direita — Responsável (campos dinâmicos)
        $pdf->SetXY(20 + $colW + 5, $yStart);
        $this->sectionTitle($pdf, $lib, $font, 'RESPONSAVEL');
        foreach ($userFields as $uf) {
            if (!($uf['enabled'] ?? true)) continue;
            $pdf->SetX(20 + $colW + 5);
            $this->row($pdf, $lib, $font, $uf['label'], $this->resolveField($data, $uf['field']));
        }
        $yRight = $pdf->GetY();

        $pdf->SetY(max($yLeft, $yRight) + 3);

        // Cláusulas
        $this->sectionTitle($pdf, $lib, $font, 'DECLARO QUE:');
        $pdf->SetFont($font, '', 9);
        $i = 1;
        foreach ($clausulas as $clausula) {
            $pdf->MultiCell(0, 5, $this->enc($lib, $i . '. ' . $clausula), 0, 'L');
            $i++;
        }
        $pdf->Ln(3);

        // Base legal
        $pdf->SetFont($font, 'I', 7);
        $pdf->MultiCell(0, 4, $this->enc($lib, $rodapeTexto), 0, 'C');
        $pdf->Ln(4);

        // Assinatura
        $this->sectionTitle($pdf, $lib, $font, 'ASSINATURA DO RESPONSAVEL');

        if (!empty($signatureBase64)) {
            $imgData = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $signatureBase64));
            $tmpImg  = tempnam(sys_get_temp_dir(), 'glpi_sig_') . '.png';
            file_put_contents($tmpImg, $imgData);
            if (file_exists($tmpImg) && filesize($tmpImg) > 0) {
                if ($lib === 'tcpdf') {
                    $pdf->Image($tmpImg, 20, null, 75, 25);
                } else {
                    $pdf->Image($tmpImg, 20, $pdf->GetY(), 75, 25);
                }
                $pdf->Ln(27);
            }
            @unlink($tmpImg);
        }

        $pdf->SetFont($font, '', 8);
        $pdf->Cell(0, 0, '', 'T', 1);
        $pdf->Ln(1);
        $pdf->Cell(0, 5, $this->enc($lib, $nomeCompleto . '   |   Data: ' . $hoje), 0, 1, 'L');

        // Emissão — bloco dedicado separado da seção Responsável
        $pdf->Ln(4);
        $pdf->SetFillColor(245, 247, 250);
        $pdf->SetFont($font, 'B', 8);
        $pdf->Cell(0, 6, $this->enc($lib, '  EMISSÃO DO TERMO'), 0, 1, 'L', true);
        $pdf->Ln(1);
        $pdf->SetFont($font, 'B', 8);
        $pdf->Cell(35, 5, $this->enc($lib, 'Emitido por:'), 0);
        $pdf->SetFont($font, '', 8);
        $pdf->Cell(0, 5, $this->enc($lib, $issuerNome), 0, 1);
        $pdf->SetFont($font, 'B', 8);
        $pdf->Cell(35, 5, $this->enc($lib, 'Data de emissão:'), 0);
        $pdf->SetFont($font, '', 8);
        $pdf->Cell(0, 5, $this->enc($lib, $hoje), 0, 1);
        $pdf->Ln(3);

        // Manifesto de auditoria
        $pdf->AddPage();
        $pdf->SetFont($font, 'B', 13);
        $pdf->Cell(0, 10, $this->enc($lib, 'MANIFESTO DE AUDITORIA ELETRONICA'), 0, 1, 'C');
        $pdf->Ln(3);

        $pdf->SetFont($font, '', 9);
        foreach ([
            'ID do Registro'      => (string)$termsId,
            'Data e Hora'         => $agora,
            'IP do Signatario'    => $_SERVER['REMOTE_ADDR']     ?? 'N/A',
            'Navegador'           => substr($_SERVER['HTTP_USER_AGENT'] ?? 'N/A', 0, 80),
            'Metodo de Validacao' => 'Assinatura Eletronica por Login Autenticado no GLPI',
            'Base Legal'          => 'MP n. 2.200-2/2001, art. 10, par. 2 e Lei n. 14.063/2020',
        ] as $label => $value) {
            $pdf->SetFont($font, 'B', 9); $pdf->Cell(60, 6, $this->enc($lib, $label . ':'), 0);
            $pdf->SetFont($font, '', 9);  $pdf->MultiCell(0, 6, $this->enc($lib, $value), 0);
        }

        $pdf->Ln(5);
        $pdf->SetFont($font, 'I', 8);
        $pdf->MultiCell(0, 5, $this->enc($lib,
            'O hash SHA-256 deste arquivo e calculado e registrado no banco de dados do sistema imediatamente ' .
            'apos a geracao, servindo como prova de integridade e nao-repudio do documento.'
        ), 0, 'J');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Termo de Devolução
    // ─────────────────────────────────────────────────────────────────────────

    public function generateDevolution(array $data, int $termsId, array $termFields, int $issuerId = 0): string {
        $lib = $this->loadLib();
        $pdf = $this->makePdf($lib, 'Termo de Devolucao');
        $this->renderDevolution($pdf, $lib, $data, $termsId, $termFields, $issuerId);
        return $this->savePdf($pdf, $termsId, $lib, 'devolucao');
    }

    private function renderDevolution(object $pdf, string $lib, array $data, int $termsId, array $termFields, int $issuerId = 0): void {
        $config       = $this->loadConfig();
        $font         = $this->fontName($lib);
        $nomeCompleto = trim(($data['firstname'] ?? '') . ' ' . ($data['realname'] ?? '')) ?: ($data['username'] ?? '-');
        $hoje         = date('d/m/Y');
        $agora        = date('d/m/Y \a\s H:i:s');
        $issuerNome   = $issuerId > 0 ? PluginTermosTerm::getIssuerName($issuerId) : '-';
        $dataAssinado = !empty($termFields['date_signed'])
            ? date('d/m/Y', strtotime($termFields['date_signed']))
            : '-';

        // Texto configurável com variáveis
        $devolTexto = $config['devol_texto']
            ?? "Pelo presente instrumento, a empresa declara ter recebido em devolucao o equipamento acima identificado, anteriormente sob responsabilidade do(a) colaborador(a) identificado(a) neste documento.\n\nO equipamento foi devolvido e inspecionado pelo setor de TI, encerrando-se assim todas as obrigacoes e responsabilidades do(a) colaborador(a) referentes a este equipamento, salvo eventuais danos identificados na vistoria de recebimento.\n\nEste documento anula o Termo de Responsabilidade anteriormente firmado.";

        $devolRodape = $config['devol_rodape'] ?? 'Documento emitido pelo setor de TI.';

        $vars = [
            '{nome}'            => $nomeCompleto,
            '{computador}'      => $data['computer_name'] ?? '-',
            '{serial}'          => $data['serial']         ?? '-',
            '{data_assinatura}' => $dataAssinado,
            '{numero_termo}'    => (string)$termsId,
            '{data_hoje}'       => $hoje,
            '{emissor}'         => $issuerNome,
        ];
        $devolTexto  = str_replace(array_keys($vars), array_values($vars), $devolTexto);
        $devolRodape = str_replace(array_keys($vars), array_values($vars), $devolRodape);

        // Campos dinâmicos com defaults
        $compFields = $config['fields_computer'] ?? [
            ['enabled' => true, 'label' => 'Nome',       'field' => 'computer_name'],
            ['enabled' => true, 'label' => 'Serie',      'field' => 'serial'],
            ['enabled' => true, 'label' => 'Patrimonio', 'field' => 'patrimonio'],
            ['enabled' => true, 'label' => 'Setor',      'field' => 'entity'],
        ];
        $userFields = $config['fields_user'] ?? [
            ['enabled' => true, 'label' => 'Nome',     'field' => 'fullname'],
            ['enabled' => true, 'label' => 'Login',    'field' => 'username'],
            ['enabled' => true, 'label' => 'Telefone', 'field' => 'phone'],
        ];

        // ── Logo ──────────────────────────────────────────────────────────────
        $this->addLogo($pdf, $lib);

        // ── Título ────────────────────────────────────────────────────────────
        $pdf->SetFont($font, 'B', 13);
        $pdf->Cell(0, 8, $this->enc($lib, 'TERMO DE DEVOLUCAO DE EQUIPAMENTO'), 0, 1, 'C');
        $pdf->SetFont($font, '', 8);
        $pdf->Cell(0, 5, $this->enc($lib, 'No ' . $termsId . '   |   ' . $hoje), 0, 1, 'C');
        $pdf->Ln(3);

        // ── Duas colunas: Equipamento | Responsável ───────────────────────────
        $colW   = 85;
        $yStart = $pdf->GetY();

        // Coluna esquerda — Equipamento
        $this->sectionTitle($pdf, $lib, $font, 'EQUIPAMENTO');
        foreach ($compFields as $cf) {
            if (!($cf['enabled'] ?? true)) continue;
            $this->row($pdf, $lib, $font, $cf['label'], $this->resolveField($data, $cf['field']));
        }
        if (($data['item_type'] ?? '') === 'Phone' && ($data['line_number'] ?? '-') !== '-') {
            $lineLabel = ($data['line_name'] ?? '-') !== ($data['line_number'] ?? '-') && ($data['line_name'] ?? '-') !== '-'
                ? $data['line_number'] . ' (' . $data['line_name'] . ')'
                : $data['line_number'];
            $this->row($pdf, $lib, $font, 'Linha Telefonica', $lineLabel);
        }
        $yLeft = $pdf->GetY();

        // Coluna direita — Responsável
        $pdf->SetXY(20 + $colW + 5, $yStart);
        $this->sectionTitle($pdf, $lib, $font, 'RESPONSAVEL');
        foreach ($userFields as $uf) {
            if (!($uf['enabled'] ?? true)) continue;
            $pdf->SetX(20 + $colW + 5);
            $this->row($pdf, $lib, $font, $uf['label'], $this->resolveField($data, $uf['field']));
        }
        // Data do recebimento original
        $pdf->SetX(20 + $colW + 5);
        $this->row($pdf, $lib, $font, 'Data de recebimento', $dataAssinado);
        $yRight = $pdf->GetY();

        $pdf->SetY(max($yLeft, $yRight) + 3);

        // ── Declaração ────────────────────────────────────────────────────────
        $this->sectionTitle($pdf, $lib, $font, 'DECLARACAO');
        $pdf->SetFont($font, '', 9);
        $pdf->MultiCell(0, 5, $this->enc($lib, $devolTexto), 0, 'J');
        $pdf->Ln(3);

        // ── Rodapé legal ──────────────────────────────────────────────────────
        $pdf->SetFont($font, 'I', 7);
        $pdf->MultiCell(0, 4, $this->enc($lib, $devolRodape), 0, 'C');
        $pdf->Ln(4);

        // ── Emissão do Termo (sem assinaturas) ────────────────────────────────
        $pdf->SetFillColor(245, 247, 250);
        $pdf->SetFont($font, 'B', 8);
        $pdf->Cell(0, 6, $this->enc($lib, '  EMISSAO DO TERMO'), 0, 1, 'L', true);
        $pdf->Ln(1);
        $pdf->SetFont($font, 'B', 8);
        $pdf->Cell(35, 5, $this->enc($lib, 'Emitido por:'), 0);
        $pdf->SetFont($font, '', 8);
        $pdf->Cell(0, 5, $this->enc($lib, $issuerNome), 0, 1);
        $pdf->SetFont($font, 'B', 8);
        $pdf->Cell(35, 5, $this->enc($lib, 'Data de emissao:'), 0);
        $pdf->SetFont($font, '', 8);
        $pdf->Cell(0, 5, $this->enc($lib, $hoje), 0, 1);
        $pdf->Ln(3);

        // ── Manifesto de Auditoria ────────────────────────────────────────────
        $pdf->AddPage();
        $pdf->SetFont($font, 'B', 13);
        $pdf->Cell(0, 10, $this->enc($lib, 'MANIFESTO DE AUDITORIA ELETRONICA'), 0, 1, 'C');
        $pdf->Ln(3);
        foreach ([
            'ID do Termo'             => (string)$termsId,
            'Data da Devolucao'       => $agora,
            'Termo Original Assinado' => $dataAssinado,
            'Emitido por'             => $issuerNome,
            'IP do Solicitante'       => $_SERVER['REMOTE_ADDR'] ?? 'N/A',
            'Base Legal'              => 'MP n. 2.200-2/2001, art. 10, par. 2 e Lei n. 14.063/2020',
        ] as $label => $value) {
            $pdf->SetFont($font, 'B', 9); $pdf->Cell(60, 6, $this->enc($lib, $label . ':'), 0);
            $pdf->SetFont($font, '', 9);  $pdf->MultiCell(0, 6, $this->enc($lib, $value), 0);
        }

        $pdf->Ln(5);
        $pdf->SetFont($font, 'I', 8);
        $pdf->MultiCell(0, 5, $this->enc($lib,
            'O hash SHA-256 deste arquivo e calculado e registrado no banco de dados do sistema imediatamente ' .
            'apos a geracao, servindo como prova de integridade e nao-repudio do documento.'
        ), 0, 'J');
    }
}
