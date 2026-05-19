<?php declare(strict_types = 0);

namespace Modules\HoneyCustom\Actions;

use CController;

class GetTickets extends CController {

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return true;
    }

    // ---------------------------------------------------------
    // ✅ BUSCAR STATUS DO JIRA
    // ---------------------------------------------------------
    private function getJiraStatus(string $issueKey, string $auth, string $jira_url): ?string {
        $ch = curl_init($jira_url . '/rest/api/3/issue/' . $issueKey);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . $auth,
                'Accept: application/json'
            ]
        ]);

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code < 200 || $code >= 300 || !$resp) {
            return null;
        }

        $data = json_decode($resp, true);

        return $data['fields']['status']['name'] ?? null;
    }

    // ---------------------------------------------------------
    // ✅ MAIN
    // ---------------------------------------------------------
    protected function doAction(): void {
        header('Content-Type: application/json');

        $file = __DIR__ . '/../tickets.json';

        $tickets = file_exists($file)
            ? json_decode(file_get_contents($file), true)
            : [];

        if (!is_array($tickets)) {
            $tickets = [];
        }

        // =====================================================
        // ✅ CONFIG JIRA (IGUAL AO OUTRO FICHEIRO)
        // =====================================================
        $jira_url = 'https://glintthsdev.atlassian.net';
        $jira_user = 'david.dias@glintt.com';
        $jira_token = ''; // <<< mete aqui o mesmo token que usas no JiraTicket.php

        $auth = base64_encode($jira_user . ':' . $jira_token);

        $updatedTickets = [];

        foreach ($tickets as $itemid => $ticket) {

            // segurança
            if (empty($ticket['jira'])) {
                continue;
            }

            // =================================================
            // ✅ OBTER STATUS DO JIRA
            // =================================================
            $status = $this->getJiraStatus($ticket['jira'], $auth, $jira_url);

            // se falhar a API mantém (não perder info)
            if ($status === null) {
                $updatedTickets[$itemid] = $ticket;
                continue;
            }

            // =================================================
            // ✅ DETETAR SE ESTÁ FECHADO
            // (robusto para vários workflows Jira)
            // =================================================
            $statusLower = strtolower($status);

            $isClosed =
                strpos($statusLower, 'done') !== false ||
                strpos($statusLower, 'Closed') !== false ||
                strpos($statusLower, 'Fechado') !== false ||
                strpos($statusLower, 'finished') !== false;

            if (!$isClosed) {
                // mantém se ainda estiver aberto
                $updatedTickets[$itemid] = $ticket;
            }
            // se estiver fechado → não adiciona = remove automaticamente
        }

        // =====================================================
        // ✅ GUARDA NOVO ESTADO (REMOVE OS FECHADOS)
        // =====================================================
        file_put_contents(
            $file,
            json_encode($updatedTickets, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        // =====================================================
        // ✅ DEVOLVE SOMENTE OS ATIVOS
        // =====================================================
        echo json_encode($updatedTickets);
        exit();
    }
}