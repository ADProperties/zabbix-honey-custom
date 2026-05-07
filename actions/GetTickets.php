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

    protected function doAction(): void {
        header('Content-Type: application/json');

        $file = __DIR__ . '/../tickets.json';

        $tickets = file_exists($file)
            ? json_decode(file_get_contents($file), true)
            : [];

        if (!is_array($tickets)) {
            $tickets = [];
        }

        echo json_encode($tickets);
        exit();
    }
}