<%-- Account-area "My alerts" section (the /account/alerts action, silvershop/stock-alerts). Mirrors the other
     AccountPage_<action> templates: account navigation + the section content. Override in your theme to restyle
     (the storefront theme ships a styled version). --%>
<% include SilverShop\Includes\AccountNavigation %>
<div class="silvershop-account silvershop-account--alerts silvershop-typography">
    <h1><%t SilverShop\StockAlerts.AccountTitle "Stock &amp; price alerts" %></h1>
    <% if $MemberStockAlerts %>
        <ul class="silvershop-stock-alerts__list">
            <% loop $MemberStockAlerts %>
                <li class="silvershop-stock-alert-row">
                    <% if $BuyableLink %><a href="$BuyableLink">$BuyableSummary.XML</a><% else %>$BuyableSummary.XML<% end_if %>
                    &mdash; $TypeNice (<span>$StatusNice</span><% if $isPriceDrop && $SubscribedPrice %>, <%t SilverShop\StockAlerts.AccountDropBelow "below {price}" price=$SubscribedPrice.Nice %><% end_if %>)
                    <span class="silvershop-stock-alert-row__email">$DisplayEmail</span>
                    <a href="$UnsubscribeLink"><%t SilverShop\StockAlerts.AccountRemove "Remove" %></a>
                </li>
            <% end_loop %>
        </ul>
    <% else %>
        <p><%t SilverShop\StockAlerts.AccountEmpty "You have no active stock or price alerts." %></p>
    <% end_if %>
</div>
