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
    #selected_key_ = null;

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

        if (this.#honeycomb === null) {

            const padding = {
                vertical: CWidgetCustomHoney.ZBX_STYLE_DASHBOARD_WIDGET_PADDING_V,
                horizontal: CWidgetCustomHoney.ZBX_STYLE_DASHBOARD_WIDGET_PADDING_H,
            };

            this.#honeycomb = new CSVGCustomHoney(padding, response.config);

            const svgElement = this.#honeycomb.getSVGElement();
            svgElement.style.display = 'block';
            svgElement.style.overflow = 'hidden';

            this._body.prepend(svgElement);
            this._body.style.overflow = 'hidden';

            this.#honeycomb.setSize(super._getContentsSize());

            // =========================================================
            // CLIQUE → CRIAR TICKET JIRA
            // =========================================================
            this.#honeycomb.getSVGElement().addEventListener(
                CSVGCustomHoney.EVENT_CELL_CLICK,
                e => {

                    this.#selected_hostid = e.detail.hostid;
                    this.#selected_itemid = e.detail.itemid;

                    const cellData = this.#cells_data.get(this.#selected_itemid);
                    if (!cellData) {
                        return;
                    }

                    const itemValue = cellData.value;
                    const widgetName = this.getName();

                    // UX: bloqueio imediato no frontend
                    if (parseFloat(itemValue) === 0) {
                        alert('Não é possível criar ticket quando o valor é 0.');
                        return;
                    }

                    const confirmar = confirm('Deseja criar um ticket no Jira?');
                    if (!confirmar) {
                        return;
                    }

                    fetch('zabbix.php?action=widget.honey_custom.jira', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            hostid: this.#selected_hostid,
                            value: itemValue,
                            widget: widgetName
                        })
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            alert(data.message);
                            this._startUpdating();
                        }
                        else {
                            alert('Erro: ' + data.message);
                        }
                    })
                    .catch(() => {
                        alert('Erro na comunicação com o backend.');
                    });

                    this.#broadcast();
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

        // =========================================================
        // DESENHO DAS CÉLULAS
        // =========================================================
        const applyDataAndDraw = () => {

            this.#items_loaded_count = response.cells.length;
            this.#honeycomb.setValue({ cells: response.cells });

            if (this.#items_loaded_count === 0) return;

            this.#cells_data.clear();
            response.cells.forEach(cell => {
                this.#cells_data.set(cell.itemid, cell);
            });

            if (this.#selected_itemid !== null) {
                this.#honeycomb.selectCell(this.#selected_itemid);
            }
        };

        applyDataAndDraw();
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
