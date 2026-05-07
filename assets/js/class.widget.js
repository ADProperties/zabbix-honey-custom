/*
** Copyright (C) 2001-2026 Zabbix SIA
**
** This program is free software: you can redistribute it and/or modify it under the terms of
** the GNU Affero General Public License as published by the Free Software Foundation, version 3.
**
** This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
** without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
** See the GNU Affero General Public License for more details.
*/

class CWidgetCustomHoney extends CWidget {

    static ZBX_STYLE_DASHBOARD_WIDGET_PADDING_V = 8;
    static ZBX_STYLE_DASHBOARD_WIDGET_PADDING_H = 10;

    #honeycomb = null;
    #user_interacting = false;
    #interacting_timeout_id;
    #resize_timeout_id;
    #items_max_count = 1000;
    #items_loaded_count = 0;

    #cells_data = new Map();
    #selected_hostid = null;
    #selected_itemid = null;

    onActivate() {
        this.#items_max_count = this.#getItemsMaxCount();
    }

    onDeactivate() {
        clearTimeout(this.#resize_timeout_id);
    }

    isUserInteracting() {
        return this.#user_interacting || super.isUserInteracting();
    }

    onResize() {
        if (this.getState() !== WIDGET_STATE_ACTIVE) {
            return;
        }

        clearTimeout(this.#resize_timeout_id);

        const old_items_max_count = this.#items_max_count;
        this.#items_max_count = this.#getItemsMaxCount();

        if (this.#items_max_count > old_items_max_count &&
            this.#items_loaded_count >= old_items_max_count) {
            this._startUpdating();
        }

        this.#resize_timeout_id = setTimeout(() => {
            if (this.#honeycomb !== null) {
                this.#honeycomb.setSize(super._getContentsSize());
            }
        }, 100);
    }

    getUpdateRequestData() {
        return {
            ...super.getUpdateRequestData(),
            max_items: this.#items_max_count,
            with_config: this.#honeycomb === null ? 1 : undefined
        };
    }

    setContents(response) {

        /* =========================================================
           INICIALIZA O HONEYCOMB
        ========================================================= */
        if (this.#honeycomb === null) {

            const padding = {
                vertical: CWidgetCustomHoney.ZBX_STYLE_DASHBOARD_WIDGET_PADDING_V,
                horizontal: CWidgetCustomHoney.ZBX_STYLE_DASHBOARD_WIDGET_PADDING_H
            };

            this.#honeycomb = new CSVGCustomHoney(padding, response.config);

            const svgElement = this.#honeycomb.getSVGElement();
            svgElement.style.display = 'block';
            svgElement.style.overflow = 'hidden';

            this._body.prepend(svgElement);
            this._body.style.overflow = 'hidden';

            this.#honeycomb.setSize(super._getContentsSize());

            /* =========================================================
               CLIQUE NA CÉLULA
               - se já há ticket: abre o Jira
               - se não há ticket: cria
            ========================================================= */
            this.#honeycomb.getSVGElement().addEventListener(
                CSVGCustomHoney.EVENT_CELL_CLICK,
                e => {
                    this.#selected_hostid = e.detail.hostid;
                    this.#selected_itemid = e.detail.itemid;

                    const cell = this.#cells_data.get(this.#selected_itemid);
                    if (!cell) {
                        return;
                    }

                    // Se já existe ticket, abre o Jira e não cria novo
                    if (cell.jira_url) {
                        window.open(cell.jira_url, '_blank');
                        return;
                    }

                    const label = (cell.base_label ?? cell.primary_label)
                        .replace(/\n/g, ' ')
                        .trim();

                    const value = parseFloat(cell.value);

                    if (value === 0) {
                        alert('Não é possível criar ticket quando o valor é 0.');
                        return;
                    }

                    if (!confirm(`Deseja criar um ticket no Jira para "${label}"?`)) {
                        return;
                    }

                    fetch('zabbix.php?action=widget.honey_custom.jira', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            hostid: this.#selected_hostid,
                            itemid: this.#selected_itemid,
                            label: label,
                            value: value,
                            widget: this.getName()
                        })
                    })
                    .then(async res => {
                        const text = await res.text();

                        try {
                            return JSON.parse(text);
                        } catch (e) {
                            throw new Error(text);
                        }
                    })
                    .then(data => {
                        alert(data.message);

                        if (data.success) {
                            this._startUpdating();
                        }
                    })
                    .catch(err => {
                        alert('Erro real do backend: ' + err.message);
                    });
                }
            );

            this.#honeycomb.getSVGElement().addEventListener(
                CSVGCustomHoney.EVENT_CELL_ENTER,
                () => {
                    clearTimeout(this.#interacting_timeout_id);
                    this.#user_interacting = true;
                }
            );

            this.#honeycomb.getSVGElement().addEventListener(
                CSVGCustomHoney.EVENT_CELL_LEAVE,
                () => {
                    this.#interacting_timeout_id = setTimeout(() => {
                        this.#user_interacting = false;
                    }, 1000);
                }
            );
        }

        /* =========================================================
           ATUALIZA DADOS + 👁️ + LIMPEZA QUANDO VALUE = 0
        ========================================================= */
        this.#items_loaded_count = response.cells.length;

        // Guardar referência original das células
        this.#cells_data.clear();
        response.cells.forEach(cell => {
            cell.base_label = (cell.primary_label ?? '').replace(/\n/g, ' ').trim();
            cell.jira_url = null;
            cell.ticket_user = null;
            cell.ticket_key = null;
            this.#cells_data.set(cell.itemid, cell);
        });

        // Itens que voltaram a zero -> remover do tickets.json
        const zeroItemids = response.cells
            .filter(c => parseFloat(c.value) === 0)
            .map(c => String(c.itemid));

        fetch('zabbix.php?action=widget.honey_custom.tickets', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({})
        })
        .then(async res => {
            const text = await res.text();

            try {
                return JSON.parse(text);
            } catch (e) {
                throw new Error(text);
            }
        })
        .then(tickets => {
            response.cells.forEach(cell => {
                const label = cell.base_label;
                const ticket = tickets[String(cell.itemid)];

                if (ticket && parseFloat(cell.value) > 0) {
                    const jiraKey = ticket.jira;
                    const jiraUrl = `https://glintthsdev.atlassian.net/browse/${jiraKey}`;

                    cell.bg_color = '2196F3';
                    cell.primary_label = `${label}\n👁️ ${ticket.user}`;
                    cell.jira_url = jiraUrl;
                    cell.ticket_user = ticket.user;
                    cell.ticket_key = jiraKey;
                }
                else {
                    cell.primary_label = label;
                    cell.jira_url = null;
                    cell.ticket_user = null;
                    cell.ticket_key = null;
                }
            });

            this.#honeycomb.setValue({ cells: response.cells });
        })
        .catch(err => {
            console.error('Erro ao obter tickets:', err);
            this.#honeycomb.setValue({ cells: response.cells });
        });
    }

    #broadcast() {
        this.broadcast({
            [CWidgetsData.DATA_TYPE_HOST_ID]: [this.#selected_hostid],
            [CWidgetsData.DATA_TYPE_HOST_IDS]: [this.#selected_hostid],
            [CWidgetsData.DATA_TYPE_ITEM_ID]: [this.#selected_itemid],
            [CWidgetsData.DATA_TYPE_ITEM_IDS]: [this.#selected_itemid]
        });
    }

    #getItemsMaxCount() {
        let { width, height } = super._getContentsSize();

        width -= CWidgetCustomHoney.ZBX_STYLE_DASHBOARD_WIDGET_PADDING_H * 2;
        height -= CWidgetCustomHoney.ZBX_STYLE_DASHBOARD_WIDGET_PADDING_V * 2;

        const { max_rows, max_columns } =
            CSVGCustomHoney.getContainerMaxParams({ width, height });

        return Math.min(this.#items_max_count, max_rows * max_columns);
    }

    onClearContents() {
        if (this.#honeycomb !== null) {
            this.#honeycomb.destroy();
            this.#honeycomb = null;
        }
    }

    onDestroy() {
        this.clearContents();
    }
}