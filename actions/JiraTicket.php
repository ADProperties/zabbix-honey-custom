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
        $value  = $input['value']  ?? '0';
        $widget = $input['widget'] ?? 'Monitorização';

        if (!$hostid) {
            echo json_encode(['success' => false, 'message' => 'Host inválido']);
            exit;
        }

        if ((float)$value === 0.0) {
            echo json_encode([
                'success' => false,
                'message' => 'Não é possível criar ticket quando o valor é 0.'
            ]);
            exit;
        }

        // =========================================================
        // BUSCAR HOST NAME E TAG CLIENT
        // =========================================================
        $hostName = null;
        $clientName = null;

        $hosts = API::Host()->get([
            'output' => ['name'],
            'hostids' => [$hostid],
            'selectTags' => ['tag', 'value']
        ]);

        if ($hosts) {
            $hostName = $hosts[0]['name'];

            foreach ($hosts[0]['tags'] as $tag) {
                if (strcasecmp($tag['tag'], 'Client') === 0) {
                    $clientName = $tag['value'];
                    break;
                }
            }
        }

        if (!$clientName) {
            echo json_encode([
                'success' => false,
                'message' => 'O host não tem a TAG "Client" definida.'
            ]);
            exit;
        }

        // =========================================================
        // CONFIGURAÇÕES JIRA
        // =========================================================
        $jira_url  = 'https://glintthsdev.atlassian.net';
        $jira_user = 'david.dias@glintt.com';
        $jira_token = ''; // <<<<<< TOKEN AQUI

        $project_key     = 'GX';
        $issue_type      = 'Monitorização';
        $client_field_id = 'customfield_10139';

        $auth = base64_encode($jira_user . ':' . $jira_token);

        // =========================================================
        // CRIAR TICKET
        // =========================================================
        $description =
            "Ticket criado manualmente via Dashboard.\n\n".
            "*Host:* {$hostName}\n".
            "*Cliente:* {$clientName}\n".
            "*Métrica:* {$widget}\n".
            "*Valor:* {$value}";

        $fields = [
            'project'     => ['key' => $project_key],
            'issuetype'   => ['name' => $issue_type],
            'summary'     => "{$widget} - {$clientName}",
            'description' => $description,
            $client_field_id => ['value' => $clientName]
        ];

        $payload = ['fields' => $fields];

        $ch = curl_init($jira_url . '/rest/api/2/issue');
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

        if ($code >= 200 && $code < 300) {
            $data = json_decode($resp, true);
            echo json_encode([
                'success' => true,
                'message' => "Ticket {$data['key']} criado com sucesso!"
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => "Erro no Jira: {$resp}"
            ]);
        }
        exit;
    }
}