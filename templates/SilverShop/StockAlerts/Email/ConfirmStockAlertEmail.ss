<%-- Double-opt-in confirm email. Self-contained but styled to match the shop's shared flat transactional-email
     look (white card on a light canvas, #222 accents, shop-name brand, horizontal rules — no coloured chrome).
     Kept self-contained rather than including core's OrderEmailHeader/Footer because that shared shell is not yet
     in the core version this module requires (it is on an unmerged core branch); switch to the includes once a
     tagged core ships them. --%>
<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>$ShopName.XML</title>
    <style type="text/css">
        body { margin:0; padding:0; width:100% !important; background:#f2f2f2; color:#222222; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Helvetica,Arial,sans-serif; -webkit-font-smoothing:antialiased; }
        a { color:#222222; }
        table { border-collapse:collapse; }
        .sa-email { width:600px; max-width:100%; margin:0 auto; background:#ffffff; border:1px solid #e5e5e5; }
        .sa-email__cell { padding:24px 28px; text-align:left; }
        .sa-email__brand { display:block; margin:0 0 14px; padding:0 0 14px; font-size:18px; font-weight:700; line-height:1.25; color:#222222; border-bottom:2px solid #222222; }
        .sa-email__title { margin:0 0 14px; font-size:22px; font-weight:700; line-height:1.25; color:#222222; }
        .sa-email__body { color:#444444; font-size:14px; line-height:1.6; }
        .sa-email__btn { display:inline-block; background:#222222; color:#ffffff; text-decoration:none; font-weight:700; padding:12px 22px; font-size:14px; }
        .sa-email__muted { font-size:12px; color:#888888; }
        .sa-email__muted a { color:#888888; }
        .sa-email__footer { padding:16px 28px 22px; color:#888888; font-size:12px; line-height:1.5; border-top:1px solid #ededed; }
        @media only screen and (max-width:620px){ .sa-email{width:100% !important;} .sa-email__cell,.sa-email__footer{padding-left:18px !important;padding-right:18px !important;} }
    </style>
</head>
<body>
    <table class="sa-email" cellpadding="0" cellspacing="0" border="0" role="presentation" align="center">
        <tr>
            <td class="sa-email__cell">
                <span class="sa-email__brand">$ShopName.XML</span>
                <% if $IsPriceDrop %>
                    <h1 class="sa-email__title"><%t SilverShop\StockAlerts.ConfirmGreetingPriceDrop "Confirm your price-drop alert" %></h1>
                <% else %>
                    <h1 class="sa-email__title"><%t SilverShop\StockAlerts.ConfirmGreeting "Confirm your back-in-stock alert" %></h1>
                <% end_if %>
                <div class="sa-email__body">
                    <% if $IsPriceDrop %>
                        <p><%t SilverShop\StockAlerts.ConfirmInstructionPriceDrop "Someone (maybe you) asked us to watch the price of <strong>{product}</strong>. Confirm below and we'll send one email if the price drops — nothing else." product=$BuyableTitle.XML %></p>
                    <% else %>
                        <p><%t SilverShop\StockAlerts.ConfirmInstruction "Someone (maybe you) asked us to watch <strong>{product}</strong> for you. Confirm below and we'll send one email the moment it's back in stock — nothing else." product=$BuyableTitle.XML %></p>
                    <% end_if %>
                    <p style="margin:22px 0;"><a class="sa-email__btn" href="$ConfirmLink"><%t SilverShop\StockAlerts.ConfirmButton "Confirm my alert" %></a></p>
                    <p class="sa-email__muted">
                        <%t SilverShop\StockAlerts.ConfirmFallback "If the button doesn't work, copy this link into your browser:" %><br>
                        <a href="$ConfirmLink">$ConfirmLink</a>
                    </p>
                    <p class="sa-email__muted">
                        <%t SilverShop\StockAlerts.ConfirmIgnore "If this wasn't you, just ignore this email — nothing will be sent — or you can" %>
                        <a href="$UnsubscribeLink"><%t SilverShop\StockAlerts.Unsubscribe "unsubscribe" %></a>.
                    </p>
                </div>
            </td>
        </tr>
        <tr>
            <td class="sa-email__footer">$ShopName.XML</td>
        </tr>
    </table>
</body>
</html>
