class CWidgetCustomHoney extends CWidget {

    static ZBX_STYLE_DASHBOARD_WIDGET_PADDING_V = 8;
    static ZBX_STYLE_DASHBOARD_WIDGET_PADDING_H = 10;

    #honeycomb = null;
    #cells_data = new Map();
    #selected_hostid = null;
    #selected_itemid = null;

    setContents(response) {

        if (this.#honeycomb === null) {
            const padding = {
                vertical: CWidgetCustomHoney.ZBX_STYLE_DASHBOARD_WIDGET_PADDING_V,
                horizontal: CWidgetCustomHoney.ZBX_STYLE_DASHBOARD_WIDGET_PADDING_H
            };

            this.#honeycomb = new CSVGCustomHoney(padding, response.config);
            this._body.prepend(this.#honeycomb.getSVGElement());
            this.#honeycomb.setSize(this._getContentsSize());

            // ===============================
            // CLICK → CRIAR TICKET
            // ===============================
            this.#honeycomb.getSVGElement().addEventListener(
                CSVGCustomHoney.EVENT_CELL_CLICK,
                e => {

                    this.#selected_hostid = e.detail.hostid;
                    this.#selected_itemid = e.detail.itemid;

                    const cell = this.#cells_data.get(this.#selected_itemid);
                    if (!cell) return;

                    const label = cell.primary_label.replace(/\n/g, ' ').trim();
                    const value = parseFloat(cell.value);

                    if (value === 0) {
                        alert('Não é possível criar ticket quando o valor é 0.');
                        return;
                    }

                    if (!confirm(`Deseja criar um ticket para ${label}?`)) {
                        return;
                    }

                    fetch('zabbix.php?action=widget.honey_custom.jira', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            hostid: this.#selected_hostid,
                            label: label,
                            value: value,
                            widget: this.getName()
                        })
                    })
                    .then(r => r.json())
                    .then(d => {
                        if (d.success) {
                            alert(d.message);
                            this._startUpdating();
                        } else {
                            alert('Erro: ' + d.message);
                        }
                    });
                }
            );
        }

        // ===============================
        // APLICAR DADOS + 👁️
        // ===============================
        this.#cells_data.clear();
        response.cells.forEach(c => this.#cells_data.set(c.itemid, c));

        const zeroHosts = response.cells
            .filter(c => parseFloat(c.value) === 0)
            .map(c => c.primary_label.replace(/\n/g, ' ').trim());

        fetch('zabbix.php?action=widget.honey_custom.tickets', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ zero_hosts: zeroHosts })
        })
        .then(r => r.json())
        .then(tickets => {
            response.cells.forEach(c => {
                const label = c.primary_label.replace(/\n/g, ' ').trim();
                if (tickets[label] && parseFloat(c.value) > 0) {
                    c.bg_color = '2196F3';
                    c.primary_label += `\n👁️ ${tickets[label].user}`;
                }
            });
            this.#honeycomb.setValue({ cells: response.cells });
        })
        .catch(() => {
            this.#honeycomb.setValue({ cells: response.cells });
        });
    }
}