<?php declare(strict_types = 0);

namespace Modules\HoneyCustom\Actions;

use CController;
use API;

class JiraTicket extends CController {

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool { return true; }
    protected function checkPermissions(): bool { return true; }

    // ---------- TEXTO → ADF (Jira Cloud) ----------
    private function toAdf(string $text): array {
        $content = [];

        foreach (explode("\n", trim($text)) as $line) {
            $line = trim($line);
            if ($line === '') continue;

            $content[] = [
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => $line
                ]]
            ];
        }

        return [
            'type' => 'doc',
            'version' => 1,
            'content' => $content
        ];
    }

    // ---------- NORMALIZA STRING (igual ao script) ----------
    private function normalize(string $s): string {
        $map = [
            'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
            'é'=>'e','ê'=>'e','è'=>'e','ë'=>'e',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
            'ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
            'ç'=>'c'
        ];
        return str_replace(
            array_keys($map),
            array_values($map),
            strtolower(preg_replace('/[\s\-.]/', '', $s))
        );
    }

    protected function doAction(): void {

        // ---------- INPUT ----------
        $input = json_decode(file_get_contents('php://input'), true);

        $hostid = $input['hostid'] ?? null;
        $label  = $input['label'] ?? '';
        $value  = (float)($input['value'] ?? 0);

        if (!$hostid || $value === 0) {
            echo json_encode(['success'=>false,'message'=>'Dados inválidos']);
            exit;
        }

        // ---------- HOST + CLIENT ----------
        $host = API::Host()->get([
            'output'=>['name'],
            'hostids'=>[$hostid],
            'selectTags'=>['tag','value']
        ])[0];

        $client = null;
        foreach ($host['tags'] as $t) {
            if ($t['tag'] === 'Client') {
                $client = $t['value'];
            }
        }

        if (!$client) {
            echo json_encode(['success'=>false,'message'=>'Falta TAG Client']);
            exit;
        }

        // ---------- PRODUTO (FIXO) ----------
        $productValue   = 'Mozy Platform';
        $productFieldId = 'customfield_10768';

        // ---------- JIRA CONFIG ----------
        $jiraUrl   = 'https://glintthsdev.atlassian.net';
        $jiraUser  = 'david.dias@glintt.com';
        $jiraToken = 'API_TOKEN_AQUI';

        $auth = base64_encode("$jiraUser:$jiraToken");

        // ---------- CAPA (CONFLUENCE) ----------
        $capa = null;
        $pageId = '322404356';

        $html = file_get_contents(
            "$jiraUrl/wiki/rest/api/content/$pageId?expand=body.storage",
            false,
            stream_context_create([
                'http'=>[
                    'header'=>"Authorization: Basic $auth"
                ]
            ])
        );

        if ($html) {
            preg_match_all('/<tr.*?<\/tr>/s', $html, $rows);
            foreach ($rows[0] as $row) {
                preg_match_all('/<td.*?<\/td>/s', $row, $cols);
                if (count($cols[0]) >= 2) {
                    $nome = strip_tags($cols[0][0]);
                    $capaCol = strip_tags($cols[0][1]);

                    if ($this->normalize($nome) === $this->normalize($client) && $capaCol !== 'N/D') {
                        $capa = $capaCol;
                        break;
                    }
                }
            }
        }

        // ---------- CRIAR TICKET ----------
        $fields = [
            'project' => ['key'=>'GX'],
            'issuetype' => ['name'=>'Monitorização'],
            'summary' => mb_substr("Monitorização | $label", 0, 250),
            'description' => $this->toAdf(
                "Cliente: $client\n".
                "Produto: $productValue\n".
                "Valor: $value"
            ),
            'customfield_10139' => ['value'=>$client],
            $productFieldId     => ['value'=>$productValue]
        ];

        if ($capa) {
            $fields['customfield_10683'] = $capa;
        }

        $resp = file_get_contents(
            "$jiraUrl/rest/api/3/issue",
            false,
            stream_context_create([
                'http'=>[
                    'method'=>'POST',
                    'header'=>"Authorization: Basic $auth\r\nContent-Type: application/json",
                    'content'=>json_encode(['fields'=>$fields])
                ]
            ])
        );

        echo json_encode(['success'=>true,'message'=>'Ticket criado']);
        exit;
    }
}