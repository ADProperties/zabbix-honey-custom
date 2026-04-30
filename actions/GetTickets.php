<?php declare(strict_types = 0);
namespace Modules\HoneyCustom\Actions;

use CController;
use CControllerResponseData;

class GetTickets extends CController {

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool { return true; }
    protected function checkPermissions(): bool { return true; }

    protected function doAction(): void {
        $file = __DIR__ . '/../tickets.json';
        $tickets = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
        
        $input = json_decode(file_get_contents('php://input'), true);
        $zeroHosts = $input['zero_hosts'] ?? [];
        
        // Se houver anomalias a zeros, apaga do ficheiro (Fechou o Ticket)
        $changed = false;
        foreach ($zeroHosts as $zh) {
            if (isset($tickets[$zh])) {
                unset($tickets[$zh]);
                $changed = true;
            }
        }
        
        if ($changed) {
            file_put_contents($file, json_encode($tickets));
        }

        header('Content-Type: application/json');
        echo json_encode($tickets);
        exit();
    }
}