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

    protected function doAction(): void {

        // =========================================================
        // INPUT
        // =========================================================
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

        // =========================================================
        // BUSCAR HOST + TAG CLIENT
        // =========================================================
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
                $clientName = $tag['value'];
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

        // =========================================================
        // JIRA CONFIG
        // =========================================================
        $jira_url  = 'https://glintthsdev.atlassian.net';
        $jira_user = 'david.dias@glintt.com';
        $jira_token = ''; // <<< API TOKEN

        $project_key     = 'GX';
        $issue_type      = 'Monitorização';
        $client_field_id = 'customfield_10139';

        $auth = base64_encode($jira_user . ':' . $jira_token);

        // =========================================================
        // BUSCAR UTILIZADOR NO JIRA (IGUAL AO SCRIPT BOM)
        // =========================================================
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
            if (is_array($users) && count($users) > 0) {
                $userId = $users[0]['accountId'] ?? null;
            }
        }

        // =========================================================
        // CRIAR TICKET (API v3)
        // =========================================================
        $fields = [
            'project'   => ['key' => $project_key],
            'issuetype' => ['name' => $issue_type],
            'summary'   => "{$widget} | {$label}",
            'description' =>
                "Cliente: {$clientName}\n".
                "Host: {$hostName}\n".
                "Valor: {$value}\n".
                "Criado no Zabbix por: {$zabbixUser}",
            $client_field_id => ['value' => $clientName]
        ];

        if ($userId !== null) {
            $fields['reporter'] = ['accountId' => $userId];
            $fields['assignee'] = ['accountId' => $userId];
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
            echo json_encode(['success' => false, 'message' => $resp]);
            exit;
        }

        $data = json_decode($resp, true);

        // =========================================================
        // REGISTO LOCAL PARA 👁️
        // =========================================================
        $file = __DIR__ . '/../tickets.json';
        $tickets = file_exists($file)
            ? json_decode(file_get_contents($file), true)
            : [];

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