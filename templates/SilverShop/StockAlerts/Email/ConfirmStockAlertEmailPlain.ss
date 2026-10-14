$ShopName

<% if $IsPriceDrop %><%t SilverShop\StockAlerts.ConfirmGreetingPriceDrop "Confirm your price-drop alert" %><% else %><%t SilverShop\StockAlerts.ConfirmGreeting "Confirm your back-in-stock alert" %><% end_if %>

<% if $IsPriceDrop %><%t SilverShop\StockAlerts.ConfirmInstructionPriceDrop "Someone (maybe you) asked us to watch the price of {product}. Confirm below and we'll send one email if the price drops — nothing else." product=$BuyableTitle %><% else %><%t SilverShop\StockAlerts.ConfirmInstruction "Someone (maybe you) asked us to watch {product} for you. Confirm below and we'll send one email the moment it's back in stock — nothing else." product=$BuyableTitle %><% end_if %>

<%t SilverShop\StockAlerts.ConfirmButton "Confirm my alert" %>: $ConfirmLink

<%t SilverShop\StockAlerts.ConfirmIgnore "If this wasn't you, just ignore this email — nothing will be sent — or you can" %> $UnsubscribeLink
