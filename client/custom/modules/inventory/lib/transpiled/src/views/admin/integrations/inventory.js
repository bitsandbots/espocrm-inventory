define("modules/inventory/views/admin/integrations/inventory", ["exports", "views/admin/integrations/edit"], function (_exports, _edit) {
  "use strict";

  Object.defineProperty(_exports, "__esModule", { value: true });
  _exports.default = void 0;
  _edit = _interopRequireDefault(_edit);
  function _interopRequireDefault(e) { return e && e.__esModule ? e : { default: e }; }

  class InventoryIntegrationView extends _edit.default {
    events = {
      'click [data-action="testConnection"]': function () { this.actionTestConnection(); },
      'click [data-action="runSync"]':        function () { this.actionRunSync(); }
    };

    afterRender() {
      super.afterRender();
      this.renderStatusSection();
    }

    renderStatusSection() {
      const lastSyncAt    = this.model.get('lastSyncAt') ?? '';
      const lastSyncCount = this.model.get('lastSyncCount') ?? '';
      const lastSyncError = this.model.get('lastSyncError') ?? '';

      const syncStatusHtml = lastSyncAt
        ? `<div style="margin-top:8px;font-size:12px;color:#555">Last sync: <strong>${lastSyncAt}</strong> &nbsp;&mdash;&nbsp;${lastSyncCount} records</div>`
        : '<div style="margin-top:8px;font-size:12px;color:#999">Never synced.</div>';

      const errorHtml = lastSyncError
        ? `<div style="margin-top:8px;padding:8px 10px;background:#fff3f3;border:1px solid #f5c6c6;border-radius:4px;font-size:12px;color:#a33;word-break:break-word;"><strong>Last sync error:</strong> ${lastSyncError}</div>`
        : '';

      const html = `
        <div class="inventory-status-wrap" style="margin-top:20px;padding:16px 18px;background:#f7f9fc;border:1px solid #dce1ea;border-radius:6px;">
          <div style="font-size:13px;font-weight:600;margin-bottom:8px;">CC Inventory Sync Status</div>
          ${syncStatusHtml}
          ${errorHtml}
          <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;">
            <button class="btn btn-default btn-sm" data-action="testConnection">Test Connection</button>
            <button class="btn btn-primary btn-sm" data-action="runSync">Sync Now</button>
          </div>
          <p style="margin-top:10px;margin-bottom:0;font-size:12px;color:#888">
            Save your database credentials first. Sync Now pulls all data from cc-inventory and upserts it into EspoCRM.
          </p>
        </div>`;

      this.$el.find('.panel-body').last().append(html);
    }

    actionTestConnection() {
      const $btn = this.$el.find('[data-action="testConnection"]');
      $btn.prop('disabled', true).text('Testing…');
      Espo.Ajax.postRequest('Inventory/testConnection', {})
        .then(() => {
          Espo.Ui.success('Connection successful.');
          $btn.prop('disabled', false).text('Test Connection');
        })
        .catch(() => {
          Espo.Ui.error('Connection failed. Check your credentials and server logs.');
          $btn.prop('disabled', false).text('Test Connection');
        });
    }

    actionRunSync() {
      const $btn = this.$el.find('[data-action="runSync"]');
      $btn.prop('disabled', true).text('Syncing…');
      Espo.Ajax.postRequest('Inventory/runSync', {})
        .then(data => {
          Espo.Ui.success(`Sync completed — ${data.count} records processed.`);
          this.model.fetch().then(() => {
            this.$el.find('.inventory-status-wrap').remove();
            this.renderStatusSection();
          });
        })
        .catch(() => {
          Espo.Ui.error('Sync failed. Check Admin → Log for details.');
          $btn.prop('disabled', false).text('Sync Now');
        });
    }
  }

  _exports.default = InventoryIntegrationView;
});
