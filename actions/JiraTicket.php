<?php declare(strict_types = 0);
namespace Modules\HoneyCustom\Actions;

use CController;
use CControllerResponseData;
use CWebUser;

class JiraTicket extends CController {

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool { return true; }
    protected function checkPermissions(): bool { return true; }

    private function normalizar($str) {
        $str = htmlentities($str, ENT_QUOTES, 'UTF-8');
        $str = preg_replace('~&([a-z]{1,2})(acute|cedil|circ|grave|lig|orn|ring|slash|th|tilde|uml);~i', '$1', $str);
        $str = html_entity_decode($str, ENT_QUOTES, 'UTF-8');
        return str_replace([' ', '-', '.'], '', strtolower($str));
    }

    protected function doAction(): void {
        $input = json_decode(file_get_contents('php://input'), true);
        $host = $input['host'] ?? 'Desconhecido';
        $value = $input['value'] ?? '0';
        $widget = $input['widget'] ?? 'Monitorização';

        // =========================================================
        // 1. CONFIGURAÇÕES (Verifica se estão corretas)
        // =========================================================
        $jira_url = 'https://glintthsdev.atlassian.net';
        $jira_user = 'david.dias@glintt.com';
        $jira_token = '39519b140c8c6c956c997aae143e47d1e646bdf334ffc1f86f983dd50298d92d';
        
        $project_key = 'GX';
        $issue_type = 'Monitorização';
        $confluence_page_id = '322404356';
        $client_field_id = 'customfield_10139';
        $capa_field_id = 'customfield_10683';
        
        $auth = base64_encode($jira_user . ':' . $jira_token);
        $capaEncontrada = null;

        // =========================================================
        // 2. BUSCA DA CAPA NO CONFLUENCE (Nova Lógica com DOMDocument)
        // =========================================================
        $ch = curl_init($jira_url . "/wiki/rest/api/content/" . $confluence_page_id . "?expand=body.storage");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Basic ' . $auth]);
        $confResp = curl_exec($ch);
        curl_close($ch);

        if ($confResp) {
            $confJson = json_decode($confResp, true);
            $html = $confJson['body']['storage']['value'] ?? '';

            if ($html !== '') {
                $dom = new \DOMDocument();
                @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
                $tables = $dom->getElementsByTagName('table');
                
                if ($tables->length > 0) {
                    $rows = $tables->item(0)->getElementsByTagName('tr');
                    foreach ($rows as $row) {
                        $cols = $row->getElementsByTagName('td');
                        if ($cols->length >= 2) {
                            $colNome = trim($cols->item(0)->textContent);
                            $colCapa = trim($cols->item(1)->textContent);
                            $colSigla = ($cols->length >= 5) ? trim($cols->item(4)->textContent) : '';

                            $normBusca = $this->normalizar($host);
                            $normNome = $this->normalizar($colNome);
                            $normSigla = $this->normalizar($colSigla);

                            // Match igual ao teu script original
                            if ($normNome === $normBusca || ($normSigla !== '' && $normSigla === $normBusca) || strpos($normNome, $normBusca) !== false) {
                                if ($colCapa !== '' && $colCapa !== 'N/D') {
                                    $capaEncontrada = $colCapa;
                                }
                                break;
                            }
                        }
                    }
                }
            }
        }

        // =========================================================
        // 3. BUSCAR O ID DO UTILIZADOR (Logado no Zabbix -> Jira)
        // =========================================================
        $zabbix_user_name = CWebUser::$data['name'] . ' ' . CWebUser::$data['surname'];
        $userId = null;
        
        $chU = curl_init($jira_url . "/rest/api/3/user/search?query=" . urlencode($zabbix_user_name));
        curl_setopt($chU, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chU, CURLOPT_HTTPHEADER, ['Authorization: Basic ' . $auth]);
        $userResp = curl_exec($chU);
        curl_close($chU);
        
        if ($userResp) {
            $users = json_decode($userResp, true);
            if (is_array($users) && count($users) > 0) {
                $userId = $users[0]['accountId'] ?? null;
            }
        }

        // =========================================================
        // 4. CRIAR TICKET NO JIRA
        // =========================================================
        $description = "Ticket criado manualmente via Dashboard (Custom Honey).\n\n" .
                       "*Instituição:* " . $host . "\n" .
                       "*Métrica avaliada:* " . $widget . "\n" .
                       "*Valor atual:* *" . $value . "*";

        $fields = [
            'project' => ['key' => $project_key],
            'issuetype' => ['name' => $issue_type],
            'summary' => $widget . " - " . $host . " (" . $value . ")",
            'description' => $description,
        ];

        if ($userId) {
            $fields['reporter'] = ['accountId' => $userId];
            $fields['assignee'] = ['accountId' => $userId];
        }

        $payload = ['fields' => $fields];
        if ($client_field_id) $payload['fields'][$client_field_id] = ['value' => $host];
        if ($capa_field_id && $capaEncontrada) $payload['fields'][$capa_field_id] = $capaEncontrada;

        $chJ = curl_init($jira_url . "/rest/api/2/issue");
        curl_setopt($chJ, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chJ, CURLOPT_POST, true);
        curl_setopt($chJ, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($chJ, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . $auth,
            'Content-Type: application/json'
        ]);
        $jiraResp = curl_exec($chJ);
        $httpCode = curl_getinfo($chJ, CURLINFO_HTTP_CODE);
        curl_close($chJ);

        // =========================================================
        // 5. RESPOSTA AO POP-UP
        // =========================================================
        if ($httpCode >= 200 && $httpCode < 300) {
            $jiraData = json_decode($jiraResp, true);
            $this->setResponse(new CControllerResponseData([
                'main_block' => json_encode(['success' => true, 'message' => "Ticket " . $jiraData['key'] . " criado com sucesso!"])
            ]));
        } else {
            $this->setResponse(new CControllerResponseData([
                'main_block' => json_encode(['success' => false, 'message' => "Erro no Jira: " . $jiraResp])
            ]));
        }
    }
}