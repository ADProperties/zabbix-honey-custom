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
            echo json_encode(['success' => false, 'message' => 'Valor 0 — ticket bloqueado']);
            exit;
        }

        // ===============================
        // HOST + TAG CLIENT
        // ===============================
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
        $client = null;

        foreach ($hosts[0]['tags'] as $t) {
            if (strcasecmp($t['tag'], 'Client') === 0) {
                $client = $t['value'];
                break;
            }
        }

        if (!$client) {
            echo json_encode(['success' => false, 'message' => 'TAG Client não definida']);
            exit;
        }

        if ($label === '') {
            $label = $hostName;
        }

        // ===============================
        // JIRA CONFIG
        // ===============================
        $jira_url  = 'https://glintthsdev.atlassian.net';
        $jira_user = 'david.dias@glintt.com';
        $jira_token = ''; // <<< TOKEN AQUI

        $project_key = 'GX';
        $issue_type  = 'Monitorização';
        $client_field_id = 'customfield_10139';

        $auth = base64_encode($jira_user . ':' . $jira_token);

        $payload = [
            'fields' => [
                'project'     => ['key' => $project_key],
                'issuetype'   => ['name' => $issue_type],
                'summary'     => "{$widget} | {$label}",
                'description' =>
                    "Cliente: {$client}\n".
                    "Host: {$hostName}\n".
                    "Valor: {$value}\n".
                    "Criado por: ".CWebUser::$data['name'].' '.CWebUser::$data['surname'],
                $client_field_id => ['value' => $client]
            ]
        ];

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

        if ($code < 200 || $code >= 300) {
            echo json_encode(['success' => false, 'message' => $resp]);
            exit;
        }

        $data = json_decode($resp, true);

        // ===============================
        // REGISTO LOCAL (👁️ + USER)
        // ===============================
        $file = __DIR__ . '/../tickets.json';
        $tickets = file_exists($file)
            ? json_decode(file_get_contents($file), true)
            : [];

        $tickets[$label] = [
            'user' => CWebUser::$data['name'].' '.CWebUser::$data['surname'],
            'jira' => $data['key']
        ];

        file_put_contents($file, json_encode($tickets));

        echo json_encode([
            'success' => true,
            'message' => "Ticket {$data['key']} criado com sucesso"
        ]);
        exit;
    }
}