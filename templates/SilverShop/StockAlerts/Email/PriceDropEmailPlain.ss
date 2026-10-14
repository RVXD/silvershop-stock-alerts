$ShopName

<%t SilverShop\StockAlerts.DropHeading "Good news — the price dropped!" %>

<%t SilverShop\StockAlerts.DropBody "The price of {product} has gone down since you started watching it." product=$BuyableTitle %>
<% if $CurrentPrice %><% if $SubscribedPrice %>$SubscribedPrice.Nice &rarr; <% end_if %>$CurrentPrice.Nice<% end_if %>

<%t SilverShop\StockAlerts.DropButton "View the product" %>: $BuyableLink

<%t SilverShop\StockAlerts.DropUnsub "You received this because you asked to be notified about a price drop. No further emails will be sent for this alert." %> $UnsubscribeLink
