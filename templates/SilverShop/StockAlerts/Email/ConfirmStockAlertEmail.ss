<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><%t SilverShop\StockAlerts.ConfirmTitle "Confirm your stock alert" %></title>
</head>
<body style="margin:0;padding:0;background:#f4f4f4;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:6px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;color:#333;">
                    <tr>
                        <td style="background:#222;color:#fff;padding:20px 28px;font-size:18px;font-weight:bold;">$ShopName</td>
                    </tr>
                    <tr>
                        <td style="padding:28px;">
                            <p style="margin:0 0 14px;font-size:16px;"><%t SilverShop\StockAlerts.ConfirmGreeting "Confirm your back-in-stock alert" %></p>
                            <p style="margin:0 0 10px;"><%t SilverShop\StockAlerts.ConfirmInstruction "Please confirm you'd like one email when <strong>{product}</strong> is back in stock." product=$BuyableTitle.XML %></p>
                            <p style="margin:24px 0;">
                                <a href="$ConfirmLink" style="display:inline-block;background:#f5a623;color:#222;text-decoration:none;font-weight:bold;padding:12px 24px;border-radius:4px;"><%t SilverShop\StockAlerts.ConfirmButton "Confirm my alert" %></a>
                            </p>
                            <p style="font-size:12px;color:#888;">
                                <%t SilverShop\StockAlerts.ConfirmFallback "If the button doesn't work, copy this link into your browser:" %><br>
                                <a href="$ConfirmLink" style="color:#888;">$ConfirmLink</a>
                            </p>
                            <p style="font-size:12px;color:#999;margin-top:24px;">
                                <%t SilverShop\StockAlerts.ConfirmIgnore "If you didn't request this, you can safely ignore this email, or" %>
                                <a href="$UnsubscribeLink" style="color:#999;"><%t SilverShop\StockAlerts.Unsubscribe "unsubscribe" %></a>.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
