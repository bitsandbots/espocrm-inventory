{{#if hasOrders}}
<div style="margin-bottom:10px;">
    <strong>Open Orders</strong>
    <ul style="margin-top:4px;padding-left:16px;font-size:12px;">
    {{#each orders}}
        <li>
            <a href="#InventoryOrder/view/{{id}}">{{name}}</a>
            &mdash; <span class="label label-{{statusStyle status}}">{{status}}</span>
            {{#if dateOrdered}}<span style="color:#888;margin-left:4px;">{{dateOrdered}}</span>{{/if}}
        </li>
    {{/each}}
    </ul>
</div>
{{/if}}

{{#if hasPurchaseOrders}}
<div style="margin-bottom:10px;">
    <strong>Open Purchase Orders</strong>
    <ul style="margin-top:4px;padding-left:16px;font-size:12px;">
    {{#each purchaseOrders}}
        <li>
            <a href="#InventoryPurchaseOrder/view/{{id}}">{{name}}</a>
            &mdash; <span class="label label-{{statusStyle status}}">{{status}}</span>
            {{#if reference}}<span style="color:#888;margin-left:4px;">Ref: {{reference}}</span>{{/if}}
        </li>
    {{/each}}
    </ul>
</div>
{{/if}}

{{#unless hasOrders}}{{#unless hasPurchaseOrders}}
<div style="color:#999;font-size:12px;padding:4px 0;">No open inventory activity.</div>
{{/unless}}{{/unless}}
