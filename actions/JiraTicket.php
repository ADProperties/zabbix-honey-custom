<?php declare(strict_types = 0);

namespace Modules\HoneyCustom\Actions;

use CController;
use CWebUser;
use API;

class JiraTicket extends CController {

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool { return true; }
    protected function checkPermissions(): bool { return true; }

    // ---------------------------------------------------------
    // CONVERTE TEXTO PARA ADF (API v3)
    // ---------------------------------------------------------
    private function toAdfParagraph(string $text): array {
        $lines = explode("\n", trim($text));
        $content = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $content[] = [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $line
                    ]
                ]
            ];
        }

        return [
            'type' => 'doc',
            'version' => 1,
            'content' => $content
        ];
    }

    // ---------------------------------------------------------
    // NORMALIZA TEXTO (igual ao script 5 estrelas)
    // ---------------------------------------------------------
    private function normalizar(string $str): string {
        $str = mb_strtolower($str, 'UTF-8');

        $map = [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c'
        ];

        $str = strtr($str, $map);
        $str = preg_replace('/[\s\-.]+/', '', $str);

        return $str;
    }

    // ---------------------------------------------------------
    // LIMPA HTML / ENTITIES (igual à ideia do script)
    // ---------------------------------------------------------
    private function cleanCell(string $html): string {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xc2\xa0", ' ', $text); // nbsp
        return trim($text);
    }

    protected function doAction(): void {

        // =====================================================
        // INPUT
        // =====================================================
        $input  = json_decode(file_get_contents('php://input'), true);

        $hostid = $input['hostid'] ?? null;
        $value  = (float)($input['value'] ?? 0);
        $label  = trim($input['label'] ?? '');
        $widget = $input['widget'] ?? 'Monitorização';

        if (!$hostid) {
            echo json_encode(['success' => false, 'message' => 'Host inválido']);
            exit;
        }

        if ($value === 0.0) {
            echo json_encode([
                'success' => false,
                'message' => 'Não é possível criar ticket quando o valor é 0.'
            ]);
            exit;
        }

        // =====================================================
        // HOST + TAG CLIENT
        // =====================================================
        $hosts = API::Host()->get([
            'output' => ['name'],
            'hostids' => [$hostid],
            'selectTags' => ['tag', 'value']
        ]);

        if (!$hosts) {
            echo json_encode(['success' => false, 'message' => 'Host não encontrado']);
            exit;
        }

        $hostName = $hosts[0]['name'];
        $clientName = null;

        foreach ($hosts[0]['tags'] as $tag) {
            if (strcasecmp($tag['tag'], 'Client') === 0) {
                $clientName = trim($tag['value']);
                break;
            }
        }

        if (!$clientName) {
            echo json_encode([
                'success' => false,
                'message' => 'O host não tem a TAG "Client" definida.'
            ]);
            exit;
        }

        if ($label === '') {
            $label = $hostName;
        }

        // =====================================================
        // PREVENIR TICKET DUPLICADO POR LABEL
        // =====================================================
        $file = __DIR__ . '/../tickets.json';
        $tickets = file_exists($file)
            ? json_decode(file_get_contents($file), true)
            : [];

        if (isset($tickets[$label])) {
            echo json_encode([
                'success' => false,
                'message' => 'Já existe um ticket associado a este item: ' . $tickets[$label]['jira']
            ]);
            exit;
        }

        // =====================================================
        // JIRA CONFIG
        // =====================================================
        $jira_url  = 'https://glintthsdev.atlassian.net';
        $jira_user = 'david.dias@glintt.com';
        $jira_token = ''; // <<< API TOKEN

        $project_key        = 'GX';
        $issue_type         = 'Monitorização';
        $client_field_id    = 'customfield_10139';
        $product_field_id   = 'customfield_10768';
        $product_value      = 'Mozy Platform';
        $capa_field_id      = 'customfield_10683';
        $confluence_page_id = '322404356';

        $auth = base64_encode($jira_user . ':' . $jira_token);

        // =====================================================
        // BUSCAR CAPA NO CONFLUENCE (ESTILO SCRIPT 5 ESTRELAS)
        // =====================================================
        $capaEncontrada = null;

        $confUrl = $jira_url . '/wiki/rest/api/content/' . $confluence_page_id . '?expand=body.storage';

        $chConf = curl_init($confUrl);
        curl_setopt_array($chConf, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . $auth
            ]
        ]);

        $confResp = curl_exec($chConf);
        $confCode = curl_getinfo($chConf, CURLINFO_HTTP_CODE);
        curl_close($chConf);

        if ($confResp && $confCode >= 200 && $confCode < 300) {
            $confJson = json_decode($confResp, true);
            $html = $confJson['body']['storage']['value'] ?? '';

            if ($html !== '') {
                if (preg_match('/<table[\s\S]*?<\/table>/i', $html, $tableMatch)) {
                    if (preg_match_all('/<tr[\s\S]*?<\/tr>/i', $tableMatch[0], $rows)) {
                        $termoBuscaNorm = $this->normalizar($clientName);

                        foreach ($rows[0] as $index => $rowHtml) {
                            // ignora header
                            if ($index === 0) {
                                continue;
                            }

                            if (!preg_match_all('/<td[\s\S]*?<\/td>/i', $rowHtml, $cols)) {
                                continue;
                            }

                            if (count($cols[0]) < 2) {
                                continue;
                            }

                            // 0: Nome | 1: Capa | 4: Sigla
                            $colNome  = $this->cleanCell($cols[0][0]);
                            $colCapa  = $this->cleanCell($cols[0][1]);
                            $colSigla = (count($cols[0]) >= 5)
                                ? $this->cleanCell($cols[0][4])
                                : '';

                            $colNomeNorm  = $this->normalizar($colNome);
                            $colSiglaNorm = $this->normalizar($colSigla);

                            $matchEncontrado = false;

                            // 1. Match exacto pelo nome
                            if ($colNomeNorm === $termoBuscaNorm) {
                                $matchEncontrado = true;
                            }
                            // 2. Match pela sigla
                            elseif ($colSiglaNorm !== '' && $colSiglaNorm === $termoBuscaNorm) {
                                $matchEncontrado = true;
                            }
                            // 3. Match parcial
                            elseif (
                                strpos($colNomeNorm, $termoBuscaNorm) !== false ||
                                strpos($termoBuscaNorm, $colNomeNorm) !== false
                            ) {
                                $matchEncontrado = true;
                            }

                            if ($matchEncontrado) {
                                if ($colCapa !== '' && strtoupper($colCapa) !== 'N/D') {
                                    $capaEncontrada = $colCapa;
                                }
                                break;
                            }
                        }
                    }
                }
            }
        }

        // =====================================================
        // BUSCAR UTILIZADOR NO JIRA
        // =====================================================
        $zabbixUser = CWebUser::$data['name'] . ' ' . CWebUser::$data['surname'];
        $userId = null;

        $chU = curl_init(
            $jira_url . '/rest/api/3/user/search?query=' . urlencode($zabbixUser)
        );

        curl_setopt_array($chU, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . $auth
            ]
        ]);

        $userResp = curl_exec($chU);
        curl_close($chU);

        if ($userResp) {
            $users = json_decode($userResp, true);
            if (is_array($users) && !empty($users)) {
                $userId = $users[0]['accountId'] ?? null;
            }
        }

        // =====================================================
        // CRIAR TICKET (API v3)
        // =====================================================
        $descriptionText =
            "Cliente: {$clientName}\n" .
            "Produto: {$product_value}\n" .
            "Host: {$hostName}\n" .
            "Valor: {$value}\n" .
            "Criado no Zabbix por: {$zabbixUser}";

        $summary = mb_substr("{$widget} | {$label}", 0, 250);

        $fields = [
            'project'            => ['key' => $project_key],
            'issuetype'          => ['name' => $issue_type],
            'summary'            => $summary,
            'description'        => $this->toAdfParagraph($descriptionText),
            $client_field_id     => ['value' => $clientName],
            $product_field_id    => ['value' => $product_value]
        ];

        if ($userId !== null) {
            $fields['reporter'] = ['accountId' => $userId];
            $fields['assignee'] = ['accountId' => $userId];
        }

        // CAPA igual ao script: string direta no campo
        if ($capaEncontrada !== null && $capaEncontrada !== '') {
            $fields[$capa_field_id] = $capaEncontrada;
        }

        $payload = ['fields' => $fields];

        $ch = curl_init($jira_url . '/rest/api/3/issue');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . $auth,
                'Content-Type: application/json'
            ]
        ]);

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code < 200 || $code >= 300) {
            echo json_encode([
                'success' => false,
                'message' => $resp
            ]);
            exit;
        }

        $data = json_decode($resp, true);

        // =====================================================
        // REGISTO LOCAL PARA 👁️
        // =====================================================
        $tickets[$label] = [
            'user' => $zabbixUser,
            'jira' => $data['key']
        ];

        file_put_contents($file, json_encode($tickets));

        echo json_encode([
            'success' => true,
            'message' => "Ticket {$data['key']} criado com sucesso!"
        ]);
        exit;
    }
}
