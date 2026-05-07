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

        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true);

        if (is_array($input) && !empty($input['zero_itemids']) && is_array($input['zero_itemids'])) {
            foreach ($input['zero_itemids'] as $itemid) {
                $itemKey = (string)$itemid;

                if (isset($tickets[$itemKey])) {
                    unset($tickets[$itemKey]);
                }
            }

            file_put_contents(
                $file,
                json_encode($tickets, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );
        }

        echo json_encode($tickets);
        exit();
    }
}