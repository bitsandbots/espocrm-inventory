import View from 'view';

/**
 * Inventory summary side panel shown on Account detail view.
 * Displays open orders and open purchase orders linked to the account.
 */
export default class InventorySummaryPanel extends View {

    template = 'inventory:panels/inventory-summary'

    data() {
        return {
            orders:           this.orders,
            purchaseOrders:   this.purchaseOrders,
            hasOrders:        (this.orders || []).length > 0,
            hasPurchaseOrders: (this.purchaseOrders || []).length > 0,
        };
    }

    setup() {
        this.orders         = [];
        this.purchaseOrders = [];
        this.fetchData();
    }

    fetchData() {
        const accountId = this.model.id;

        Promise.all([
            this.ajaxGetRequest('InventoryOrder', {
                where: [
                    {type: 'equals', attribute: 'customerId', value: accountId},
                    {type: 'in', attribute: 'status', value: ['pending', 'processing', 'shipped']},
                ],
                maxSize: 10,
                orderBy: 'dateOrdered',
                order: 'desc',
            }),
            this.ajaxGetRequest('InventoryPurchaseOrder', {
                where: [
                    {type: 'equals', attribute: 'supplierId', value: accountId},
                    {type: 'in', attribute: 'status', value: ['draft', 'ordered', 'partial']},
                ],
                maxSize: 10,
                orderBy: 'createdAt',
                order: 'desc',
            }),
        ]).then(([ordersResp, posResp]) => {
            this.orders         = ordersResp.list ?? [];
            this.purchaseOrders = posResp.list ?? [];
            this.reRender();
        }).catch(() => {});
    }
}
